# SQL Web Manager documentation

A web client for SQL Server, SSMS-style, built with plain PHP, HTML, CSS and JavaScript (no Composer and no front-end dependencies).

## Where to start

| I want to… | Read |
|---|---|
| Install and open the application | [Root README](../README.md) (quick start) |
| Use the tool (login, explorer, editor, export) | [User guide](user-guide.md) |
| Configure, deploy (Docker, Apache, IIS) and troubleshoot | [Configuration and operations](configuration.md) |
| Understand the risks and the protections | [Security](security.md) |
| Call the HTTP API | [API reference](api.md) |
| Understand how the code is organized | [Architecture](architecture.md) |
| Change or extend the code | [Development guide](development.md) |
| Learn why something was done a certain way | [Architecture decisions (ADRs)](adr/README.md) |
| See the change history | [CHANGELOG](../CHANGELOG.md) |

The [PRD](../PRD.md) describes the product vision and roadmap. It includes items that are **not implemented yet** (see [Not available yet](user-guide.md#not-available-yet)).

## Conventions in this documentation

- The code is the source of truth. When it disagrees with the README or the PRD, the code wins.
- Paths are relative to the project root.
- Messages and UI labels appear in **bold**, exactly as on screen (the interface is in English, US).
