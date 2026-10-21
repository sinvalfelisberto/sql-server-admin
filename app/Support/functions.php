<?php
declare(strict_types=1);

namespace App\Support;

/** Compõe funções da esquerda para a direita: pipe(f, g)(x) === g(f(x)). */
function pipe(callable ...$fns): \Closure
{
    return static fn (mixed $input): mixed => array_reduce(
        $fns,
        static fn (mixed $acc, callable $fn): mixed => $fn($acc),
        $input,
    );
}

/** Aplica parcialmente os primeiros argumentos de $fn. */
function partial(callable $fn, mixed ...$bound): \Closure
{
    return static fn (mixed ...$rest): mixed => $fn(...$bound, ...$rest);
}

/** Pega no máximo $n itens de um iterável, de forma preguiçosa. */
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
 * Monta uma cadeia de middlewares ao redor de $core.
 * Cada middleware é fn($request, callable $next); o primeiro da lista é o mais externo.
 */
function pipeline(array $middleware, callable $core): \Closure
{
    return array_reduce(
        array_reverse($middleware),
        static fn (callable $next, callable $mw): \Closure => static fn (mixed $request): mixed => $mw($request, $next),
        \Closure::fromCallable($core),
    );
}

/** Escapa texto para HTML (usar em toda saída de template). */
function e(?string $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
