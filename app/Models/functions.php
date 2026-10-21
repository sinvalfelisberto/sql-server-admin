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
