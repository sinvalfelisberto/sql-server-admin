<?php
declare(strict_types=1);

/**
 * Regras de domínio puras: sem I/O, sem estado global, sem efeitos colaterais.
 * Mesma entrada, mesma saída — por isso são testáveis sem banco.
 */

namespace App\Models;

use App\Support\Result;
use PDOException;

/** Delimita um identificador T-SQL: "a]b" -> "[a]]b]". */
function quote_identifier(string $identifier): string
{
    return '[' . str_replace(']', ']]', $identifier) . ']';
}

/** Valida e normaliza "host", "host\instancia", "host,porta" ou "host:porta". */
function normalize_server(string $server): Result
{
    $server = trim($server);
    $valid = preg_match('/^(tcp:)?[A-Za-z0-9_.\-]+(\\\\[A-Za-z0-9_$#.\-]+)?([,:]\d{1,5})?$/', $server) === 1;

    return $valid
        ? Result::ok((string) preg_replace('/:(\d{1,5})$/', ',$1', $server))
        : Result::err('Nome de servidor inválido.');
}

/** Falha se houver lista de permitidos e o servidor não estiver nela. */
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
        : Result::err('Servidor não permitido.');
}

/** DSN do pdo_sqlsrv. O banco NÃO entra no DSN (é escolhido via USE) para evitar injeção de opções. */
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

/** Divide o script em lotes pelo separador GO (linha isolada), como no SSMS. */
function split_batches(string $sql): array
{
    $parts = preg_split('/^[ \t]*GO[ \t]*(?:\d+[ \t]*)?(?:--.*)?$/mi', $sql) ?: [];

    return array_values(array_filter(
        array_map('trim', $parts),
        static fn (string $batch): bool => $batch !== '',
    ));
}

/** Limite efetivo de linhas: o pedido (se > 0) limitado pelo teto configurado. */
function effective_limit(int $requested, int $hardMax): int
{
    return $requested > 0 ? min($requested, $hardMax) : $hardMax;
}

/** Rótulo legível do tipo, ex.: nvarchar(100), decimal(18,2). */
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

/** Linha crua da consulta de colunas -> formato da API. */
function column_view(array $row): array
{
    return [
        'name'     => $row['name'],
        'type'     => type_label($row['type'], (int) $row['max_length'], (int) $row['precision'], (int) $row['scale']),
        'nullable' => (bool) $row['is_nullable'],
        'pk'       => (bool) $row['is_pk'],
    ];
}

/** Linha crua de sys.databases -> formato da API (bancos 1..4 são de sistema). */
function database_view(array $row): array
{
    return ['name' => $row['name'], 'system' => (bool) $row['is_system']];
}

/** Valor de célula seguro para JSON: binário vira 0xHEX. */
function safe_cell(mixed $value): mixed
{
    return is_string($value) && !mb_check_encoding($value, 'UTF-8')
        ? '0x' . strtoupper(bin2hex($value))
        : $value;
}

/** Metadados do driver -> descritor de coluna da API. */
function column_descriptor(array $meta): array
{
    return ['name' => $meta['name'], 'type' => $meta['sqlsrv:decl_type'] ?? ''];
}

/** Remove prefixos "[Microsoft][ODBC Driver 18 for SQL Server][SQL Server]" da mensagem. */
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
 * Devolve $sql com tudo que não é código de nível 0 trocado por espaços (mesmo tamanho):
 * comentários, textos entre aspas, identificadores [..] e "..", e o conteúdo de parênteses.
 * Serve para procurar palavras-chave da instrução principal sem cair em subconsultas ou textos.
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
 * Reescreve um SELECT único para devolver só uma página (OFFSET/FETCH).
 * Devolve null quando a consulta não é segura de paginar (vários comandos, SELECT INTO,
 * TOP, FOR XML/JSON, OPTION, UNION/DISTINCT sem ORDER BY...); nesse caso o chamador executa normalmente.
 * Sem ORDER BY usa (SELECT NULL): a ordem das páginas então depende do servidor.
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

/** Célula de CSV (separador ";"): aspas só quando necessário. */
function csv_cell(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    $text = is_bool($value) ? (string) (int) $value : (string) $value;

    return preg_match('/[";\r\n]/', $text) === 1 ? '"' . str_replace('"', '""', $text) . '"' : $text;
}

/** @param list<mixed> $cells */
function csv_line(array $cells): string
{
    return implode(';', array_map(csv_cell(...), $cells));
}

/** Nomes de coluna únicos para JSON: repetidos ganham sufixo _2, _3... */
function unique_keys(array $names): array
{
    $seen = [];

    return array_map(static function (string $name) use (&$seen): string {
        $seen[$name] = ($seen[$name] ?? 0) + 1;

        return $seen[$name] > 1 ? "{$name}_{$seen[$name]}" : $name;
    }, $names);
}

/** Nome de arquivo seguro para o cabeçalho Content-Disposition. */
function safe_filename(string $name, string $fallback = 'resultado'): string
{
    $clean = trim((string) preg_replace('/[^A-Za-z0-9._\-]+/', '_', $name), '._');

    return $clean === '' ? $fallback : $clean;
}
