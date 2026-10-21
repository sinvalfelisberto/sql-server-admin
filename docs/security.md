# Security

The application runs **arbitrary SQL with the credentials of whoever signed in**. It does not replace SQL Server's access control: whoever can sign in can do everything the login allows, including `UPDATE`, `DELETE` and DDL. There is no read-only mode (it is in the PRD but was not implemented).

## Deployment recommendations

1. **Use HTTPS** in any environment other than localhost. Passwords travel in the login form.
2. **Fill in `allowed_servers`** to stop the application from acting as a proxy to any host on the network (SSRF). Empty means "any server".
3. **Least privilege:** create logins with only the permissions they need. Without `VIEW DEFINITION`, **View DDL** for procedures and views does not work.
4. **Expose only what is needed.** With `public/` as the document root, `app/` and `config/` are unreachable. Without that option, use the root `index.php` and the [IIS](configuration.md#iis-whole-project-in-one-folder) rules, which send everything except `assets/` to the front controller.
5. Restrict network access (VPN, firewall, IP allowlist) when possible.
6. `docker-compose.yml` ships no password: set `MSSQL_SA_PASSWORD` in `.env` (not versioned) to use the `demo` profile, and never commit that file.

## What the application does

| Area | Measure | Where |
|---|---|---|
| Password | Kept in the PHP session, on the server; never sent to the browser | `Session`, `Connection` |
| Session cookie | Name `sqlwm`, `HttpOnly`, `SameSite=Strict`, `Secure` over HTTPS, strict session mode | `Session::start`, `docker/php.ini` |
| Session fixation | `session_regenerate_id(true)` on login | `Session::login` |
| CSRF | Every `POST` requires a token (header or field), compared with `hash_equals` | `Middleware::csrf` |
| Brute force | 0.5 s delay on a failed login | `AuthController::verify` |
| Expiry | The session ends after `session_timeout` s of inactivity (default 3600) | `Session::start` |
| Target server | Name validated by a regular expression; optional allowlist | `normalize_server`, `ensure_server_allowed` |
| Injection in connection options | The database is not part of the DSN; `USE` with an escaped identifier is used (`]` becomes `]]`) | `ConnectionFactory`, `quote_identifier` |
| Catalog | Parameterized queries (`prepare`/`execute`) | `CatalogRepository`, `ObjectScripter` |
| XSS | All template output goes through `e()` (`htmlspecialchars`) | `Views` |
| Clickjacking and sniffing | `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` | `Application` |
| Error leakage | Unexpected exceptions go to the log; the client gets a generic message. `display_errors=Off`, `expose_php=Off` | `Application`, `docker/php.ini` |
| Caching | JSON responses carry `Cache-Control: no-store` | `Response::json` |
| Limits | `query_timeout`, `max_rows` (per page when paginated) and `memory_limit` bound the cost of each query | `Config`, `QueryExecutor` |
| Export | `POST /api/export` requires a session and CSRF like the other routes; the file name is sanitized (`safe_filename`) | `QueryController::export` |

## Known limitations

- **No per-user control at the application level:** there are no roles, audit trail or blocklist of commands.
- **No rate limiting** beyond the 0.5 s delay on a failed login.
- **Complete export has no PHP time limit and, by default, no row cap:** an authenticated user can keep the server busy exporting a huge table. Set `export_max_rows` (`SQLWM_EXPORT_MAX_ROWS`) and review `query_timeout` in shared environments.
- **Pagination re-runs the query on every page:** pages far into large tables cost the same as the query up to that point.
- **Clear-text password in the session:** anyone with read access to the server's session storage (`session.save_path`) can read the credentials of signed-in users. Restrict the permissions on that folder.
- **`localStorage`:** history and favorites stay in the browser, as plain text, and can contain sensitive data typed into queries. Use a dedicated browser profile on shared machines.
- **Trusting the server certificate** disables TLS certificate validation between the application and SQL Server (`TrustServerCertificate`). Prefer a valid certificate and uncheck the option.
- **CSV and formulas:** the CSV writes cell text without neutralizing the start (`=`, `+`, `-`, `@`). Opened in Excel, such a value can be interpreted as a formula. If the data comes from third parties, prefer the **Excel** button: the `.xlsx` writes all text as text (`inlineStr`), not as a formula.

## Reporting a vulnerability

The project has no published security channel (**pending:** define a contact and a policy, for example a `SECURITY.md`).
