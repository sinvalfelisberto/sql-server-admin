# 0004 – Server-side pagination with `OFFSET/FETCH` and streamed full export

- **Status:** accepted
- **Context:** the grid paginated only in the browser: the server read and sent up to `max_rows` rows (default 10,000) on every execution. The request was to reduce that load by showing 200 records at a time while keeping the export complete.
- **Decision:**
  - A single `SELECT` is rewritten with `OFFSET … FETCH` (`paginate_sql`) and every page is a new execution (`QueryExecutor::executePage`). One extra row is requested to know whether a next page exists, with no `COUNT(*)`.
  - The rewrite is conservative: it only applies to a single-statement `SELECT`/CTE, after masking comments, strings and parentheses. Any other form (several batches, `TOP`, `INTO`, `FOR JSON`, `UNION`/`DISTINCT` without `ORDER BY`, `EXEC`…) or a server rejection falls back to the normal `execute`.
  - AUTO TOP becomes the **total** cap summed across pages; `max_rows` applies per page.
  - Export (`POST /api/export`) re-runs the query without pagination and streams CSV/JSON; Excel receives the `raw` format and the browser builds the `.xlsx`. Grid sorting and filtering are not applied.
- **Alternatives considered:**
  - *Driver scrollable cursor (`SQLSRV_CURSOR_SCROLL`):* needs no query rewrite, but materializes the result on the server for each page. *Inferred:* rejected because it does not reduce the load.
  - *Wrapping the query in `SELECT * FROM (…)`:* breaks with an inner `ORDER BY`, unnamed or duplicate columns, and CTEs.
  - *Keep client-only pagination and lower `max_rows`:* simpler, but the server still reads the whole result up to the cap.
- **Consequences:**
  - (+) The server and the browser handle one page at a time; export does not build the file in server memory.
  - (+) No need to count the total number of rows.
  - (−) Every page re-runs the query; without `ORDER BY`, the order between pages is not guaranteed.
  - (−) Sorting by header and filtering apply only to the current page.
  - (−) Export has no cap by default and runs with no PHP time limit (`export_max_rows` mitigates this).
  - (−) The list of rejected forms is a heuristic (textual analysis, not a T-SQL parser); new or rare forms may fall back to the normal flow.
