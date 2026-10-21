# 0003 – Client-side export and an in-house `.xlsx` generator

- **Status:** accepted. For results paginated on the server, export now re-runs the query on the server ([ADR 0004](0004-server-side-pagination.md)); the `.xlsx` is still built in the browser.
- **Context:** Excel export started as an HTML table with a `.xls` extension, which Excel opens with a format warning and without data types. The PRD (FR-05) asks for `.xlsx`.
- **Decision:** generate the `.xlsx` in the browser (`public/assets/js/xlsx.js`), writing the Office Open XML package as a *stored* (uncompressed) ZIP with its own CRC-32. CSV and JSON are also generated on the client from the current grid (already filtered and sorted).
- **Alternatives considered:** a third-party library (e.g., SheetJS), server-side generation, keeping the HTML `.xls`. Per [ADR 0001](0001-no-composer-no-build.md), dependencies were avoided; *inferred:* generating on the server would require re-running the query or sending the result back.
- **Consequences:**
  - (+) No new dependency and no extra server load; it exports exactly what the user sees.
  - (+) Numbers become numbers (up to 15 digits), bold header, auto filter and frozen row.
  - (−) No compression, so large files take more space.
  - (−) Format limits handled by hand: 1,048,575 rows and 32,767 characters per cell.
  - (−) Date/time styles are not applied (dates come out as text).
  - File name: `schema.table_ddMMyyyy.ext`, derived from the first `FROM` of the query.
