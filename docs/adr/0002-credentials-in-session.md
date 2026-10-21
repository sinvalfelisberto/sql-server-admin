# 0002 – Credentials in the PHP session and a new connection per request

- **Status:** accepted
- **Context:** the application uses each user's own SQL Server authentication, not a service account. The credentials must be reused across requests without being sent back to the browser.
- **Decision:** the password stays in the PHP session, on the server (`Session`), and every API request opens a new `PDO` connection through `ConnectionFactory`, selecting the database with `USE [database]`. The controller calls `Session::release()` before querying the database so the session is not locked.
- **Alternatives considered:** persistent connections; a token or credential kept in the browser; a single service account. *Inferred:* persistent connections do not fit per-user credentials, and keeping the password on the client would expose the secret.
- **Consequences:**
  - (+) The password is never sent to the browser; `HttpOnly`/`SameSite=Strict` cookie and CSRF token.
  - (+) SQL Server enforces each user's permissions; there is no application "superuser".
  - (−) The cost of opening a connection on every call (login timeout of 10 s by default).
  - (−) The password sits in clear text in the server's session storage; protect `session.save_path` (see [Security](../security.md)).
  - (−) SQL Server transactions and session variables do **not** persist between executions.
