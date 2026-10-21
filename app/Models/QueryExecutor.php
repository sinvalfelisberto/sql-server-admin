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
 * Executa scripts T-SQL. A parte com efeito colateral (PDO) fica aqui;
 * as transformações dos dados são funções puras (ver functions.php).
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
            // Mantém o que já foi lido antes do erro, como o SSMS.
            $error = pdo_error($e);
        }

        return new QueryResult($sets, $messages, $error, (int) round((hrtime(true) - $start) / 1e6));
    }

    /** Produz, em ordem, cada result set ou mensagem de linhas afetadas do lote. */
    private function runBatch(PDO $pdo, string $batch, int $limit): Generator
    {
        $stmt = $pdo->query($batch);

        do {
            if ($stmt->columnCount() > 0) {
                yield ['set' => $this->readSet($stmt, $limit)];
            } elseif (($affected = $stmt->rowCount()) >= 0) {
                yield ['message' => "($affected linha(s) afetada(s))"];
            }
        } while ($stmt->nextRowset());
    }

    private function readSet(PDOStatement $stmt, int $limit): array
    {
        $columns = array_map(
            static fn (int $i): array => column_descriptor($stmt->getColumnMeta($i)),
            range(0, $stmt->columnCount() - 1),
        );

        // Lê limite+1 linhas: a sobra indica truncamento sem puxar o resultado inteiro.
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
