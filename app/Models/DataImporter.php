<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use PDO;
use RuntimeException;
use Throwable;

/** Inserts imported rows (CSV/JSON) into a table, creating it first when asked. */
final class DataImporter
{
    public function __construct(
        private readonly ConnectionFactory $factory,
        private readonly Config $config,
    ) {
    }

    /**
     * Each call is atomic: it creates the table (if $create) and inserts all rows in one transaction.
     *
     * @param list<string> $columns destination columns, in cell order
     * @param list<list<?string>> $rows
     * @param ?list<array{name:string,type:string}> $create column definitions to create the table; null = the table already exists
     * @return int rows inserted
     * @throws RuntimeException import rule violated (message ready for the user)
     * @throws \PDOException database failure
     */
    public function import(Connection $connection, string $database, string $schema, string $table, array $columns, array $rows, ?array $create = null): int
    {
        $createSql = null;
        if ($create !== null) {
            $createSql = build_import_create_table($schema, $table, $create)
                ?? throw new RuntimeException('Invalid column definition for the new table.');
        }

        $pdo = $this->factory->open($connection, $database);
        $pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, $this->config->int('query_timeout', 120));
        $pdo->beginTransaction();

        try {
            if ($createSql !== null) {
                $pdo->exec($createSql);
            } else {
                $exists = $pdo->prepare("SELECT OBJECT_ID(?, 'U')");
                $exists->execute([quote_identifier($schema) . '.' . quote_identifier($table)]);
                if ($exists->fetchColumn() === null) {
                    throw new RuntimeException('Table ' . quote_identifier($schema) . '.' . quote_identifier($table) . ' not found.');
                }
            }

            $size = insert_batch_size(count($columns));
            $statements = [];
            foreach (array_chunk($rows, $size) as $batch) {
                $statements[count($batch)] ??= $pdo->prepare(build_insert_sql($schema, $table, $columns, count($batch)));
                $statements[count($batch)]->execute(array_merge(...$batch));
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return count($rows);
    }
}
