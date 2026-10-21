<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Either imutável: sucesso (ok) ou falha (err). Permite encadear validações
 * sem exceções e sem ifs aninhados.
 */
final readonly class Result
{
    private function __construct(private bool $success, private mixed $value)
    {
    }

    public static function ok(mixed $value): self
    {
        return new self(true, $value);
    }

    public static function err(mixed $error): self
    {
        return new self(false, $error);
    }

    public function isOk(): bool
    {
        return $this->success;
    }

    /** Transforma o valor de sucesso; falhas passam intactas. */
    public function map(callable $fn): self
    {
        return $this->success ? self::ok($fn($this->value)) : $this;
    }

    /** Encadeia uma função que também devolve Result. */
    public function flatMap(callable $fn): self
    {
        return $this->success ? $fn($this->value) : $this;
    }

    /** Colapsa o Result em um único valor. */
    public function fold(callable $onError, callable $onOk): mixed
    {
        return $this->success ? $onOk($this->value) : $onError($this->value);
    }
}
