<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Models\DataImporter;
use PDOException;
use RuntimeException;

use function App\Models\pdo_error;

/** Data import: receives batches of rows already read from the CSV/JSON by the browser. */
final class ImportController
{
    private const MAX_ROWS = 5000;
    private const MAX_CELLS = 50000;
    private const MAX_COLUMNS = 1024;

    public function __construct(
        private readonly Session $session,
        private readonly DataImporter $importer,
        private readonly Config $config,
    ) {
    }

    /**
     * Inserts one batch. Fields: database, schema, table, columns (destination), rows (lists of text or null)
     * and, on the first batch of a new table, create (a list of {name, type}).
     */
    public function import(Request $request): Response
    {
        $connection = $this->session->connection();
        $this->session->release();
        set_time_limit($this->config->int('query_timeout', 120) + 30);

        $in = $request->json();

        try {
            [$columns, $rows] = $this->validated($in);
            $create = isset($in['create']) && is_array($in['create']) ? array_values($in['create']) : null;
            if ($create !== null) {
                $create = array_map(
                    static fn (mixed $c): array => ['name' => (string) ($c['name'] ?? ''), 'type' => (string) ($c['type'] ?? '')],
                    $create,
                );
            }

            $inserted = $this->importer->import(
                $connection,
                (string) ($in['database'] ?? ''),
                (string) ($in['schema'] ?? ''),
                (string) ($in['table'] ?? ''),
                $columns,
                $rows,
                $create,
            );

            return Response::json(['ok' => true, 'inserted' => $inserted]);
        } catch (PDOException $e) {
            return Response::json(['ok' => false, 'error' => pdo_error($e)]);
        } catch (RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => ['message' => $e->getMessage()]]);
        }
    }

    /**
     * @return array{0:list<string>,1:list<list<?string>>}
     * @throws RuntimeException
     */
    private function validated(array $in): array
    {
        $columns = $in['columns'] ?? null;
        $rows = $in['rows'] ?? null;
        $schema = (string) ($in['schema'] ?? '');
        $table = (string) ($in['table'] ?? '');

        if ($schema === '' || $table === '' || strlen($schema) > 128 || strlen($table) > 128) {
            throw new RuntimeException('Enter a schema and a table name.');
        }
        if (!is_array($columns) || $columns === [] || count($columns) > self::MAX_COLUMNS) {
            throw new RuntimeException('Map at least one column.');
        }
        $columns = array_map(static fn (mixed $c): string => is_string($c) ? $c : '', array_values($columns));
        if (in_array('', $columns, true) || count(array_unique(array_map('strtolower', $columns))) !== count($columns)) {
            throw new RuntimeException('Column names must be non-empty and unique.');
        }
        if (!is_array($rows) || $rows === []) {
            throw new RuntimeException('There are no rows to import.');
        }
        if (count($rows) > self::MAX_ROWS || count($rows) * count($columns) > self::MAX_CELLS) {
            throw new RuntimeException('Too many rows in a single request.');
        }

        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) !== count($columns)) {
                throw new RuntimeException('Every row must have one value per mapped column.');
            }
            $clean[] = array_map(
                static fn (mixed $v): ?string => $v === null ? null : (is_scalar($v) ? (string) $v : throw new RuntimeException('Cell values must be text or null.')),
                array_values($row),
            );
        }

        return [$columns, $clean];
    }
}
