<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use PDO;

/** Único ponto que abre conexões PDO com o SQL Server. */
final class ConnectionFactory
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Abre uma conexão nova. O banco é selecionado via USE (e não no DSN)
     * para que o nome nunca seja interpretado como opção de conexão.
     */
    public function open(Connection $connection, ?string $database = null): PDO
    {
        $encrypt = $this->config->get('encrypt');
        $dsn = build_dsn(
            $connection->server,
            $connection->trustCertificate,
            $this->config->int('login_timeout', 10),
            $encrypt === null ? null : (bool) $encrypt,
        );

        $pdo = new PDO($dsn, $connection->user, $connection->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE => true,
        ]);

        if ($database !== null && $database !== '') {
            $pdo->exec('USE ' . quote_identifier($database));
        }

        return $pdo;
    }
}
