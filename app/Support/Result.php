<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Immutable Either: success (ok) or failure (err). Lets you chain validations
 * without exceptions and without nested ifs.
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

    /** Transforms the success value; failures pass through untouched. */
    public function map(callable $fn): self
    {
        return $this->success ? self::ok($fn($this->value)) : $this;
    }

    /** Chains a function that also returns a Result. */
    public function flatMap(callable $fn): self
    {
        return $this->success ? $fn($this->value) : $this;
    }

    /** Collapses the Result into a single value. */
    public function fold(callable $onError, callable $onOk): mixed
    {
        return $this->success ? $onOk($this->value) : $onError($this->value);
    }
}
