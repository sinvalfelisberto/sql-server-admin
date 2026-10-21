<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use PDO;

/** The only place that opens PDO connections to SQL Server. */
final class ConnectionFactory
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Opens a new connection. The database is selected with USE (not in the DSN)
     * so its name is never interpreted as a connection option.
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
