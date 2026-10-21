<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use Generator;
use PDO;
use PDOException;
use PDOStatement;

use function App\Support\take;

/**
 * Runs T-SQL scripts. The part with side effects (PDO) lives here;
 * the data transformations are pure functions (see functions.php).
 */
final class QueryExecutor
{
    public function __construct(
        private readonly ConnectionFactory $factory,
        private readonly Config $config,
    ) {
    }

    public function execute(Connection $connection, string $database, string $sql, int $limit): QueryResult
    {
        $sets = [];
        $messages = [];
        $error = null;
        $start = hrtime(true);

        try {
            $pdo = $this->factory->open($connection, $database);
            $pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, $this->config->int('query_timeout', 120));

            foreach (split_batches($sql) as $batch) {
                foreach ($this->runBatch($pdo, $batch, $limit) as $part) {
                    if (isset($part['set'])) {
                        $sets[] = $part['set'];
                    } else {
                        $messages[] = $part['message'];
                    }
                }
            }
        } catch (PDOException $e) {
            // Keeps what was read before the error, like SSMS.
            $error = pdo_error($e);
        }

        return new QueryResult($sets, $messages, $error, (int) round((hrtime(true) - $start) / 1e6));
    }

    /**
     * Runs one page (OFFSET/FETCH) of a single SELECT. Returns null when the query cannot be
     * paginated (script with several batches, SELECT INTO, TOP, UNION without ORDER BY...) or the
     * server rejects the paginated version; the caller then uses execute().
     *
     * @param int $cap maximum rows overall (AUTO TOP); 0 = no cap
     */
    public function executePage(Connection $connection, string $database, string $sql, int $page, int $size, int $cap): ?QueryResult
    {
        $batches = split_batches($sql);
        $offset = ($page - 1) * $size;
        $take = $cap > 0 ? min($size, $cap - $offset) : $size;
        $paged = count($batches) === 1 && $take > 0 ? paginate_sql($batches[0], $offset, $take + 1) : null;
        if ($paged === null) {
            return null;
        }

        $start = hrtime(true);
        try {
            $pdo = $this->factory->open($connection, $database);
            $pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, $this->config->int('query_timeout', 120));
            $parts = iterator_to_array($this->runBatch($pdo, $paged, $take), false);
        } catch (PDOException) {
            return null;
        }

        $sets = array_values(array_column(array_filter($parts, static fn (array $p): bool => isset($p['set'])), 'set'));
        if (count($sets) !== 1) {
            return null;
        }

        $hasMore = $sets[0]['truncated'] && ($cap === 0 || $offset + $take < $cap);
        $sets[0]['truncated'] = false;
        $messages = array_column(array_filter($parts, static fn (array $p): bool => isset($p['message'])), 'message');

        return new QueryResult(
            $sets,
            $messages,
            null,
            (int) round((hrtime(true) - $start) / 1e6),
            ['page' => $page, 'size' => $size, 'hasMore' => $hasMore],
        );
    }

    /**
     * Opens a single SELECT for streaming reads (export). Returns [PDO, statement, columns];
     * keep the PDO alive while reading the rows.
     *
     * @return array{0:PDO,1:PDOStatement,2:list<array{name:string,type:string}>}
     * @throws PDOException|\RuntimeException
     */
    public function openStream(Connection $connection, string $database, string $sql): array
    {
        $batches = split_batches($sql);
        if (count($batches) !== 1) {
            throw new \RuntimeException('The full export accepts a single SELECT statement only.');
        }

        $pdo = $this->factory->open($connection, $database);
        $pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, $this->config->int('query_timeout', 120));
        $stmt = $pdo->query($batches[0]);
        while ($stmt->columnCount() === 0) {
            if (!$stmt->nextRowset()) {
                throw new \RuntimeException('The query did not return a result set.');
            }
        }

        $columns = array_map(
            static fn (int $i): array => column_descriptor($stmt->getColumnMeta($i)),
            range(0, $stmt->columnCount() - 1),
        );

        return [$pdo, $stmt, $columns];
    }

    /**
     * Rows of the statement, one by one, with JSON-safe cells.
     *
     * @param int $max 0 = all
     */
    public function streamRows(PDOStatement $stmt, int $max = 0): Generator
    {
        $rows = $this->fetchRows($stmt);
        foreach ($max > 0 ? take($rows, $max) : $rows as $row) {
            yield array_map(safe_cell(...), $row);
        }
    }

    /** Yields, in order, each result set or affected-rows message of the batch. */
    private function runBatch(PDO $pdo, string $batch, int $limit): Generator
    {
        $stmt = $pdo->query($batch);

        do {
            // PRINT and informational messages (severity <= 10) arrive in errorInfo, not as an exception.
            yield from $this->infoMessages($stmt);

            if ($stmt->columnCount() > 0) {
                yield ['set' => $this->readSet($stmt, $limit)];
            } elseif (($affected = $stmt->rowCount()) >= 0) {
                yield ['message' => "($affected row(s) affected)"];
            }
        } while ($stmt->nextRowset());

        yield from $this->infoMessages($stmt);
    }

    /** Pending informational message from the server (PRINT, warnings), if any. */
    private function infoMessages(PDOStatement $stmt): Generator
    {
        [$state, , $text] = $stmt->errorInfo() + [null, null, null];

        if ($state !== null && $state !== '00000' && $text !== null && $text !== '') {
            yield ['message' => clean_error_message((string) $text)];
        }
    }

    private function readSet(PDOStatement $stmt, int $limit): array
    {
        $columns = array_map(
            static fn (int $i): array => column_descriptor($stmt->getColumnMeta($i)),
            range(0, $stmt->columnCount() - 1),
        );

        // Reads limit+1 rows: the extra one signals truncation without pulling the whole result.
        $fetched = iterator_to_array(take($this->fetchRows($stmt), $limit + 1), false);

        return [
            'columns'   => $columns,
            'rows'      => array_map(
                static fn (array $row): array => array_map(safe_cell(...), $row),
                array_slice($fetched, 0, $limit),
            ),
            'truncated' => count($fetched) > $limit,
        ];
    }

    private function fetchRows(PDOStatement $stmt): Generator
    {
        while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            yield $row;
        }
    }
}
