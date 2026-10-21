<?php
declare(strict_types=1);

namespace App\Support;

/** Composes functions left to right: pipe(f, g)(x) === g(f(x)). */
function pipe(callable ...$fns): \Closure
{
    return static fn (mixed $input): mixed => array_reduce(
        $fns,
        static fn (mixed $acc, callable $fn): mixed => $fn($acc),
        $input,
    );
}

/** Partially applies the first arguments of $fn. */
function partial(callable $fn, mixed ...$bound): \Closure
{
    return static fn (mixed ...$rest): mixed => $fn(...$bound, ...$rest);
}

/** Takes at most $n items from an iterable, lazily. */
function take(iterable $items, int $n): \Generator
{
    if ($n <= 0) {
        return;
    }
    foreach ($items as $item) {
        yield $item;
        if (--$n === 0) {
            return;
        }
    }
}

/**
 * Builds a middleware chain around $core.
 * Each middleware is fn($request, callable $next); the first in the list is the outermost.
 */
function pipeline(array $middleware, callable $core): \Closure
{
    return array_reduce(
        array_reverse($middleware),
        static fn (callable $next, callable $mw): \Closure => static fn (mixed $request): mixed => $mw($request, $next),
        \Closure::fromCallable($core),
    );
}

/** Escapes text for HTML (use on all template output). */
function e(?string $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
