# 0001 – No Composer and no front-end build

- **Status:** accepted
- **Context:** the tool is a small SQL Server client meant to run on servers with limited access (it is deployed on IIS without control of the document root). The README defines the project as "plain PHP, HTML, CSS and JavaScript, with no front-end dependencies and no Composer".
- **Decision:** a custom PSR-4 autoloader (`app/bootstrap.php`), free functions loaded with `require`, JavaScript as static files with no bundler, and in-house utilities (`pipe`, `Result`, the `.xlsx` generator) instead of libraries.
- **Alternatives considered:** Composer with a framework (Laravel/Slim) and a bundler (Vite). *Inferred:* rejected because of the installation and maintenance cost in restricted environments.
- **Consequences:**
  - (+) Deployment is copying files; nothing to install or update.
  - (+) A minimal surface of dependencies, and of third-party vulnerabilities.
  - (−) Common features (router, container, ZIP/XLSX) are maintained here and need their own tests.
  - (−) No automatic type checking or front-end linting.
