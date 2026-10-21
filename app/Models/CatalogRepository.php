<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

/** Reads the server catalog (databases, objects, columns). */
final class CatalogRepository
{
    private const SQL_OBJECTS = <<<'SQL'
        SELECT s.name AS [schema], o.name AS name, RTRIM(o.type) AS type
          FROM sys.objects o
          JOIN sys.schemas s ON s.schema_id = o.schema_id
         WHERE o.is_ms_shipped = 0 AND o.type IN ('U','V','P','FN','IF','TF')
         ORDER BY s.name, o.name
        SQL;

    private const SQL_COLUMNS = <<<'SQL'
        SELECT c.name, t.name AS type, c.max_length, c.precision, c.scale, c.is_nullable,
               c.is_identity, c.is_computed,
               CASE WHEN EXISTS (
                   SELECT 1 FROM sys.index_columns ic
                     JOIN sys.indexes i ON i.object_id = ic.object_id AND i.index_id = ic.index_id
                    WHERE i.is_primary_key = 1 AND ic.object_id = c.object_id AND ic.column_id = c.column_id
               ) THEN 1 ELSE 0 END AS is_pk
          FROM sys.columns c
          JOIN sys.types t ON t.user_type_id = c.user_type_id
         WHERE c.object_id = OBJECT_ID(?)
         ORDER BY c.column_id
        SQL;

    private const SQL_PARAMETERS = <<<'SQL'
        SELECT p.name, t.name AS type, p.max_length, p.precision, p.scale, 1 AS is_nullable,
               0 AS is_identity, 0 AS is_computed, 0 AS is_pk
          FROM sys.parameters p
          JOIN sys.types t ON t.user_type_id = p.user_type_id
         WHERE p.object_id = OBJECT_ID(?) AND p.parameter_id > 0
         ORDER BY p.parameter_id
        SQL;

    private const SQL_SPACE = <<<'SQL'
        SELECT SUM(CASE WHEN index_id IN (0, 1) THEN row_count END) AS row_count,
               SUM(reserved_page_count) * 8 AS reserved_kb,
               SUM(used_page_count) * 8 AS used_kb,
               SUM(CASE WHEN index_id < 2
                        THEN in_row_data_page_count + lob_used_page_count + row_overflow_used_page_count
                        ELSE lob_used_page_count + row_overflow_used_page_count END) * 8 AS data_kb
          FROM sys.dm_db_partition_stats
         WHERE object_id = OBJECT_ID(?)
        SQL;

    private const SQL_INDEXES = <<<'SQL'
        SELECT i.index_id, i.name, i.type_desc, i.is_unique, i.is_primary_key, i.is_unique_constraint,
               i.filter_definition, ic.is_descending_key, ic.is_included_column, c.name AS column_name
          FROM sys.indexes i
          JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
          JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
         WHERE i.object_id = OBJECT_ID(?) AND i.type > 0
         ORDER BY i.index_id, ic.is_included_column, ic.key_ordinal, ic.index_column_id
        SQL;

    private const SQL_FOREIGN_KEYS = <<<'SQL'
        SELECT fk.object_id AS fk_id, fk.name,
               fk.delete_referential_action_desc AS on_delete, fk.update_referential_action_desc AS on_update,
               SCHEMA_NAME(pt.schema_id) AS parent_schema, pt.name AS parent_table, pc.name AS column_name,
               SCHEMA_NAME(rt.schema_id) AS ref_schema, rt.name AS ref_table, rc.name AS ref_column
          FROM sys.foreign_keys fk
          JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id
          JOIN sys.tables pt ON pt.object_id = fk.parent_object_id
          JOIN sys.tables rt ON rt.object_id = fk.referenced_object_id
          JOIN sys.columns pc ON pc.object_id = fkc.parent_object_id AND pc.column_id = fkc.parent_column_id
          JOIN sys.columns rc ON rc.object_id = fkc.referenced_object_id AND rc.column_id = fkc.referenced_column_id
         WHERE fk.parent_object_id = OBJECT_ID(?) OR fk.referenced_object_id = OBJECT_ID(?)
         ORDER BY fk.object_id, fkc.constraint_column_id
        SQL;

    public function __construct(private readonly ConnectionFactory $factory)
    {
    }

    /**
     * Table/view metadata: approximate rows, space, indexes and foreign keys.
     *
     * @return array{rows:?int,reservedKb:int,dataKb:int,indexKb:int,unusedKb:int,indexes:list<array>,foreignKeys:list<array>}
     */
    public function tableInfo(Connection $connection, string $database, string $schema, string $name): array
    {
        $pdo = $this->factory->open($connection, $database);
        $stmt = $pdo->prepare(self::SQL_SPACE);
        $stmt->execute([quote_identifier($schema) . '.' . quote_identifier($name)]);

        return space_view($stmt->fetch(PDO::FETCH_ASSOC) ?: []) + $this->tableDetails($pdo, $schema, $name);
    }

    /** @return array{indexes:list<array>,foreignKeys:list<array>} */
    public function tableDetails(PDO $pdo, string $schema, string $name): array
    {
        $qualified = quote_identifier($schema) . '.' . quote_identifier($name);

        $indexes = $pdo->prepare(self::SQL_INDEXES);
        $indexes->execute([$qualified]);

        $foreignKeys = $pdo->prepare(self::SQL_FOREIGN_KEYS);
        $foreignKeys->execute([$qualified, $qualified]);

        return [
            'indexes'     => group_indexes($indexes->fetchAll(PDO::FETCH_ASSOC)),
            'foreignKeys' => group_foreign_keys($foreignKeys->fetchAll(PDO::FETCH_ASSOC), $schema, $name),
        ];
    }

    /** @return array{databases: list<array{name:string,system:bool}>, version: string} */
    public function serverInfo(Connection $connection): array
    {
        $pdo = $this->factory->open($connection);

        return [
            'databases' => array_map(
                database_view(...),
                $pdo->query('SELECT name, CASE WHEN database_id <= 4 THEN 1 ELSE 0 END AS is_system
                               FROM sys.databases WHERE HAS_DBACCESS(name) = 1 ORDER BY name')
                    ->fetchAll(PDO::FETCH_ASSOC),
            ),
            'version' => (string) $pdo->query("SELECT CAST(SERVERPROPERTY('ProductVersion') AS varchar(50))")->fetchColumn(),
        ];
    }

    /** @return list<array{schema:string,name:string,type:string}> */
    public function objects(Connection $connection, string $database): array
    {
        return $this->factory->open($connection, $database)
            ->query(self::SQL_OBJECTS)
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Columns of tables/views; parameters of procedures and functions.
     *
     * @return list<array{name:string,type:string,nullable:bool,pk:bool,identity:bool,computed:bool}>
     */
    public function columns(Connection $connection, string $database, string $schema, string $name, string $type): array
    {
        $isTable = in_array($type, ['U', 'V'], true);
        $stmt = $this->factory->open($connection, $database)
            ->prepare($isTable ? self::SQL_COLUMNS : self::SQL_PARAMETERS);
        $stmt->execute([quote_identifier($schema) . '.' . quote_identifier($name)]);

        return array_map(column_view(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
