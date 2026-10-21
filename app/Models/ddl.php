<?php
declare(strict_types=1);

/**
 * Geração de scripts DDL e resumo de metadados. Funções puras: recebem as linhas
 * do catálogo (sys.*) e devolvem texto/estruturas, sem tocar no banco.
 */

namespace App\Models;

/** Linhas de sys.index_columns (uma por coluna) -> um item por índice. */
function group_indexes(array $rows): array
{
    $grouped = array_reduce($rows, static function (array $acc, array $r): array {
        $id = (int) $r['index_id'];
        $index = $acc[$id] ?? [
            'name'             => $r['name'],
            'type'             => $r['type_desc'],
            'unique'           => (bool) $r['is_unique'],
            'primaryKey'       => (bool) $r['is_primary_key'],
            'uniqueConstraint' => (bool) $r['is_unique_constraint'],
            'filter'           => $r['filter_definition'],
            'keys'             => [],
            'included'         => [],
        ];

        if ($r['is_included_column']) {
            $index['included'][] = $r['column_name'];
        } else {
            $index['keys'][] = ['name' => $r['column_name'], 'desc' => (bool) $r['is_descending_key']];
        }

        return [$id => $index] + $acc;
    }, []);

    ksort($grouped);

    return array_values($grouped);
}

/**
 * Linhas de sys.foreign_key_columns -> uma FK por item.
 * direction: 'out' (esta tabela referencia outra) ou 'in' (é referenciada por outra).
 */
function group_foreign_keys(array $rows, string $schema, string $table): array
{
    $grouped = array_reduce($rows, static function (array $acc, array $r) use ($schema, $table): array {
        $id = (int) $r['fk_id'];
        $fk = $acc[$id] ?? [
            'name'        => $r['name'],
            'direction'   => strcasecmp($r['parent_schema'], $schema) === 0 && strcasecmp($r['parent_table'], $table) === 0 ? 'out' : 'in',
            'parentTable' => $r['parent_schema'] . '.' . $r['parent_table'],
            'refSchema'   => $r['ref_schema'],
            'refTable'    => $r['ref_schema'] . '.' . $r['ref_table'],
            'refName'     => $r['ref_table'],
            'onDelete'    => str_replace('_', ' ', $r['on_delete']),
            'onUpdate'    => str_replace('_', ' ', $r['on_update']),
            'columns'     => [],
            'refColumns'  => [],
        ];
        $fk['columns'][] = $r['column_name'];
        $fk['refColumns'][] = $r['ref_column'];

        return [$id => $fk] + $acc;
    }, []);

    ksort($grouped);

    return array_values($grouped);
}

/** Linha de sys.dm_db_partition_stats (agregada) -> resumo de linhas e espaço em KB. */
function space_view(array $row): array
{
    $reserved = (int) ($row['reserved_kb'] ?? 0);
    $used = (int) ($row['used_kb'] ?? 0);
    $data = (int) ($row['data_kb'] ?? 0);

    return [
        'rows'       => ($row['row_count'] ?? null) === null ? null : (int) $row['row_count'],
        'reservedKb' => $reserved,
        'dataKb'     => $data,
        'indexKb'    => max(0, $used - $data),
        'unusedKb'   => max(0, $reserved - $used),
    ];
}

/** Definição de uma coluna dentro do CREATE TABLE. */
function column_definition(array $c): string
{
    $name = quote_identifier($c['name']);

    if ($c['is_computed']) {
        return "$name AS {$c['computed_def']}" . ($c['is_persisted'] ? ' PERSISTED' : '');
    }

    return implode(' ', array_filter([
        $name,
        type_label($c['type'], (int) $c['max_length'], (int) $c['precision'], (int) $c['scale']),
        $c['is_identity'] ? "IDENTITY({$c['seed_value']},{$c['increment_value']})" : null,
        $c['is_nullable'] ? 'NULL' : 'NOT NULL',
        $c['default_name'] !== null ? 'CONSTRAINT ' . quote_identifier($c['default_name']) . " DEFAULT {$c['default_def']}" : null,
    ]));
}

/** "[a] ASC, [b] DESC" a partir das chaves de um índice. */
function index_key_list(array $keys, bool $withOrder = true): string
{
    return implode(', ', array_map(
        static fn (array $k): string => quote_identifier($k['name']) . ($withOrder ? ($k['desc'] ? ' DESC' : ' ASC') : ''),
        $keys,
    ));
}

/** Constraint PRIMARY KEY/UNIQUE declarada dentro do CREATE TABLE. */
function key_constraint(array $index): string
{
    return 'CONSTRAINT ' . quote_identifier($index['name'])
        . ($index['primaryKey'] ? ' PRIMARY KEY ' : ' UNIQUE ')
        . $index['type'] . ' (' . index_key_list($index['keys']) . ')';
}

/** CREATE INDEX para índices que não são PK/UNIQUE constraint. */
function index_script(string $qualifiedTable, array $index): string
{
    $name = quote_identifier($index['name']);
    $type = (string) $index['type'];

    if (str_contains($type, 'COLUMNSTORE')) {
        $cols = index_key_list($index['keys'], false);
        $columns = $cols === '' ? '' : " ($cols)";

        return "CREATE $type INDEX $name ON $qualifiedTable$columns;";
    }
    if (!in_array($type, ['CLUSTERED', 'NONCLUSTERED'], true)) {
        return "-- Índice $name ($type) omitido: tipo não suportado pelo gerador.";
    }

    return 'CREATE ' . ($index['unique'] ? 'UNIQUE ' : '') . "$type INDEX $name ON $qualifiedTable (" . index_key_list($index['keys']) . ')'
        . ($index['included'] ? ' INCLUDE (' . implode(', ', array_map(quote_identifier(...), $index['included'])) . ')' : '')
        . ($index['filter'] !== null ? " WHERE {$index['filter']}" : '')
        . ';';
}

/** ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY. */
function foreign_key_script(string $qualifiedTable, array $fk): string
{
    return "ALTER TABLE $qualifiedTable ADD CONSTRAINT " . quote_identifier($fk['name'])
        . ' FOREIGN KEY (' . implode(', ', array_map(quote_identifier(...), $fk['columns'])) . ')'
        . ' REFERENCES ' . quote_identifier($fk['refSchema']) . '.' . quote_identifier($fk['refName'])
        . ' (' . implode(', ', array_map(quote_identifier(...), $fk['refColumns'])) . ')'
        . ($fk['onDelete'] !== 'NO ACTION' ? " ON DELETE {$fk['onDelete']}" : '')
        . ($fk['onUpdate'] !== 'NO ACTION' ? " ON UPDATE {$fk['onUpdate']}" : '')
        . ';';
}

/**
 * Script completo de uma tabela: CREATE TABLE (colunas, PK, UNIQUE, CHECK),
 * índices e chaves estrangeiras, em lotes separados por GO.
 *
 * @param list<array> $columns linhas de sys.columns enriquecidas (ver ObjectScripter)
 * @param list<array> $indexes saída de group_indexes()
 * @param list<array> $foreignKeys saída de group_foreign_keys() (só direction 'out' entram)
 * @param list<array{name:string,definition:string}> $checks
 */
function build_create_table(string $schema, string $table, array $columns, array $indexes, array $foreignKeys, array $checks): string
{
    $qualified = quote_identifier($schema) . '.' . quote_identifier($table);

    $keyConstraints = array_map(
        key_constraint(...),
        array_values(array_filter($indexes, static fn (array $i): bool => $i['primaryKey'] || $i['uniqueConstraint'])),
    );
    $checkConstraints = array_map(
        static fn (array $c): string => 'CONSTRAINT ' . quote_identifier($c['name']) . " CHECK {$c['definition']}",
        $checks,
    );
    $body = array_merge(array_map(column_definition(...), $columns), $keyConstraints, $checkConstraints);

    $extraIndexes = array_map(
        static fn (array $i): string => index_script($qualified, $i),
        array_values(array_filter($indexes, static fn (array $i): bool => !$i['primaryKey'] && !$i['uniqueConstraint'])),
    );
    $outgoing = array_map(
        static fn (array $fk): string => foreign_key_script($qualified, $fk),
        array_values(array_filter($foreignKeys, static fn (array $fk): bool => $fk['direction'] === 'out')),
    );

    $batches = array_merge(
        ["CREATE TABLE $qualified (\n    " . implode(",\n    ", $body) . "\n);"],
        $extraIndexes,
        $outgoing,
    );

    return implode("\nGO\n\n", $batches) . "\nGO\n";
}

/** Script de criação do banco com collation e opções ativas (arquivos MDF/LDF não incluídos). */
function build_create_database(array $db): string
{
    $name = quote_identifier($db['name']);
    $onOff = static fn (mixed $flag): string => $flag ? 'ON' : 'OFF';

    $create = "CREATE DATABASE $name"
        . (($db['collation_name'] ?? null) !== null ? "\n    COLLATE {$db['collation_name']}" : '')
        . ';';

    $options = [
        "COMPATIBILITY_LEVEL = {$db['compatibility_level']}",
        "RECOVERY {$db['recovery_model_desc']}",
        "PAGE_VERIFY {$db['page_verify_option_desc']}",
        'AUTO_CLOSE ' . $onOff($db['is_auto_close_on']),
        'AUTO_SHRINK ' . $onOff($db['is_auto_shrink_on']),
        'AUTO_CREATE_STATISTICS ' . $onOff($db['is_auto_create_stats_on']),
        'AUTO_UPDATE_STATISTICS ' . $onOff($db['is_auto_update_stats_on']),
        'READ_COMMITTED_SNAPSHOT ' . $onOff($db['is_read_committed_snapshot_on']),
        'ALLOW_SNAPSHOT_ISOLATION ' . ($db['snapshot_isolation_state_desc'] === 'ON' ? 'ON' : 'OFF'),
    ];

    return implode("\n", [
        $create,
        'GO',
        '',
        ...array_map(static fn (string $o): string => "ALTER DATABASE $name SET $o;", $options),
        'GO',
        '',
        '-- Arquivos de dados e de log (MDF/LDF) não incluídos neste script.',
        '',
    ]);
}
