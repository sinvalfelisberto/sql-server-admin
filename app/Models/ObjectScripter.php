<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

/** Generates DDL scripts for databases and objects from the server catalog. */
final class ObjectScripter
{
    private const SQL_COLUMNS = <<<'SQL'
        SELECT c.name, t.name AS type, c.max_length, c.precision, c.scale, c.is_nullable,
               c.is_identity, c.is_computed,
               ic.seed_value, ic.increment_value,
               cc.definition AS computed_def, cc.is_persisted,
               dc.name AS default_name, dc.definition AS default_def
          FROM sys.columns c
          JOIN sys.types t ON t.user_type_id = c.user_type_id
          LEFT JOIN sys.identity_columns ic ON ic.object_id = c.object_id AND ic.column_id = c.column_id
          LEFT JOIN sys.computed_columns cc ON cc.object_id = c.object_id AND cc.column_id = c.column_id
          LEFT JOIN sys.default_constraints dc ON dc.parent_object_id = c.object_id AND dc.parent_column_id = c.column_id
         WHERE c.object_id = OBJECT_ID(?)
         ORDER BY c.column_id
        SQL;

    private const SQL_CHECKS = <<<'SQL'
        SELECT name, definition FROM sys.check_constraints
         WHERE parent_object_id = OBJECT_ID(?) ORDER BY name
        SQL;

    private const SQL_DATABASE = <<<'SQL'
        SELECT name, collation_name, compatibility_level, recovery_model_desc, page_verify_option_desc,
               is_auto_close_on, is_auto_shrink_on, is_auto_create_stats_on, is_auto_update_stats_on,
               is_read_committed_snapshot_on, snapshot_isolation_state_desc
          FROM sys.databases WHERE name = ?
        SQL;

    public function __construct(
        private readonly ConnectionFactory $factory,
        private readonly CatalogRepository $catalog,
    ) {
    }

    /** Complete CREATE TABLE for tables; the object definition for views, procedures and functions. */
    public function script(Connection $connection, string $database, string $schema, string $name, string $type): string
    {
        $pdo = $this->factory->open($connection, $database);

        return $type === 'U'
            ? $this->tableScript($pdo, $schema, $name)
            : $this->definitionScript($pdo, quote_identifier($schema) . '.' . quote_identifier($name));
    }

    public function databaseScript(Connection $connection, string $database): string
    {
        $stmt = $this->factory->open($connection)->prepare(self::SQL_DATABASE);
        $stmt->execute([$database]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? '-- Database not found.' . "\n" : build_create_database($row);
    }

    private function tableScript(PDO $pdo, string $schema, string $name): string
    {
        $qualified = quote_identifier($schema) . '.' . quote_identifier($name);

        $columns = $this->rows($pdo, self::SQL_COLUMNS, [$qualified]);
        if ($columns === []) {
            return "-- Table $qualified not found.\n";
        }
        $checks = $this->rows($pdo, self::SQL_CHECKS, [$qualified]);
        $details = $this->catalog->tableDetails($pdo, $schema, $name);

        return build_create_table($schema, $name, $columns, $details['indexes'], $details['foreignKeys'], $checks);
    }

    private function definitionScript(PDO $pdo, string $qualified): string
    {
        $stmt = $pdo->prepare('SELECT OBJECT_DEFINITION(OBJECT_ID(?))');
        $stmt->execute([$qualified]);
        $definition = $stmt->fetchColumn();

        return is_string($definition) && $definition !== ''
            ? rtrim($definition) . "\nGO\n"
            : "-- Definition unavailable (object does not exist, is encrypted, or you lack VIEW DEFINITION permission).\n";
    }

    private function rows(PDO $pdo, string $sql, array $params): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
