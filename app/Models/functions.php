<?php
declare(strict_types=1);

/**
 * Pure domain rules: no I/O, no global state, no side effects.
 * Same input, same output — which is why they can be tested without a database.
 */

namespace App\Models;

use App\Support\Result;
use PDOException;

/** Delimits a T-SQL identifier: "a]b" -> "[a]]b]". */
function quote_identifier(string $identifier): string
{
    return '[' . str_replace(']', ']]', $identifier) . ']';
}

/** Validates and normalizes "host", "host\instance", "host,port" or "host:port". */
function normalize_server(string $server): Result
{
    $server = trim($server);
    $valid = preg_match('/^(tcp:)?[A-Za-z0-9_.\-]+(\\\\[A-Za-z0-9_$#.\-]+)?([,:]\d{1,5})?$/', $server) === 1;

    return $valid
        ? Result::ok((string) preg_replace('/:(\d{1,5})$/', ',$1', $server))
        : Result::err('Invalid server name.');
}

/** Fails if there is an allowlist and the server is not in it. */
function ensure_server_allowed(array $allowed, string $server): Result
{
    if ($allowed === []) {
        return Result::ok($server);
    }

    $normalized = array_map(
        static fn (string $s): string => normalize_server($s)->fold(static fn (): string => '', static fn (string $v): string => strtolower($v)),
        $allowed,
    );

    return in_array(strtolower($server), $normalized, true)
        ? Result::ok($server)
        : Result::err('Server not allowed.');
}

/** pdo_sqlsrv DSN. The database is NOT part of the DSN (it is chosen with USE) to avoid option injection. */
function build_dsn(string $server, bool $trustCertificate, int $loginTimeout, ?bool $encrypt): string
{
    return implode(';', array_filter([
        'sqlsrv:Server=' . $server,
        'LoginTimeout=' . $loginTimeout,
        'APP=SQL Web Manager',
        $trustCertificate ? 'TrustServerCertificate=1' : null,
        $encrypt === null ? null : 'Encrypt=' . (int) $encrypt,
    ]));
}

/** Splits the script into batches on the GO separator (on its own line), as in SSMS. */
function split_batches(string $sql): array
{
    $parts = preg_split('/^[ \t]*GO[ \t]*(?:\d+[ \t]*)?(?:--.*)?$/mi', $sql) ?: [];

    return array_values(array_filter(
        array_map('trim', $parts),
        static fn (string $batch): bool => $batch !== '',
    ));
}

/** Effective row limit: the requested one (if > 0) capped by the configured ceiling. */
function effective_limit(int $requested, int $hardMax): int
{
    return $requested > 0 ? min($requested, $hardMax) : $hardMax;
}

/** Readable type label, e.g. nvarchar(100), decimal(18,2). */
function type_label(string $type, int $maxLength, int $precision, int $scale): string
{
    $t = strtolower($type);

    return match (true) {
        in_array($t, ['varchar', 'char', 'varbinary', 'binary'], true) => $t . '(' . ($maxLength === -1 ? 'max' : $maxLength) . ')',
        in_array($t, ['nvarchar', 'nchar'], true) => $t . '(' . ($maxLength === -1 ? 'max' : intdiv($maxLength, 2)) . ')',
        in_array($t, ['decimal', 'numeric'], true) => "$t($precision,$scale)",
        in_array($t, ['datetime2', 'datetimeoffset', 'time'], true) => "$t($scale)",
        default => $t,
    };
}

/** Raw row from the columns query -> API format. */
function column_view(array $row): array
{
    return [
        'name'     => $row['name'],
        'type'     => type_label($row['type'], (int) $row['max_length'], (int) $row['precision'], (int) $row['scale']),
        'nullable' => (bool) $row['is_nullable'],
        'pk'       => (bool) $row['is_pk'],
        'identity' => (bool) ($row['is_identity'] ?? false),
        'computed' => (bool) ($row['is_computed'] ?? false),
    ];
}

/** Raw row from sys.databases -> API format (databases 1..4 are system databases). */
function database_view(array $row): array
{
    return ['name' => $row['name'], 'system' => (bool) $row['is_system']];
}

/** Cell value that is safe for JSON: binary becomes 0xHEX. */
function safe_cell(mixed $value): mixed
{
    return is_string($value) && !mb_check_encoding($value, 'UTF-8')
        ? '0x' . strtoupper(bin2hex($value))
        : $value;
}

/** Driver metadata -> API column descriptor. */
function column_descriptor(array $meta): array
{
    return ['name' => $meta['name'], 'type' => $meta['sqlsrv:decl_type'] ?? ''];
}

/** Strips the "[Microsoft][ODBC Driver 18 for SQL Server][SQL Server]" prefixes from the message. */
function clean_error_message(string $message): string
{
    return trim((string) preg_replace('/^(\[[^\]]*\])+\s*/', '', $message));
}

/** PDOException -> ['message', 'number', 'state']. */
function pdo_error(PDOException $e): array
{
    $info = $e->errorInfo ?? [];

    return [
        'message' => clean_error_message((string) ($info[2] ?? $e->getMessage())),
        'number'  => $info[1] ?? null,
        'state'   => $info[0] ?? null,
    ];
}

/**
 * Returns $sql with everything that is not top-level code replaced by spaces (same length):
 * comments, quoted strings, [..] and ".." identifiers, and the contents of parentheses.
 * Used to look for keywords of the main statement without falling into subqueries or strings.
 */
function mask_sql(string $sql): string
{
    $n = strlen($sql);
    $out = $sql;
    $depth = 0;
    $blank = static function (int $from, int $to) use (&$out): void {
        for ($k = $from; $k < $to; $k++) {
            if ($out[$k] !== "\n") {
                $out[$k] = ' ';
            }
        }
    };

    for ($i = 0; $i < $n;) {
        $c = $sql[$i];
        $two = substr($sql, $i, 2);

        if ($two === '--') {
            $end = strpos($sql, "\n", $i);
            $end = $end === false ? $n : $end;
            $blank($i, $end);
            $i = $end;
        } elseif ($two === '/*') {
            $level = 1;
            $j = $i + 2;
            while ($j < $n && $level > 0) {
                $pair = substr($sql, $j, 2);
                if ($pair === '/*') {
                    $level++;
                    $j += 2;
                } elseif ($pair === '*/') {
                    $level--;
                    $j += 2;
                } else {
                    $j++;
                }
            }
            $blank($i, $j);
            $i = $j;
        } elseif ($c === "'" || $c === '"' || $c === '[') {
            $close = $c === '[' ? ']' : $c;
            $j = $i + 1;
            while ($j < $n) {
                if ($sql[$j] === $close) {
                    if (($sql[$j + 1] ?? '') === $close) {
                        $j += 2;
                        continue;
                    }
                    break;
                }
                $j++;
            }
            $blank($i, min($j + 1, $n));
            $i = $j + 1;
        } elseif ($c === '(') {
            $depth++;
            $out[$i] = ' ';
            $i++;
        } elseif ($c === ')') {
            $depth = max(0, $depth - 1);
            $out[$i] = ' ';
            $i++;
        } else {
            if ($depth > 0 && $c !== "\n") {
                $out[$i] = ' ';
            }
            $i++;
        }
    }

    return $out;
}

/**
 * Rewrites a single SELECT to return only one page (OFFSET/FETCH).
 * Returns null when the query is not safe to paginate (several statements, SELECT INTO,
 * TOP, FOR XML/JSON, OPTION, UNION/DISTINCT without ORDER BY...); the caller then runs it normally.
 * Without ORDER BY it uses (SELECT NULL): the order between pages then depends on the server.
 */
function paginate_sql(string $sql, int $offset, int $rows): ?string
{
    $masked = mask_sql($sql);
    $end = strlen(rtrim($masked, " \t\r\n;"));
    $core = substr($masked, 0, $end);

    $notPageable = $core === ''
        || str_contains($core, ';')
        || preg_match('/^\s*(SELECT|WITH)\b/i', $core) !== 1
        || preg_match('/\b(INTO|OPTION|TOP|OFFSET|INSERT|UPDATE|DELETE|MERGE|EXEC|EXECUTE|DECLARE)\b/i', $core) === 1
        || preg_match('/\bFOR\s+(XML|JSON|BROWSE)\b/i', $core) === 1
        || preg_match('/\bSELECT\s+@\w+\s*[-+*\/%&|^]?=/i', $core) === 1;
    if ($notPageable) {
        return null;
    }

    $hasOrder = preg_match('/\bORDER\s+BY\b/i', $core) === 1;
    if (!$hasOrder && preg_match('/\b(UNION|INTERSECT|EXCEPT|DISTINCT)\b/i', $core) === 1) {
        return null;
    }

    return rtrim(substr($sql, 0, $end)) . ($hasOrder ? '' : ' ORDER BY (SELECT NULL)')
        . " OFFSET $offset ROWS FETCH NEXT $rows ROWS ONLY";
}

/**
 * Allowed CSV separator: ";", ",", "|" or tab (the word "tab" is also accepted).
 * Any other value (or null) falls back to $default.
 */
function csv_separator(?string $value, string $default = ';'): string
{
    $value = strtolower($value ?? '');
    $value = $value === 'tab' ? "\t" : $value;

    return in_array($value, [';', ',', '|', "\t"], true) ? $value : $default;
}

/** CSV cell: quoted only when the text contains quotes, a line break or the separator. */
function csv_cell(mixed $value, string $separator = ';'): string
{
    if ($value === null) {
        return '';
    }
    $text = is_bool($value) ? (string) (int) $value : (string) $value;
    $special = '/["\r\n' . preg_quote($separator, '/') . ']/';

    return preg_match($special, $text) === 1 ? '"' . str_replace('"', '""', $text) . '"' : $text;
}

/** @param list<mixed> $cells */
function csv_line(array $cells, string $separator = ';'): string
{
    return implode($separator, array_map(static fn (mixed $cell): string => csv_cell($cell, $separator), $cells));
}

/** Unique column names for JSON: repeated ones get a _2, _3... suffix. */
function unique_keys(array $names): array
{
    $seen = [];

    return array_map(static function (string $name) use (&$seen): string {
        $seen[$name] = ($seen[$name] ?? 0) + 1;

        return $seen[$name] > 1 ? "{$name}_{$seen[$name]}" : $name;
    }, $names);
}

/** File name that is safe for the Content-Disposition header. */
function safe_filename(string $name, string $fallback = 'resultado'): string
{
    $clean = trim((string) preg_replace('/[^A-Za-z0-9._\-]+/', '_', $name), '._');

    return $clean === '' ? $fallback : $clean;
}

/** Does the SQL type (the driver's base name) hold exact decimals? float/real and integers are excluded. */
function is_decimal_type(string $type): bool
{
    return in_array(strtolower($type), ['decimal', 'numeric', 'money', 'smallmoney'], true);
}

/**
 * Decimal in the SQL standard for export: a dot as the separator and at least two decimal places.
 * "30000.0000" -> "30000.00"; ".0000" -> "0.00"; "-.5000" -> "-0.50". Trailing zeros beyond the
 * second decimal place are dropped; significant digits are never cut ("12.3456" stays "12.3456").
 * Values with no fractional part (e.g., numeric(10,0)) and text that is not a decimal pass through untouched.
 */
function format_decimal(string $value): string
{
    if (preg_match('/^(-?)(\d*)\.(\d+)$/', trim($value), $m) !== 1) {
        return $value;
    }
    [, $sign, $int, $frac] = $m;
    $frac = str_pad(rtrim($frac, '0'), 2, '0');
    $int = ltrim($int, '0') === '' ? '0' : ltrim($int, '0');
    $negativeZero = $int === '0' && trim($frac, '0') === '';

    return ($negativeZero ? '' : $sign) . "$int.$frac";
}

/**
 * Column type accepted when the import creates a table (closed list, no free text).
 * Returns the normalized type or null if it is not allowed.
 */
function import_column_type(string $spec): ?string
{
    $s = strtolower(trim($spec));
    if (in_array($s, ['int', 'bigint', 'smallint', 'tinyint', 'bit', 'float', 'real', 'date', 'datetime2', 'datetime', 'uniqueidentifier'], true)) {
        return $s;
    }
    if (preg_match('/^(n?varchar)\((\d{1,4}|max)\)$/', $s, $m) === 1) {
        $limit = $m[1] === 'nvarchar' ? 4000 : 8000;

        return $m[2] === 'max' || ((int) $m[2] >= 1 && (int) $m[2] <= $limit) ? "{$m[1]}({$m[2]})" : null;
    }
    if (preg_match('/^decimal\((\d{1,2})\s*,\s*(\d{1,2})\)$/', $s, $m) === 1) {
        [$precision, $scale] = [(int) $m[1], (int) $m[2]];

        return $precision >= 1 && $precision <= 38 && $scale <= $precision ? "decimal($precision,$scale)" : null;
    }

    return null;
}

/** Rows per INSERT: at most 1000 (the VALUES limit) and 2000 parameters (the driver limit is 2100). */
function insert_batch_size(int $columns): int
{
    return max(1, min(1000, intdiv(2000, max(1, $columns))));
}

/**
 * INSERT with positional parameters for $rows rows.
 *
 * @param list<string> $columns
 */
function build_insert_sql(string $schema, string $table, array $columns, int $rows): string
{
    $tuple = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

    return 'INSERT INTO ' . quote_identifier($schema) . '.' . quote_identifier($table)
        . ' (' . implode(', ', array_map(quote_identifier(...), $columns)) . ') VALUES '
        . implode(', ', array_fill(0, $rows, $tuple));
}

/**
 * CREATE TABLE for the import. Columns accept NULL. Fails (null) on a type outside the list,
 * an empty/long name or repeated names (case-insensitive).
 *
 * @param list<array{name:string,type:string}> $columns
 */
function build_import_create_table(string $schema, string $table, array $columns): ?string
{
    $names = array_map(static fn (array $c): string => strtolower($c['name']), $columns);
    if ($columns === [] || count(array_unique($names)) !== count($names)) {
        return null;
    }

    $defs = [];
    foreach ($columns as $c) {
        $type = import_column_type($c['type']);
        if ($type === null || $c['name'] === '' || strlen($c['name']) > 128) {
            return null;
        }
        $defs[] = quote_identifier($c['name']) . " $type NULL";
    }

    return 'CREATE TABLE ' . quote_identifier($schema) . '.' . quote_identifier($table)
        . " (\n    " . implode(",\n    ", $defs) . "\n)";
}
