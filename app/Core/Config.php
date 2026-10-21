<?php
declare(strict_types=1);

namespace App\Core;

final readonly class Config
{
    /** Variável de ambiente => [chave de configuração, tipo]. */
    private const ENV_MAP = [
        'SQLWM_DEFAULT_SERVER'           => ['default_server', 'string'],
        'SQLWM_ALLOWED_SERVERS'          => ['allowed_servers', 'list'],
        'SQLWM_ENCRYPT'                  => ['encrypt', 'bool'],
        'SQLWM_TRUST_SERVER_CERTIFICATE' => ['trust_server_certificate', 'bool'],
        'SQLWM_MAX_ROWS'                 => ['max_rows', 'int'],
        'SQLWM_EXPORT_MAX_ROWS'          => ['export_max_rows', 'int'],
        'SQLWM_QUERY_TIMEOUT'            => ['query_timeout', 'int'],
        'SQLWM_SESSION_TIMEOUT'          => ['session_timeout', 'int'],
    ];

    public function __construct(private array $values)
    {
    }

    /**
     * Precedência: config.php < config.local.php < variáveis de ambiente SQLWM_*.
     *
     * @param ?array<string, string> $env padrão: o ambiente do processo
     */
    public static function load(string $dir, ?array $env = null): self
    {
        $values = require $dir . '/config.php';
        $local = $dir . '/config.local.php';
        if (is_file($local)) {
            $values = array_replace($values, require $local);
        }

        return new self(array_replace($values, self::fromEnv($env ?? getenv())));
    }

    /**
     * Converte variáveis SQLWM_* em chaves de configuração tipadas. Pura: variáveis
     * ausentes, vazias ou inválidas são ignoradas.
     *
     * @param array<string, mixed> $env
     */
    public static function fromEnv(array $env): array
    {
        return array_reduce(
            array_keys(self::ENV_MAP),
            static function (array $acc, string $var) use ($env): array {
                $raw = $env[$var] ?? '';
                if (!is_string($raw) || trim($raw) === '') {
                    return $acc;
                }
                [$key, $type] = self::ENV_MAP[$var];
                $value = match ($type) {
                    'int'    => filter_var($raw, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
                    'bool'   => filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
                    'list'   => array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== '')),
                    default  => trim($raw),
                };

                return $value === null ? $acc : $acc + [$key => $value];
            },
            [],
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) ($this->values[$key] ?? $default);
    }
}
