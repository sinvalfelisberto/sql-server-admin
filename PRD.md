# Product Requirements Document (PRD)

## SQL Server Web Management Studio (PHP-SSMS Web)

### 1. Product Overview

* **Project Name (Provisional):** PHP-SSMS Web
* **Goal:** Provide a modern, lightweight, responsive web interface built in PHP for administering and running queries on Microsoft SQL Server instances (on-premises and Azure SQL), reproducing the fast experience of desktop clients such as SSMS directly in the browser.
* **Target Audience:** PHP/full-stack developers, DBAs, DevOps and support teams who work in cross-platform environments (Linux, macOS, Windows) and need fast, secure access to SQL Server instances.

---

### 2. Rationale and Opportunity

* **SSMS operating system restriction:** The original SSMS is limited to the Windows ecosystem and requires a heavy local software installation.
* **A gap in the PHP ecosystem:** Established tools such as phpMyAdmin focus primarily on MySQL/MariaDB. Generic utilities (for example, Adminer) offer limited SQL Server support, with no native support for batch delimiters (`GO`), multiple result sets, schema granularity, DBA productivity shortcuts and practical context menus.

---

### 3. User Personas

| Persona | Profile | Main Use Case |
| :--- | :--- | :--- |
| **Web Developer** | Develops on Linux/macOS with PHP/Laravel/Symfony. | Inspect data, run migration scripts, test queries and view DDL quickly in the browser. |
| **Administrator / DBA** | Responsible for integrity, performance and security. | Inspect table metadata, check indexes, audit space usage and manage access. |
| **Technical Support / L2** | Maintains and fixes operational data. | Run one-off queries (`SELECT TOP 100`), copy object names and export reports to CSV/Excel. |

---

### 4. Functional Requirements (FR)

#### FR-01: Authentication and Connection Management
* **Multiple Profiles:** Configure, test and store connection profiles (Host, Port, Default Database, Encryption/Encrypt, TrustServerCertificate).
* **Authentication Modes:**
  * SQL Server Authentication (user and password).
  * Windows Authentication / Kerberos (when applicable to the hosting environment).
  * Azure SQL Database (Service Principal / Entra ID support).
* **Secure Persistence:** Credentials are kept in an encrypted, in-memory PHP session, with no clear-text credential persistence.

#### FR-02: Object Explorer and Context Menus
* **Metadata Tree:**
  * Instance $\rightarrow$ Databases $\rightarrow$ Schemas (for example, `dbo`, `sales`, etc.).
  * Tables, Views, Stored Procedures, Functions (Scalar and Table-Valued), Triggers and Sequences.
  * On-demand lazy loading of columns, indexes, primary keys and constraints.

* **Context Menu (Right Click):**
  * **On Tables and Views:**
    * **SELECT TOP 100:** Opens a new editor tab pre-filled with the query `SELECT TOP 100 * FROM [schema].[object]` and runs it immediately.
    * **SELECT \* WITHOUT TOP:** Generates `SELECT * FROM [schema].[object]` in the editor, raising a prior alert/confirmation if the table has a high volume (preventing memory overload).
    * **VIEW DDL:** Generates and opens, in a tab/modal, the complete DDL script (`CREATE TABLE` or `CREATE VIEW`), covering columns, data types, primary/foreign keys, defaults and indexes.
    * **Table Information:** Shows a detailed metadata modal with:
      * Approximate row count (`sys.dm_db_partition_stats`).
      * Allocated disk space (data vs. indexes vs. unused space).
      * Complete list of indexes (Clustered, Non-Clustered) and related foreign keys.
    * **Copy Table Name:** Copies the qualified identifier (for example, `[dbo].[Users]`) or the plain name to the user's clipboard.
    * **Refresh:** Fires an asynchronous request to refresh only the metadata of the selected node, without reloading the whole tree.
  * **On Databases:**
    * **New Query:** Opens a new editor with the context made explicit (`USE [database]`).
    * **View DDL / Script Database:** Shows the database creation script with collation and active options.
    * **Refresh:** Reloads all child objects of the selected database.

#### FR-03: T-SQL Editor and Keyboard Shortcuts
* **Editor Interface:** Monaco Editor or CodeMirror with T-SQL syntax highlighting, line numbers and contextual autocomplete for schemas, tables and columns.
* **Query Execution Shortcuts:**
  * **`F5`**: Runs the whole script or only the block of text selected by the cursor (classic SSMS behavior).
  * **`Ctrl + Enter` (or `Cmd + Enter` on macOS)**: Runs the active query or the focused snippet (standard for web tools).
* **Batch Splitting (`GO`):** A parser that splits and runs multiple batches sequentially, honoring the native `GO` delimiter.
* **Multiple Result Sets:** Ability to render and switch between more than one result grid when the query contains multiple `SELECT` statements.
* **Messages and Diagnostics Panel:** Clear display of `PRINT` output, the number of rows affected by each command, and T-SQL warnings/errors with the line number.
* **Command History:** Local storage of the most recent queries run in the session for quick repetition and auditing.

#### FR-04: Interactive Data Grid
* **Efficient Pagination:** Server-side pagination built with `OFFSET ... FETCH NEXT`.
* **Inline Editing / CRUD:** Direct cell editing, with prior primary key validation to generate the `UPDATE` command.
* **Filters and Sorting:** Quick visual per-column filters that do not require typing SQL by hand.

#### FR-05: Data Export and Import
* **Export:** Support for CSV, JSON, Excel (`.xlsx`) and T-SQL script output with batched `INSERT INTO` statements.
* **Import:** CSV upload with visual column mapping for structured insertion.

#### FR-06: Administration and Monitoring
* **Active Sessions Monitor:** View running processes through `sys.dm_exec_requests` and `sys.dm_exec_sessions`, showing status, locks and execution time.
* **Login and Permission Management:** An interface to create and maintain Logins and assign them to predefined Database Roles (for example, `db_owner`, `db_datareader`, `db_datawriter`).

---

### 5. Non-Functional Requirements (NFR)

* **NFR-01 (Performance):** Response time for loading metadata nodes under 200 ms on a local network; asynchronous loading (lazy loading).
* **NFR-02 (Security):**
  * Mandatory protection against Cross-Site Scripting (XSS) and Cross-Site Request Forgery (CSRF).
  * Connections forced over TLS/SSL (`Encrypt=yes`).
  * **Read-Only Mode** configurable globally for connections to critical production databases.
* **NFR-03 (Compatibility and Stack):**
  * PHP version: $\ge$ 8.2 (using the official Microsoft `pdo_sqlsrv` or `sqlsrv` driver).
  * Microsoft ODBC Driver for SQL Server (versions 17 or 18).
  * Database compatibility: Microsoft SQL Server 2016, 2017, 2019, 2022 and Azure SQL Database.
* **NFR-04 (Packaging):** Official `Dockerfile` and `docker-compose.yml` with all ODBC dependencies built in.

---

### 6. Proposed Technical Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                       Web Frontend                          │
│        Tailwind CSS + Alpine.js / Vue.js 3 (Light SPA)      │
│   • Monaco Editor (Shortcuts: F5 / Ctrl+Enter)              │
│   • Custom Context Menu (SELECT TOP 100, DDL, Info, etc.)   │
└──────────────────────────────┬──────────────────────────────┘
                               │ JSON / REST API
┌──────────────────────────────▼──────────────────────────────┐
│                    PHP 8.2+ Backend                         │
│            Slim Framework 4 or Laravel Mini                 │
├─────────────────────────────────────────────────────────────┤
│  Driver: Microsoft ODBC Driver + pdo_sqlsrv                 │
└──────────────────────────────┬──────────────────────────────┘
                               │ TCP port 1433 (TLS/SSL)
┌──────────────────────────────▼──────────────────────────────┐
│              Microsoft SQL Server / Azure SQL               │
└─────────────────────────────────────────────────────────────┘
```

---

### 7. Release Roadmap

```
MVP (Phase 1)             Productivity (Phase 2)       Admin & Scale (Phase 3)
┌─────────────────────┐   ┌────────────────────────┐   ┌────────────────────────┐
│• pdo_sqlsrv auth    │   │• Right-click Context   │   │• Session monitor       │
│• Object tree        │──>│  Menu (DDL, TOP 100)   │──>│  (sys.dm_exec_*)       │
│• T-SQL editor       │   │• Table info            │   │• Login/Role management │
│• F5 and Ctrl + Enter│   │• Copy object name      │   │• Dark theme support    │
│  shortcuts          │   │• CSV/Excel export      │   │• Global Read-Only Mode │
└─────────────────────┘   └────────────────────────┘   └────────────────────────┘
```

---

### 8. Acceptance Criteria and Success Metrics

1. **Shortcut Responsiveness:** Pressing `F5` or `Ctrl + Enter` in the web editor runs the query without triggering the browser's default page reload.
2. **Context Menu Fluidity:** Right-clicking any table or view opens the menu in under 50 ms, offering `SELECT TOP 100`, `SELECT * WITHOUT TOP`, `VIEW DDL`, `Table Information`, `Copy name` and `Refresh`.
3. **Practical Deployment:** The application comes up ready to connect in a containerized environment with the single command `docker compose up -d`, in under 3 minutes.
