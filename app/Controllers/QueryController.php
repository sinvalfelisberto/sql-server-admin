<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Models\QueryExecutor;
use PDOException;
use RuntimeException;

use function App\Models\csv_line;
use function App\Models\csv_separator;
use function App\Models\effective_limit;
use function App\Models\format_decimal;
use function App\Models\is_decimal_type;
use function App\Models\pdo_error;
use function App\Models\safe_filename;
use function App\Models\unique_keys;

final class QueryController
{
    public function __construct(
        private readonly Session $session,
        private readonly QueryExecutor $executor,
        private readonly Config $config,
    ) {
    }

    public function execute(Request $request): Response
    {
        $connection = $this->session->connection();
        $this->session->release();

        $in = $request->json();
        $sql = (string) ($in['sql'] ?? '');
        $database = (string) ($in['database'] ?? '');
        $requested = (int) ($in['limit'] ?? 0);
        $maxRows = $this->config->int('max_rows', 10000);
        set_time_limit($this->config->int('query_timeout', 120) + 30);

        // With "page", a single SELECT is paginated on the server: each call returns only one page.
        // "limit" becomes the total cap (AUTO TOP) and the hard max_rows cap applies per page.
        $result = isset($in['page'])
            ? $this->executor->executePage(
                $connection,
                $database,
                $sql,
                max(1, (int) $in['page']),
                min($maxRows, max(1, (int) ($in['page_size'] ?? 200))),
                max(0, $requested),
            )
            : null;

        $result ??= $this->executor->execute($connection, $database, $sql, effective_limit($requested, $maxRows));

        return Response::json($result->toArray());
    }

    /**
     * Exports the COMPLETE result of a single SELECT, re-running the query and streaming the output.
     * format: csv | json (download) | raw (JSON {columns, types, rows} for the browser to build the .xlsx).
     * Accepts a form (native download) or JSON.
     */
    public function export(Request $request): Response
    {
        $connection = $this->session->connection();
        $this->session->release();

        $in = $request->post !== [] ? $request->post : $request->json();
        $format = in_array($in['format'] ?? '', ['csv', 'json', 'raw'], true) ? (string) $in['format'] : 'csv';
        $limit = max(0, (int) ($in['limit'] ?? 0));
        $configured = $this->config->int('export_max_rows', 0);
        $max = $configured > 0 && ($limit === 0 || $limit > $configured) ? $configured : $limit;

        try {
            [$pdo, $stmt, $columns] = $this->executor->openStream(
                $connection,
                (string) ($in['database'] ?? ''),
                (string) ($in['sql'] ?? ''),
            );
        } catch (PDOException $e) {
            return Response::json(['ok' => false, 'error' => pdo_error($e)]);
        } catch (RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => ['message' => $e->getMessage()]]);
        }

        $separator = csv_separator(
            isset($in['separator']) ? (string) $in['separator'] : null,
            csv_separator((string) $this->config->get('csv_separator', ';')),
        );
        $names = array_column($columns, 'name');
        $types = array_column($columns, 'type');
        $keys = unique_keys($names);
        $json = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
        $decimal = array_map(is_decimal_type(...), $types);
        $rows = (static function (iterable $source) use ($decimal): \Generator {
            foreach ($source as $row) {
                yield array_map(
                    static fn (mixed $v, bool $isDecimal): mixed => $isDecimal && is_string($v) ? format_decimal($v) : $v,
                    $row,
                    $decimal,
                );
            }
        })($this->executor->streamRows($stmt, $max));

        $body = static function () use ($pdo, $format, $separator, $names, $types, $keys, $rows, $json): void {
            set_time_limit(0);
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $emit = static function (string $chunk): bool {
                echo $chunk;
                if (connection_aborted()) {
                    return false;
                }

                return true;
            };

            $n = 0;
            if ($format === 'csv') {
                $emit("\xEF\xBB\xBF" . csv_line($names, $separator));
            } elseif ($format === 'json') {
                $emit('[');
            } else {
                $emit('{"columns":' . json_encode($names, $json) . ',"types":' . json_encode($types, $json) . ',"rows":[');
            }

            foreach ($rows as $row) {
                $chunk = match ($format) {
                    'csv'   => "\r\n" . csv_line($row, $separator),
                    'json'  => ($n ? ',' : '') . "\n  " . json_encode(array_combine($keys, $row), $json),
                    default => ($n ? ',' : '') . json_encode($row, $json),
                };
                if (!$emit($chunk)) {
                    return;
                }
                if (++$n % 500 === 0) {
                    flush();
                }
            }

            $emit(match ($format) {
                'csv'   => '',
                'json'  => $n ? "\n]" : ']',
                default => ']}',
            });
            unset($pdo);
        };

        $mime = $format === 'csv' ? 'text/csv; charset=utf-8' : 'application/json; charset=utf-8';
        $headers = ['Content-Type' => $mime, 'Cache-Control' => 'no-store', 'X-Accel-Buffering' => 'no'];
        if ($format !== 'raw') {
            $file = safe_filename((string) ($in['filename'] ?? ''), 'result') . ($format === 'csv' ? '.csv' : '.json');
            $file = preg_replace('/\.(csv|json)\.(csv|json)$/', '.$2', $file);
            $headers['Content-Disposition'] = 'attachment; filename="' . $file . '"';
        }

        return new Response(200, $body, $headers);
    }
}
