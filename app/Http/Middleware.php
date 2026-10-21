<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Middleware factories. Each returns a function fn(Request, callable $next): Response,
 * composed into a pipeline by the Router.
 */
final class Middleware
{
    /** HTML pages: without a session, redirects to /login. */
    public static function authPage(Session $session): \Closure
    {
        return static fn (Request $r, callable $next): Response => $session->isLoggedIn()
            ? $next($r)
            : Response::redirect($r->url('login'));
    }

    /** JSON API: without a session, responds 401. */
    public static function authApi(Session $session): \Closure
    {
        return static fn (Request $r, callable $next): Response => $session->isLoggedIn()
            ? $next($r)
            : Response::json(['ok' => false, 'error' => ['message' => 'Session expired. Please sign in again.']], 401);
    }

    /** Users who are already signed in do not need the login screen. */
    public static function guest(Session $session): \Closure
    {
        return static fn (Request $r, callable $next): Response => $session->isLoggedIn()
            ? Response::redirect($r->url())
            : $next($r);
    }

    /** Requires a CSRF token in the X-CSRF-Token header or the "csrf" form field. */
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
