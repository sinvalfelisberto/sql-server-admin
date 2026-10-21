# Changelog

Based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). The project does not publish numbered releases yet; `config/config.php` declares `version = 1.0.0`.

## [Unreleased]

### Added
- Screenshots in the README (`docs/images/`).
- The MIT License (`LICENSE`).
- **Import data…** in the database context menu: load a CSV or JSON file into an existing table (column mapping, empty values as NULL) or a new table (types inferred and editable), in atomic batches with progress and resume, and an **Import completed** window at the end. New `POST /api/import`, `public/assets/js/import.js`, `DataImporter` and tests (`tests/import.test.js`).
- `POST /api/columns` also reports `identity` and `computed` for each column.
- A show/hide button on the login screen's **Password** field (new `public/assets/js/login.js`; without JavaScript the button stays hidden and the field remains a password field).
- The results grid and the exports (Copy, CSV, Excel, JSON) show exact decimal columns (`decimal`, `numeric`, `money`, `smallmoney`) in the SQL standard with at least two decimal places: `30000.0000` becomes `30000.00` and `.0000` becomes `0.00`; significant digits are never cut. In Excel these cells are numbers formatted `0.00`.
- Selectable CSV separator (`;`, `,`, tab or `|`) in the top bar, next to **AUTO TOP**, remembered in the browser; default set by the new `csv_separator` setting (`SQLWM_CSV_SEPARATOR`). `POST /api/export` accepts a `separator` field.
- Server-side pagination: a single `SELECT` is returned in pages of 200 rows (`OFFSET … FETCH`), with **‹ Previous** / **Next ›**; scripts that cannot be paginated behave as before.
- `POST /api/export`: streamed CSV and JSON, and the complete result for Excel, by re-running the query; new setting `export_max_rows` (`SQLWM_EXPORT_MAX_ROWS`).
- Excel export as a real `.xlsx` file (own generator, no dependencies) with a bold header, frozen first row, auto filter and column widths; tests in `tests/xlsx.test.js`.
- Exported files are named `schema.table_ddMMyyyy.ext` (CSV, Excel and JSON).
- Support for `PRINT` and server warning messages in the **Messages** tab; the tab opens by itself when the execution returns no result sets.
- IIS deployment with the whole project in one folder: root `index.php` and `deploy/iis-rewrite.xml`.
- Full documentation in `docs/`.

### Changed
- **SELECT TOP 100** (context menu and double-click on a table) now lists every column, one per line, instead of `*`.
- The login screen now pre-fills **Server** with `localhost,1433` (the `default_server` setting; override it in `config/config.local.php` or with `SQLWM_DEFAULT_SERVER`).
- The **Excel** button no longer generates an HTML `.xls` table; it generates `.xlsx`. Above 1,048,575 rows it warns you to use CSV.
- `Request` reads the original URL from `HTTP_X_ORIGINAL_URL` when `REQUEST_URI` is missing (IIS).
- The application interface (labels, messages, errors, generated SQL comments, number and date formats) and the documentation are now in English (US). Previously both were in Brazilian Portuguese. The CSV separator defaults to `;`. Source code comments, configuration comments (`config.php`, `.env.example`, `docker-compose.yml`, `Dockerfile`), test names and test output, and the PRD are in English (US) as well.

### Fixed
- The SQL Server demo password that was the default value in `docker-compose.yml` was removed; `MSSQL_SA_PASSWORD` is now required in `.env` for the `demo` profile.
- On IIS, `HTTPS=off` was treated as a secure connection, marking the session cookie as `Secure` over HTTP.

### Security
- The Git history was rewritten (`git filter-repo`) to erase that password from every commit; the hashes of all commits after the initial commit changed. Existing clones need `git fetch origin && git reset --hard origin/main`.

### Removed
- The HTML spreadsheet generator (`toHtmlTable`).
