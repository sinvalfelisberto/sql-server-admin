# HTTP API reference

The API is internal: it exists for the application's own front end and is **not a stable public API**. Routes, fields and formats may change between versions. This page documents what the code does today (`app/routes.php` and the controllers).

## Conventions

- **Base:** the application root (for example `http://localhost:8080` or `https://server/sql-admin`). The front end reads the base from `<meta name="base-url">`.
- **Authentication:** the `sqlwm` session cookie (`HttpOnly`, `SameSite=Strict`, `Secure` when the request is HTTPS). There are no API tokens.
- **CSRF:** every `POST` requires the session token, in the `X-CSRF-Token` header (API) or the `csrf` form field (login). The token is in the HTML page served after login.
- **Format:** API requests send JSON (`Content-Type: application/json`); API responses are UTF-8 JSON with `Cache-Control: no-store`. An invalid JSON body is treated as `{}`.
- **Security headers** on every response: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`.
- **Database:** the `database` field of each call selects the database (via `USE`); empty keeps the login's default database.

### Response envelope

Success: `{ "ok": true, ... }`. Database failure: HTTP 200 with

```json
{ "ok": false, "error": { "message": "Invalid object name 'x'.", "number": 208, "state": "42S02" } }
```

`number` is the SQL Server error code and `state` the SQLSTATE; both can be `null`. The `[Microsoft][ODBC Driver 18…]` prefix is stripped from the message.

### Protocol errors

| HTTP | When | Body |
|---|---|---|
| 401 | No valid session (API) | `{"ok":false,"error":{"message":"Session expired. Please sign in again."}}` |
| 403 | Missing or invalid CSRF token | API: `{"ok":false,"error":{"message":"Invalid CSRF token. Reload the page."}}`; pages: HTML "Session expired." |
| 404 | Unknown path | HTML `Page not found.` |
| 405 | Method not allowed (`Allow` header) | HTML `Method not allowed.` |
| 500 | Unexpected exception | `{"ok":false,"error":{"message":"Internal server error."}}`; the detail goes only to the PHP log |

## Page routes

| Method | Path | Middleware | Description |
|---|---|---|---|
| GET | `/` | `auth.page` | Editor. Without a session, redirects (302) to `/login` |
| GET | `/login` | `guest` | Login form. If already signed in, redirects to `/` |
| POST | `/login` | `guest`, `csrf` | Validates and opens the session. Success: 302 to `/`. Failure: 200 with the form and the error message |

`POST /login` fields (form): `csrf`, `server`, `user`, `pass`, `trust` (present = trust the certificate). Failure messages: `Invalid server name.`, `Server not allowed.`, `Enter the user name.` or the SQL Server message.

## API routes

All of them: `POST`, JSON, with the `auth.api` and `csrf` middleware.

### `POST /api/logout`

Ends the session. Response: `{ "ok": true }`.

### `POST /api/databases`

Lists the accessible databases and the server version. No parameters.

```json
{
  "ok": true,
  "current": "master",
  "databases": [ { "name": "master", "system": true }, { "name": "Sales", "system": false } ],
  "version": "16.0.4135.4"
}
```

Databases with `database_id <= 4` are system databases. Only databases the login can access are listed.

### `POST /api/objects`

User objects in a database (tables, views, procedures and functions).

| Field | Type | Description |
|---|---|---|
| `database` | string | Database to query |

```json
{ "ok": true, "objects": [ { "schema": "dbo", "name": "Customers", "type": "U" } ] }
```

`type`: `U` table, `V` view, `P` procedure, `FN` / `IF` / `TF` functions (scalar, inline, table-valued). Microsoft-shipped objects (`is_ms_shipped`) are left out.

### `POST /api/columns`

Columns of a table or view; for procedures and functions, it returns the parameters.

| Field | Type | Description |
|---|---|---|
| `database`, `schema`, `name` | string | Object |
| `type` | string | Object type (`U`/`V` returns columns; other values, parameters) |

```json
{ "ok": true, "columns": [ { "name": "Id", "type": "int", "nullable": false, "pk": true } ] }
```

`type` is the readable label (`nvarchar(100)`, `decimal(18,2)`, `varchar(max)`). Parameters come with `nullable: true` and `pk: false`.

### `POST /api/ddl`

Script for an object. Table (`type = "U"`): `CREATE TABLE` with columns, identity, computed columns, defaults, keys, checks, indexes and foreign keys. Other types: the definition obtained with `OBJECT_DEFINITION`.

Fields: `database`, `schema`, `name`, `type`.

```json
{ "ok": true, "ddl": "CREATE TABLE [dbo].[Customers] (...)" }
```

If the object does not exist, the text comes back as a SQL comment (`-- Table ... not found.` or `-- Definition unavailable ...`), still with `ok: true`.

### `POST /api/db-ddl`

Database creation script with collation and active options. Field: `database`. Response: `{ "ok": true, "ddl": "..." }`. Nonexistent database: `-- Database not found.`

### `POST /api/table-info`

Metadata for a table or view. Fields: `database`, `schema`, `name`.

```json
{
  "ok": true,
  "rows": 1520,
  "reservedKb": 400, "dataKb": 240, "indexKb": 96, "unusedKb": 64,
  "indexes": [
    { "name": "PK_Customers", "type": "CLUSTERED", "unique": true, "primaryKey": true,
      "uniqueConstraint": false, "filter": null,
      "keys": [ { "name": "Id", "desc": false } ], "included": [] }
  ],
  "foreignKeys": [
    { "name": "FK_Orders_Customers", "direction": "in", "parentTable": "dbo.Orders",
      "refSchema": "dbo", "refTable": "dbo.Customers", "refName": "Customers",
      "onDelete": "NO ACTION", "onUpdate": "NO ACTION",
      "columns": ["CustomerId"], "refColumns": ["Id"] }
  ]
}
```

`rows` is approximate (it comes from `sys.dm_db_partition_stats`) and is `null` when the view/object has no statistics. `direction`: `out` = this table references another; `in` = it is referenced by another.

### `POST /api/execute`

Runs a T-SQL script.

| Field | Type | Default | Description |
|---|---|---|---|
| `sql` | string | — | Script. `GO` on its own line separates batches |
| `database` | string | login's database | Database the script runs in |
| `limit` | int | 0 | Maximum rows **per result set**. `0` or absent = the server cap; values above `max_rows` are reduced to `max_rows`. With `page`, it becomes the **total** cap (AUTO TOP) across pages; `0` = no cap |
| `page` | int | — | Requests server-side pagination (1 = first page). Only applies to a single paginable `SELECT`; otherwise the response is the normal one, without the `page` field |
| `page_size` | int | 200 | Rows per page; capped at `max_rows` |

```json
{
  "ok": true,
  "error": null,
  "sets": [
    { "columns": [ { "name": "a", "type": "int" } ], "rows": [ [1] ], "truncated": false }
  ],
  "messages": [ "after" ],
  "total_rows": 1,
  "elapsed_ms": 12,
  "page": null
}
```

- `rows` is a list of lists, in the order of `columns`. Binary values become `"0xHEX"`. `int`, `float` and similar types come as JSON numbers; `decimal`, `money` and `bigint` come as **strings** (for example `"1.50"`, `"9007199254740993"`) so no precision is lost. `NULL` comes as `null`.
- `columns[].type` is the driver's base type name (`decimal`, `nvarchar`, `varbinary`), **without** length or precision, unlike `/api/columns`.
- `truncated: true` means the result set had more rows than the limit.
- `page` is `null` (normal result) or `{ "page": 2, "size": 200, "hasMore": true }` when the query was paginated. In that case `sets` has a single result set with at most `size` rows and `truncated` is always `false`; use `hasMore` to know whether a next page exists. The query is re-run with `OFFSET … FETCH` on every call; without `ORDER BY` the order between pages is not guaranteed. If the query is not paginable or the server rejects the paginated version, `page` is `null` and execution is the normal one.
- `messages` collects `PRINT` output, warnings and `(N row(s) affected)`. With no result set, no message and no error, the server returns `["Command(s) completed successfully."]`.
- If a batch fails, `ok` is `false` and `error` carries `message`/`number`/`state`. What was read **before the failing batch** (earlier batches separated by `GO`, or earlier result sets in the same batch when the error happens at run time) stays in `sets` and `messages`. Compilation errors, such as `SELECT 1; SELECT nonexistent_column`, take down the whole batch and nothing in it runs. The HTTP status is still 200.
- DDL statements and `INSERT` without a result set produce `(N row(s) affected)` messages; for DDL, `N` is 0.
- The maximum time of each query is `query_timeout` (default 120 s). The PHP script gets that value + 30 s.
- The script runs with no implicit transaction and **with the login's permissions**: `INSERT`/`UPDATE`/DDL really execute.

### `POST /api/export`

Exports the **complete** result of a single `SELECT`, re-running the query and streaming the response (without building the file in memory). It accepts a form (`application/x-www-form-urlencoded`, with the `csrf` field) or JSON (with `X-CSRF-Token`).

| Field | Type | Description |
|---|---|---|
| `sql` | string | A single statement; `GO` or several batches are rejected |
| `database` | string | Database |
| `format` | string | `csv` (default), `json` or `raw` |
| `filename` | string | File name without extension (`csv` and `json`); characters outside `A-Za-z0-9._-` become `_` |
| `limit` | int | Maximum rows; `0` = no cap (`export_max_rows`, if set, always prevails as the maximum) |
| `separator` | string | CSV only: `;`, `,`, `\|` or `tab`; any other value or absence uses the `csv_separator` setting (default `;`) |

- Exact decimal columns (`decimal`, `numeric`, `money`, `smallmoney`) are written with a dot and at least two decimal places (`30000.0000` becomes `30000.00`, `.0000` becomes `0.00`); extra significant digits are kept. This applies to `csv`, `json` and `raw`.
- `csv`: UTF-8 with BOM, the chosen separator (default `;`), `CRLF`, the same quoting rule as the browser export; `Content-Disposition: attachment`.
- `json`: a list of objects (repeated columns get a `_2`, `_3` suffix); `Content-Disposition: attachment`.
- `raw`: `{ "columns": ["Id"], "types": ["bigint"], "rows": [["1"], ...] }`, used by the browser to build the `.xlsx`.
- If the query fails before anything is written, the response is the normal error envelope (`{"ok":false,"error":{...}}`). An error **while** reading interrupts the file, which is left incomplete.
- PHP runs with no time limit on this route (`set_time_limit(0)`); `query_timeout` still applies to the query's execution on SQL Server.

## curl example

The API depends on a session and a CSRF token. The token is in `<meta name="csrf-token">` on any HTML page (the login page's token is for logging in; after signing in, read it again from `/`). Flow tested against SQL Server 2022:

```bash
BASE=http://localhost:8080
token() { sed -n 's/.*name="csrf-token" content="\([^"]*\)".*/\1/p'; }

# 1. Login token (and session cookie)
CSRF=$(curl -s -c cookies.txt "$BASE/login" | token)

# 2. Sign in (expected response: 302)
curl -s -b cookies.txt -c cookies.txt -o /dev/null -w '%{http_code}\n' "$BASE/login" \
  --data-urlencode "csrf=$CSRF" --data-urlencode "server=YOUR_SERVER:1433" \
  --data-urlencode "user=YOUR_USER" --data-urlencode "pass=YOUR_PASSWORD" --data-urlencode "trust=1"

# 3. Token of the signed-in session
TOKEN=$(curl -s -b cookies.txt "$BASE/" | token)

# 4. Call the API
curl -s -b cookies.txt "$BASE/api/execute" \
  -H "X-CSRF-Token: $TOKEN" -H 'Content-Type: application/json' \
  -d '{"sql":"SELECT @@VERSION AS v","database":"master","limit":10}'
```

Replace `YOUR_SERVER`, `YOUR_USER` and `YOUR_PASSWORD` for your environment; never commit real credentials.
