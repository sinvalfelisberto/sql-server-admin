<?php
declare(strict_types=1);

namespace App\Http;

use App\Models\Connection;

/** Wraps $_SESSION: the rest of the application never touches superglobals. */
final class Session
{
    public function start(int $idleTimeout, bool $secure): void
    {
        session_name('sqlwm');
        session_set_cookie_params([
            'lifetime' => 0,
            'httponly' => true,
            'samesite' => 'Strict',
            'secure'   => $secure,
        ]);
        session_start();

        if ($this->isLoggedIn() && time() - (int) ($_SESSION['last'] ?? 0) > $idleTimeout) {
            $this->destroy();
            session_start();
        }
        if ($this->isLoggedIn()) {
            $_SESSION['last'] = time();
        }
    }

    /** Sessions with an old/invalid format (for example, after an upgrade) count as signed out. */
    public function isLoggedIn(): bool
    {
        $conn = $_SESSION['conn'] ?? null;

        return is_array($conn) && isset($conn['server'], $conn['user'], $conn['password'], $conn['display'])
            && array_key_exists('trustCertificate', $conn);
    }

    public function login(Connection $connection): void
    {
        session_regenerate_id(true);
        $_SESSION['conn'] = $connection->toArray();
        $_SESSION['last'] = time();
    }

    public function connection(): Connection
    {
        return Connection::fromArray($_SESSION['conn']);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public function csrfToken(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public function isValidCsrf(?string $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals($_SESSION['csrf'] ?? '', $token);
    }

    /** Releases the session lock: long queries do not block other requests. */
    public function release(): void
    {
        session_write_close();
    }
}
