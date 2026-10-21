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

    /**
     * Executa uma página (OFFSET/FETCH) de um SELECT único. Devolve null quando a consulta não
     * pode ser paginada (script com vários lotes, SELECT INTO, TOP, UNION sem ORDER BY...) ou o
     * servidor recusa a versão paginada; o chamador então usa execute().
     *
     * @param int $cap máximo de linhas ao todo (AUTO TOP); 0 = sem teto
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
     * Abre um SELECT único para leitura em fluxo (exportação). Devolve [PDO, statement, colunas];
     * mantenha o PDO vivo enquanto lê as linhas.
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
     * Linhas do statement, uma a uma, com células seguras para JSON.
     *
     * @param int $max 0 = todas
     */
    public function streamRows(PDOStatement $stmt, int $max = 0): Generator
    {
        $rows = $this->fetchRows($stmt);
        foreach ($max > 0 ? take($rows, $max) : $rows as $row) {
            yield array_map(safe_cell(...), $row);
        }
    }

    /** Produz, em ordem, cada result set ou mensagem de linhas afetadas do lote. */
    private function runBatch(PDO $pdo, string $batch, int $limit): Generator
    {
        $stmt = $pdo->query($batch);

        do {
            // PRINT e mensagens informativas (severidade <= 10) chegam em errorInfo, não como exceção.
            yield from $this->infoMessages($stmt);

            if ($stmt->columnCount() > 0) {
                yield ['set' => $this->readSet($stmt, $limit)];
            } elseif (($affected = $stmt->rowCount()) >= 0) {
                yield ['message' => "($affected row(s) affected)"];
            }
        } while ($stmt->nextRowset());

        yield from $this->infoMessages($stmt);
    }

    /** Mensagem informativa pendente do servidor (PRINT, avisos), se houver. */
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
