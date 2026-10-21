# Configuration and operations

## Requirements

| Item | Version |
|---|---|
| PHP | 8.2 or later (the code uses `readonly class`; on PHP 8.1 or earlier the application does not start) |
| PHP extensions | `pdo_sqlsrv` and `sqlsrv` |
| Driver | Microsoft ODBC Driver 17 or 18 for SQL Server |
| Browser | Modern, with `<dialog>`, `fetch`, `Blob` and `localStorage` |
| Node.js | Only to run `tests/xlsx.test.js` (it uses `zlib.crc32`, available in Node 20.15+/22.2+ — **pending:** exact minimum version not verified) |

The Docker image ships PHP 8.3, Apache, ODBC Driver 18 and `pdo_sqlsrv`.

## Settings

Precedence: `config/config.php` < `config/config.local.php` < environment variables. Empty or invalid variables are ignored.

| Key | Environment variable | Type | Default | Description |
|---|---|---|---|---|
| `app_name` | — | string | `SQL Web Manager` | Displayed name |
| `version` | — | string | `1.0.0` | Version shown in the footer |
| `default_server` | `SQLWM_DEFAULT_SERVER` | string | `localhost,1433` | Server pre-filled on the login screen |
| `allowed_servers` | `SQLWM_ALLOWED_SERVERS` | list (comma) | empty | Allowed servers. Empty = any. Accepted formats are the same as on the login screen |
| `trust_server_certificate` | `SQLWM_TRUST_SERVER_CERTIFICATE` | bool | `true` | Initial value of the certificate checkbox |
| `encrypt` | `SQLWM_ENCRYPT` | bool | `null` | `null` = driver default; `true`/`false` forces it |
| `login_timeout` | — | int (s) | `10` | Time allowed to connect |
| `query_timeout` | `SQLWM_QUERY_TIMEOUT` | int (s) | `120` | Maximum time per query |
| `max_rows` | `SQLWM_MAX_ROWS` | int | `10000` | Row cap per result set; on a paginated query it applies **per page** (maximum page size) |
| `export_max_rows` | `SQLWM_EXPORT_MAX_ROWS` | int | `0` | Row cap for the complete export (CSV/JSON/Excel of a paginated result); `0` = no cap |
| `csv_separator` | `SQLWM_CSV_SEPARATOR` | string | `;` | Default CSV separator: `;`, `,`, `|` or `tab`. Users can change it on screen (their choice is remembered in the browser); any other value falls back to `;` |
| `session_timeout` | `SQLWM_SESSION_TIMEOUT` | int (s) | `3600` | Inactivity before sign-out |

Booleans accept `true/false`, `1/0`, `yes/no`, `on/off`. For lists, separate with commas: `SQLWM_ALLOWED_SERVERS=srv1:1433,srv2`.

Example `config/config.local.php` (not versioned):

```php
<?php
return [
    'default_server'  => 'myserver:1433',
    'allowed_servers' => ['myserver:1433'],
    'max_rows'        => 5000,
];
```

## Running

### Docker (recommended)

```bash
docker compose up -d
```

- Default port `8080`; change it with `SQLWM_PORT` (see `.env.example`; copy it to `.env`).
- The container has a `healthcheck` on `/login` and `no-new-privileges`.
- Demo SQL Server: `docker compose --profile demo up -d`; first set `MSSQL_SA_PASSWORD` (a strong password of your own) in `.env`; there is no default password. On the login screen use server `mssql`, user `sa` and that password. Its port is not published.
- The image only copies `app/`, `config/` and `public/`. To change the configuration, use environment variables.

### Local PHP (development)

```bash
php -S localhost:8080 -t public
```

Requires `pdo_sqlsrv` in the local PHP. The built-in server serves the front controller with no extra configuration.

### Apache and Nginx

Point the document root at `public/`. `public/.htaccess` redirects anything that is not a file or folder to `index.php` (requires `mod_rewrite` and `AllowOverride All`). A complete example is in `docker/apache.conf`. On Nginx, use `try_files $uri /index.php$is_args$args;` with PHP-FPM (**pending:** the Nginx configuration has not been tested in this project).

### IIS, whole project in one folder

For hosts where the site cannot point at `public/`:

1. Copy the whole project into the site folder. The root `index.php` includes `public/index.php`.
2. Install the IIS **URL Rewrite** module.
3. Paste the contents of `deploy/iis-rewrite.xml` inside `<system.webServer>` in the site's `web.config`. The rules:
   - `assets/…` → `public/assets/…` (static files);
   - everything else → `index.php`, including `app/`, `config/`, `tests/`, `.env` and `*.md`, which are therefore never exposed.
4. Use a PHP 8.2+ FastCGI handler with `pdo_sqlsrv`.
5. Make sure the application pool user can write to `session.save_path`.

The application detects the subfolder (`SCRIPT_NAME`) and builds URLs from it. On IIS, when `REQUEST_URI` does not exist, it uses `HTTP_X_ORIGINAL_URL`, and it treats `HTTPS=off` as a non-secure connection.

> **Note:** without URL Rewrite the routes (`/login`, `/api/*`) do not work. Without it you would have to change the URLs to the `index.php/…` form, which the current code does not do.
>
> This has not been tested on a real IIS: only the request handling was validated, by simulating the server variables.

## Operations

### Logs

Unexpected errors are written with `error_log` (`Class: message in file:line`). In Docker they go to the container's stderr: `docker compose logs app`. Apache sends access and error logs to stdout/stderr.

### Sessions

They live in PHP's default storage (files in `session.save_path`). With more than one instance behind a load balancer, use sticky sessions or a shared session store.

### Updating

Just replace the files (or rebuild the image: `docker compose build && docker compose up -d`). Old sessions with an invalid format count as signed out, without an error. There is no database of its own and no migrations.

## Troubleshooting

| Symptom | Likely cause | What to do |
|---|---|---|
| Blank page or "Internal server error." right on opening | PHP older than 8.2, missing `pdo_sqlsrv` extension, or a PHP error | Check the PHP log; confirm `php -v` and `php -m` |
| `could not find driver` / `SQLSTATE[IM002]` on login | `pdo_sqlsrv` or the ODBC Driver is missing | Install the extensions and msodbcsql 17/18 |
| `SSL Provider: certificate verify failed` or a certificate error | Self-signed certificate | Check **Trust server certificate**, or set `SQLWM_TRUST_SERVER_CERTIFICATE=true` |
| `Server not allowed.` | `allowed_servers` is set and does not include the server entered | Add the server (same `host,port` form) or clear the list |
| `Invalid server name.` | Format other than `host`, `host\instance`, `host,port`, `host:port` | Fix the server text |
| Login over HTTP "does not stick" (returns to the login) on IIS | Cookie flagged as secure because of `HTTPS=off` | Update to the current version; check `Request::fromGlobals` |
| 404 on `/login`, `/api/...` on IIS/Apache | Rewrite missing | Install/enable URL Rewrite (IIS) or `mod_rewrite` + `AllowOverride All` (Apache) |
| CSS and JS do not load in a subfolder | `assets/` not mapped to `public/assets/` | Apply `deploy/iis-rewrite.xml` (IIS) or point the document root at `public/` |
| `Invalid CSRF token. Reload the page.` | Expired session, blocked cookies, or the site in an iframe/another domain | Reload the page and sign in again |
| `Session expired.` while using the editor | Inactivity longer than `session_timeout` | Sign in again |
| Result "limited to the first N rows" | AUTO TOP or `max_rows` (on a result that is not paginated) | Raise AUTO TOP, or `max_rows` (the server cap) |
| Complete export is slow or saturates the server | A paginated result exports with no cap | Set `SQLWM_EXPORT_MAX_ROWS`; review `query_timeout` |
| Query fails on timeout | Exceeded `query_timeout` | Optimize the query or raise `SQLWM_QUERY_TIMEOUT` |
| **View DDL** says "Definition unavailable" | Encrypted or nonexistent object, or no `VIEW DEFINITION` | Grant the permission to the login |
| The **Excel** button warns about the row limit | More than 1,048,575 rows | Export as CSV |
