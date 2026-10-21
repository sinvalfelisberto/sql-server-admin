<?php
declare(strict_types=1);

namespace App\Models;

use App\Support\Result;

/** Credentials and target of a SQL Server connection. Immutable. */
final readonly class Connection
{
    public function __construct(
        public string $server,
        public string $user,
        public string $password,
        public bool $trustCertificate,
        public string $display,
        public string $database = '',
    ) {
    }

    /**
     * Validates the login form input without throwing exceptions.
     *
     * @param list<string> $allowedServers
     * @return Result<self> fails with a friendly message
     */
    public static function fromLogin(array $input, array $allowedServers, bool $trustCertificate): Result
    {
        $display = trim((string) ($input['server'] ?? ''));
        $user = trim((string) ($input['user'] ?? ''));

        return normalize_server($display)
            ->flatMap(static fn (string $server): Result => ensure_server_allowed($allowedServers, $server))
            ->flatMap(static fn (string $server): Result => $user === ''
                ? Result::err('Enter the user name.')
                : Result::ok(new self($server, $user, (string) ($input['pass'] ?? ''), $trustCertificate, $display)));
    }

    public function withDatabase(string $database): self
    {
        return new self($this->server, $this->user, $this->password, $this->trustCertificate, $this->display, $database);
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['server'],
            $data['user'],
            $data['password'],
            (bool) $data['trustCertificate'],
            $data['display'],
            $data['database'] ?? '',
        );
    }
}
