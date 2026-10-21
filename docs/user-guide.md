# User guide

For people who query and administer a SQL Server from the browser.

## Connect

1. Open the application (for example, `http://localhost:8080`).
2. Fill in:
   - **Server:** `host`, `host\instance`, `host,port` or `host:port` (for example, `myserver:1433`). `host:port` is converted to `host,port`.
   - **User** and **Password:** SQL Server authentication login. Windows authentication is not supported. The eye button at the end of the **Password** field shows or hides what you typed.
   - **Trust server certificate:** check it if the server uses a self-signed certificate (Driver 18 encrypts by default).
3. Click **Connect**.

The application actually tests the credentials and opens on the login's default database. If the administrator restricted the servers, any other host returns **Server not allowed.** Wrong credentials show the SQL Server message, and the login takes about half a second to respond.

The session expires after a period of inactivity (default: 1 hour). When it expires, the next action takes you back to the login screen. The sign-out button (right side of the top bar) ends the session.

## The screen

| Area | Purpose |
|---|---|
| Top bar | Database selector, **Run**, clear editor, **History**, **Favorites**, save `.sql`, **AUTO TOP**, **CSV separator**, user@server, theme, sign out |
| Object Explorer (left) | Tree of databases and objects. Drag the divider to resize it; double-click it to reset the width |
| Query tabs | Several queries at once; **+** opens a new one |
| Editor | Syntax highlighting and line numbers |
| Results / Messages | Data grid or server messages |
| Status bar | Current database, rows, execution time and version |

Tabs, history, favorites, theme, the selected database and AUTO TOP are stored in the browser (`localStorage`), not on the server.

## Object Explorer

- Each database has the groups **Tables**, **Views**, **Procedures** and **Functions**. Objects and columns load on demand when you expand a node.
- System databases are marked `SYS`. Only databases the login can access are listed.
- The **Filter objects...** field filters the tree. Each group shows at most 500 objects; beyond that you see `… N more (refine the filter)`. The refresh button at the top reloads everything.
- Clicking a column inserts its name into the editor.
- Double-clicking a table runs `SELECT TOP` on it, in a new tab, listing every column of the table (the row count comes from AUTO TOP).

### Context menu (right click)

**Tables and views**

| Item | What it does |
|---|---|
| **SELECT TOP 100** | Opens a new tab and runs it. The query lists each column of the table, one per line, instead of `*` (as in SSMS) |
| **SELECT \* (no TOP)** | Opens a new tab with `SELECT *`. If the table has more than 100,000 rows, it asks for confirmation first |
| **View DDL** | Shows the `CREATE TABLE` script (columns, identity, computed columns, defaults, PK/UNIQUE, indexes, foreign keys and checks). For views, it shows the definition |
| **Table information** | Approximate rows, space (reserved, data, indexes, unused), indexes and foreign keys |
| **Copy qualified name** / **Copy plain name** | Copies `[schema].[name]` or just the name |
| **Refresh** | Reloads only that node |

**Databases:** **New query** (opens a tab with `USE [database]`), **View DDL (database script)** (collation and active options; the MDF/LDF files are not included), **Import data…** (see [Import data](#import-data)) and **Refresh**.

**Procedures and functions:** **View DDL** (the object definition), copy name and **Refresh**. If the definition is unavailable, the script says so: the object does not exist, is encrypted, or you lack the `VIEW DEFINITION` permission.

## Write and run queries

- **Run:** the **Run** button, `F5` or `Ctrl+Enter` (`Cmd+Enter` on macOS).
- With text selected, only the selection runs; with no selection, the whole script runs.
- `Tab` inserts two spaces in the editor (it does not move focus).
- **GO** on its own line separates batches, as in SSMS (it accepts `GO 5` and a trailing comment). Each batch runs in sequence. The `GO` count is ignored: `GO 5` does not repeat the batch.
- Multiple result sets appear as buttons (chips) above the grid.
- If a batch fails, earlier batches (separated by `GO`) and result sets already read are kept, and the error message appears in the **Messages** tab. Compilation errors take down the whole batch.
- The database used for execution is the one in the top bar selector.

### AUTO TOP

With **AUTO TOP** checked, the result is limited to the number of rows entered next to it (default: 1000) **in total**, across all pages. If the result was cut, the **Messages** tab says so. **SELECT \* (no TOP)** ignores AUTO TOP.

### Server-side pagination

When what you run is **a single `SELECT`** (it may have `WHERE`, `JOIN`, `GROUP BY`, `ORDER BY` and CTEs), the server returns only one page at a time (200 rows by default, adjustable in **Show**), and the grid shows **‹ Previous** / **Next ›** and `Rows 201 to 400 (more available)`. This way neither the server nor the browser ever loads the whole result at once.

- **Each page re-runs the query** (with `OFFSET … FETCH`). On large tables, pages further along cost more.
- **Without `ORDER BY`, the order between pages is not guaranteed** by SQL Server: rows can repeat or go missing if the data changes. For stable pagination, use `ORDER BY` on a unique column.
- **Sorting by header and Filter apply only to the current page.** To sort everything, use `ORDER BY` in the query.
- What counts is the exact `SELECT` you ran, not a slice of the result. If text is selected, only that text runs.
- **Not paginated** (the whole result comes back, limited by AUTO TOP and `max_rows`, as before): scripts with `GO` or several statements, `SELECT … INTO`, `SELECT TOP …` in the main statement, `FOR XML/JSON`, `OPTION (…)`, assignment to a variable, `UNION`/`DISTINCT` without `ORDER BY`, `EXEC` and any statement that is not a `SELECT`. If the server rejects the paginated version, the query simply runs normally.

### Messages and `PRINT`

The **Messages** tab shows the `PRINT` text, server warnings, `(N row(s) affected)`, the error (with the `Msg` number), the total time and the row count of each result. When the execution returns no grid at all (for example, a script that only has `PRINT`), this tab opens by itself.

> `PRINT` output appears together in the tab, in the order the server sent it, with no position relative to the grids.

### History and favorites

- **History:** the last 50 queries run (no repeats). Click one to reload it into the editor; there is also **Clear history**.
- **Favorites:** **★ Add current query to favorites** asks for a name; click a favorite to load it into the editor and use the item's remove button to delete it.
- **Save:** downloads the editor as `.sql` (the name comes from the tab title).

## Results grid

- **Sort:** click the column header: 1st click ascending, 2nd descending, 3rd removes the sort. Switching result sets clears the sort.
- **Filter:** the **Filter** field shows only the rows where any column contains the text (case-insensitive); it works on the data already loaded.
- **Show:** rows per page (10 to 1000); the default is 200. On a result paginated on the server, changing the value reloads the first page. On the others, pagination is display-only.
- `NULL` cells appear as a highlighted **NULL**; numeric columns are right-aligned; exact decimals use the format described in [Decimal number format](#decimal-number-format); binary columns appear as `0xHEX`.
- The grid footer shows the range displayed, the total and the **‹ Previous** / **Next ›** navigation.
- The SQL type of the column appears as a tooltip when you hover over the header.

## Export

The grid buttons export the result of the query:

- **Result paginated on the server:** the file contains the **complete query result**, not just the visible page or the AUTO TOP slice. The server runs the query again and streams the rows. The grid's sorting and filter are **not** applied: the order of the query is used (use `ORDER BY`). The download only starts when the server finishes opening the query; large queries can take a while and have no row cap unless the administrator sets `export_max_rows`.
- **Other results (already fully loaded):** the file contains **all loaded rows**, with the grid's sorting and filter applied.
- **Copy** copies only the current page when the result is paginated.

| Button | Result |
|---|---|
| **Copy** | Copies to the clipboard, with a header, tab-separated (pastes straight into spreadsheets); line breaks inside cells become spaces |
| **CSV** | UTF-8 with BOM, using the separator chosen in **CSV separator** (default `;`; see below) |
| **Excel** | A real `.xlsx` file: bold header, frozen first row, auto filter and adjusted column widths |
| **JSON** | A list of objects; columns with repeated names get a suffix so they do not overwrite each other |

### CSV separator

The **CSV separator** selector, in the top bar next to **AUTO TOP**, sets the delimiter of the file: `;` (semicolon), `,` (comma), **Tab** or `|` (pipe). Your choice is remembered in the browser. The initial value comes from the administrator's `csv_separator` setting (default `;`).

- Excel in the English (US) locale expects commas: pick `,` there. A `;` file can open in a single column.
- Cells containing the chosen separator, quotes or line breaks are wrapped in quotes.
- The choice applies to both kinds of CSV export (from the grid and from the server for paginated results).

### Decimal number format

In the results grid and in every export (**Copy**, **CSV**, **Excel** and **JSON**), exact decimal columns (`decimal`, `numeric`, `money` and `smallmoney`) are shown in the SQL standard: a dot as the decimal separator and **at least two decimal places**. The data itself is not changed; sorting uses the real values.

| Raw value from SQL Server | Grid and export |
|---|---|
| `30000.0000` | `30000.00` |
| `.0000` | `0.00` |
| `-.5000` | `-0.50` |
| `0.5` | `0.50` |
| `12.3456` | `12.3456` |

- Trailing zeros beyond the second decimal place are dropped, but **significant digits are never cut**: `12.3456` and `0.0125` keep all their digits.
- Values with no fractional part (for example `numeric(10,0)`), `float`/`real` and integer columns are left untouched.
- In Excel, cells with two decimal places are numbers with the `0.00` format.

### File name

The name follows the pattern `schema.table_ddMMyyyy.extension`, for example `Sales.Orders_21102026.xlsx`.

- The table is the one in the **first `FROM`** of the query that was run. For `db.schema.table`, only the schema and table are used.
- With no schema in the query (`FROM Customers`), the name uses `dbo`. This may not match the real schema if your user has a different default schema.
- With no `FROM` (for example, `SELECT 1`), the name is `result_ddMMyyyy.extension`.
- With a `JOIN` or subquery, the first `FROM` wins.
- Characters that are invalid in a file name become `_`.

### Excel specifics

- Numeric columns become numbers. Values with more than 15 digits stay as text, so no precision is lost.
- Text longer than 32,767 characters is cut off (Excel's limit).
- Above 1,048,575 rows the button warns you to use CSV. On a paginated result, the browser receives the complete result to build the file, so very large results consume browser memory; prefer CSV in those cases.
- Nulls become empty cells.
- The worksheet is named **Result**.

## Import data

Right-click a database and choose **Import data…** to load a CSV or JSON file into a table of that database.

1. **File:** pick a `.csv`, `.tsv`, `.txt` or `.json` file. The dialog shows the number of rows and columns.
   - **CSV:** choose the **Delimiter** (`;`, `,`, **Tab**, `|`, or **Auto-detect**, the default) and whether the **first row contains column names**. Quotes (`"a;b"`, `""` for a literal quote) and line breaks inside quoted cells are supported. Without a header, columns are named `column_1`, `column_2`…
   - **JSON:** an **array of objects**, for example `[{"id": 1, "name": "Ana"}]`. The columns are the keys of all objects, in order of appearance. Nested objects and arrays are stored as JSON text. Anything else (an object, an array of arrays) is rejected with a message.
2. **Destination:** choose one of:
   - **Existing table:** each file column gets a selector for the target column (or **(skip)**); columns with the same name (ignoring case, spaces and punctuation) are mapped automatically. Identity, computed and `rowversion` columns are not listed, because they cannot be inserted. The dialog warns about `NOT NULL` columns you did not map.
   - **New table:** type a schema (default `dbo`) and a name; the table is created with one nullable column per file column. Names and types can be edited; types are suggested from the data (`int`, `bigint`, `decimal(38,s)`, `bit`, `date`, `datetime2`, otherwise `nvarchar(n)`/`nvarchar(max)`).
3. **Treat empty values as NULL** (on by default): empty cells and missing JSON keys become `NULL`.
4. Click **Import**. A progress bar shows the rows sent. When it finishes, an **Import completed** window summarizes the result (rows imported, destination, whether the table was created, file, number of columns and time) and offers **View data (SELECT TOP 100)** to open the table in a new tab.

**How it behaves**

- Rows are **appended**; nothing is updated or deleted, and there is no "truncate first" option.
- Values travel as text and SQL Server converts them to each column's type. Prefer ISO dates (`2026-10-09`, `2026-10-09 11:11:43`); other formats depend on the server's language settings. Numbers must use a dot as the decimal separator.
- The file is read and parsed in the browser, up to 1,000,000 rows. Rows are sent in batches of up to 1,000 rows (fewer for wide files). **Each batch is atomic**: if a row fails (for example, a value that does not convert), nothing from that batch is saved.
- If a later batch fails, batches already sent **stay in the table**. The message tells you which rows failed and how many were imported; fix the cause and use **Resume from row N** to continue (the mapping is locked meanwhile). If the very first batch fails, nothing was created or inserted and you can adjust and try again.
- Closing the dialog while it runs stops after the current batch.
- It needs `INSERT` permission on the table (and `CREATE TABLE` for a new one). A new table appears in the Object Explorer when the import finishes.

## Not available yet

The PRD describes, but **does not implement**: export as a T-SQL script with `INSERT INTO`, importing other formats (only CSV and JSON are supported), updating or truncating tables during an import, read-only mode and the administration and monitoring features (FR-06). The application runs arbitrary SQL with the permissions of the login provided.

## Shortcuts

| Shortcut | Action |
|---|---|
| `F5` or `Ctrl+Enter` | Run |
| Double-click a table | `SELECT TOP` in a new tab |
| Click a column | Inserts its name into the editor |
| Right click | Object Explorer context menu |
| `Esc` | Closes the context menu |
| Arrows ↑ ↓ | Move through the context menu |
| Arrows ← → on the divider | Resize the Object Explorer; `Home` resets |
