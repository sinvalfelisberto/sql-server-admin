<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Minimal container: explicit factories, single (lazy) instances. No magic autowiring. */
final class Container
{
    /** @var array<string, callable(self): mixed> */
    private array $factories = [];
    private array $instances = [];

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function get(string $id): mixed
    {
        if (!array_key_exists($id, $this->instances)) {
            $factory = $this->factories[$id] ?? throw new RuntimeException("Service not registered: $id");
            $this->instances[$id] = $factory($this);
        }

        return $this->instances[$id];
    }
}
