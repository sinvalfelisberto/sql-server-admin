# Development guide

## Set up the environment

There is no `composer install` and no `npm install`. You need PHP 8.2+ (with `pdo_sqlsrv` to run the application) and, for the `.xlsx` test, Node.js.

```bash
php -S localhost:8080 -t public      # application (needs a SQL Server to sign in)
docker compose --profile demo up -d  # alternative: app + demo SQL Server
```

## Tests

```bash
php tests/run.php          # pure functions and value objects: 98 checks, no database
node tests/xlsx.test.js    # .xlsx generator: 11 tests, no database or browser
node tests/import.test.js  # CSV/JSON reading for the import: 12 tests, no database or browser
```

- `tests/run.php` is a custom runner (the `check(name, actual, expected)` function) that covers `Result`, `pipe`/`partial`/`take`/`pipeline`, `Config::fromEnv`, the functions in `functions.php`/`ddl.php` and `Connection::fromLogin`. It needs no database.
- `tests/import.test.js` covers the delimiter detection, the CSV parser (quotes, line breaks, BOM), JSON reading, column type inference and table name suggestions.
- `tests/xlsx.test.js` reads the generated ZIP and checks the signatures, the CRC-32 and the contents of the XML files.
- **There are no automated tests** for the controllers, `QueryExecutor`, `CatalogRepository` (they depend on SQL Server) or `app.js`. Validate those parts manually, for example with Docker's `demo` profile.

Before opening a PR: run the three commands above and `php -l` on the changed files.

## Code conventions

The project combines three styles, each where it pays off most (see the [architecture](architecture.md)):

- **OO** where there is state or side effects: `Session`, `Router`, `QueryExecutor`, repositories, controllers.
- **Functional** for domain rules: pure functions in `app/Models/functions.php` and `ddl.php`, `Result` (Either), `pipe`, `pipeline`.
- **Immutability:** `Request`, `Response`, `Connection`, `QueryResult`, `Config` and `Result` are `readonly`.
- Every PHP file starts with `declare(strict_types=1);`.
- Expected errors (validation) are values (`Result`); exceptions are for the unexpected.
- Template output always goes through `$e(...)`.
- Everything is in English (US): user-facing text (UI, messages, generated SQL comments), source code comments, test names, configuration comments, identifiers and documentation.
- No autowiring: register every dependency in `Application::container()`.

## Recipes

### Add an API endpoint

1. Declare the route in `app/routes.php`: `['POST', '/api/my-action', [MyController::class, 'action'], ['auth.api', 'csrf']]`.
2. Create the method on the controller. If it queries the database, read the connection with `$this->session->connection()` and call `$this->session->release()` right after.
3. Put the data-access logic in a repository (`app/Models/`) and the pure rules in `functions.php`.
4. In `ExplorerController`, use `respond(fn () => [...])` to get the automatic `{ok, error}` envelope on database failures.
5. Register the new controller and dependencies in `Application::container()`.
6. Write tests for the pure functions in `tests/run.php`.
7. Document the route in [`docs/api.md`](api.md).

The `Router` matches the **exact** path; there are no URL parameters. Send the data in the JSON body.

### Add a setting

1. Add the key and its default to `config/config.php`.
2. If it should have an environment variable, add a line to `Config::ENV_MAP` (name, key, type `string|int|bool|list`).
3. Pass the variable through in `docker-compose.yml` and document it in `.env.example`.
4. Update the table in [Configuration](configuration.md#settings).

### Add an export format

In `app.js`, the `// ---------- Export ----------` section has `exportRows()` (data), the converters (`toCsv`, `toJson`, `XlsxWriter.build`) and `exportName(sql, ext)` (file name in the `schema.table_ddMMyyyy.ext` pattern). Create the converter, the button in `app/Views/editor.php` and the listener in `bind()` (next to `btn-csv`/`btn-xls`/`btn-json`).

### Work on the `.xlsx` generator

`public/assets/js/xlsx.js` is a lean UMD module: in the browser it creates `XlsxWriter`; in Node it does `module.exports`. For numeric cells it uses the column's SQL type (`NUMERIC_TYPE`) and only writes a number when there is no precision loss (up to 15 digits). Changes must keep `node tests/xlsx.test.js` passing; to validate end to end, open the generated file in Excel or LibreOffice.

## Structure of the generated DDL

`build_create_table` assembles, in this order: `CREATE TABLE` (columns, identity, computed columns, defaults, PK/UNIQUE and checks), `CREATE INDEX` for the other indexes and `ALTER TABLE … ADD CONSTRAINT … FOREIGN KEY`, each block ending in `GO`. Only outgoing foreign keys (`direction = out`) are included.

## Contributing

The project is released under the [MIT License](../LICENSE); contributions are accepted under the same terms. **Pending:** the repository defines no `CONTRIBUTING.md` or branch policy. The history uses descriptive commit messages in English (for example, "Add XLSX export functionality and corresponding tests").
