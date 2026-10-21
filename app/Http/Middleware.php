<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Fábricas de middleware. Cada uma devolve uma função fn(Request, callable $next): Response,
 * compostas em pipeline pelo Router.
 */
final class Middleware
{
    /** Páginas HTML: sem login, redireciona para /login. */
    public static function authPage(Session $session): \Closure
    {
        return static fn (Request $r, callable $next): Response => $session->isLoggedIn()
            ? $next($r)
            : Response::redirect($r->url('login'));
    }

    /** API JSON: sem login, responde 401. */
    public static function authApi(Session $session): \Closure
    {
        return static fn (Request $r, callable $next): Response => $session->isLoggedIn()
            ? $next($r)
            : Response::json(['ok' => false, 'error' => ['message' => 'Session expired. Please sign in again.']], 401);
    }

    /** Quem já está logado não precisa ver a tela de login. */
    public static function guest(Session $session): \Closure
    {
        return static fn (Request $r, callable $next): Response => $session->isLoggedIn()
            ? Response::redirect($r->url())
            : $next($r);
    }

    /** Exige token CSRF no cabeçalho X-CSRF-Token ou no campo de formulário "csrf". */
    public static function csrf(Session $session): \Closure
    {
        return static function (Request $r, callable $next) use ($session): Response {
            if ($session->isValidCsrf($r->header('X-CSRF-Token') ?? ($r->post['csrf'] ?? null))) {
                return $next($r);
            }

            return str_starts_with($r->path, '/api/')
                ? Response::json(['ok' => false, 'error' => ['message' => 'Invalid CSRF token. Reload the page.']], 403)
                : Response::html('Session expired. <a href="' . htmlspecialchars($r->url('login')) . '">Back to sign in</a>.', 403);
        };
    }
}
