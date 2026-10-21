# Architecture decisions (ADRs)

Short records of the decisions that shape the project. Format: context, decision, alternatives, consequences.

| # | Decision | Status |
|---|---|---|
| [0001](0001-no-composer-no-build.md) | No Composer and no front-end build | Accepted |
| [0002](0002-credentials-in-session.md) | Credentials in the PHP session and a new connection per request | Accepted |
| [0003](0003-client-side-xlsx.md) | Client-side export and an in-house `.xlsx` generator | Accepted (partly superseded by [0004](0004-server-side-pagination.md) for paginated results) |
| [0004](0004-server-side-pagination.md) | Server-side pagination with `OFFSET/FETCH` and streamed full export | Accepted |

> The ADRs record what is in the code and the README. Motivations marked *inferred* are not written down in the repository; confirm them with the team before treating them as history.
