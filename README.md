# SQL Web Manager

Cliente web para SQL Server (estilo SSMS) em PHP, HTML, CSS e JavaScript puros, sem dependências de front-end nem de Composer.

## Documentação

A documentação completa está em [`docs/`](docs/README.md): [manual do usuário](docs/usuario.md), [configuração e operação](docs/configuracao.md), [segurança](docs/seguranca.md), [referência da API](docs/api.md), [arquitetura](docs/arquitetura.md), [guia de desenvolvimento](docs/desenvolvimento.md) e [decisões (ADRs)](docs/adr/README.md). Histórico em [CHANGELOG.md](CHANGELOG.md).

## Recursos

- Login com autenticação SQL Server (servidor, usuário e senha)
- Object Explorer com todas as bases do servidor (bancos de sistema marcados com `SYS`), cada uma com Tabelas, Views, Procedures e Functions; objetos e colunas carregam sob demanda e há filtro
- Menu de contexto (botão direito) nos bancos, tabelas, views, procedures e funções:
  - **Tabelas e views:** `SELECT TOP 100` (nova aba, já executa), `SELECT *` sem TOP (confirma antes se a tabela tem mais de 100 mil linhas), **Ver DDL**, **Informações da tabela** (linhas aproximadas, espaço, índices e chaves estrangeiras), copiar nome (qualificado ou simples) e **Atualizar** só aquele nó
  - **Bancos:** Nova consulta (`USE [banco]`), Ver DDL do banco (collation e opções) e Atualizar
  - **Procedures e functions:** Ver DDL (definição), copiar nome e Atualizar
- Editor com realce de sintaxe, numeração de linhas e várias abas (salvas no navegador)
- Executa o texto selecionado ou todo o script; suporta `GO` e vários result sets
- AUTO TOP, histórico, favoritos, salvar `.sql`, tema claro/escuro
- Grid com ordenação, filtro, paginação e exportação (copiar, CSV, Excel, JSON)

Atalhos: `Ctrl+Enter` ou `F5` executam; duplo clique em uma tabela faz `SELECT TOP`; clique em uma coluna insere o nome no editor.

## Requisitos

- PHP 8.2+ com as extensões `pdo_sqlsrv` e `sqlsrv`
- Microsoft ODBC Driver 17/18 for SQL Server

## Executando

### Docker (recomendado)

```bash
docker compose up -d
```

Abre em http://localhost:8080 (troque a porta com `SQLWM_PORT`). A imagem traz PHP 8.3, Apache, ODBC Driver 18 e `pdo_sqlsrv`; um build sem cache leva cerca de 1,5 minuto.

Para testar sem ter um SQL Server à mão, suba também o de demonstração e use servidor `mssql`, usuário `sa`:

```bash
docker compose --profile demo up -d
```

A senha de demonstração está em `docker-compose.yml` (`MSSQL_SA_PASSWORD`, porta não publicada). Troque-a em qualquer outro uso. O Driver 18 criptografa por padrão; com certificado autoassinado, marque "Confiar no certificado do servidor" no login.

### PHP local

```bash
php -S localhost:8080 -t public
```

Em Apache/Nginx, aponte o document root para `public/` (há um `.htaccess` com o rewrite para o front controller).

### IIS, com o projeto inteiro numa pasta (sem apontar para `public/`)

Copie o projeto todo para a pasta do site e use o `index.php` da raiz. Cole o conteúdo de `deploy/iis-rewrite.xml` dentro de `<system.webServer>` do `web.config` (requer o módulo URL Rewrite): `assets/` sai de `public/assets/` e todo o resto vai para o `index.php`, então `app/`, `config/` e `tests/` não ficam expostos. O PHP do site precisa ser 8.2+ com `pdo_sqlsrv` e a pasta de sessões (`session.save_path`) gravável.

## Testes

```bash
php tests/run.php
```

Cobrem as funções puras e os objetos de valor; não precisam de banco.

## Arquitetura

MVC com um front controller. O código combina três estilos, cada um onde rende mais:

| Estilo | Onde | Exemplos |
|---|---|---|
| **OO** | Camadas com estado ou efeitos | `Request`/`Response` (imutáveis), `Session`, `Router`, `Container`, `CatalogRepository`, `QueryExecutor`, controllers |
| **Funcional** | Regras de domínio e composição | `app/Models/functions.php` e `ddl.php` (funções puras, incluindo a geração de DDL), `Result` (Either), `pipe`, `take`, middlewares em `pipeline` |
| **MVC** | Fluxo da requisição | Router → middlewares → Controller → Model → View |

```
public/            document root: index.php (front controller) e assets/
app/
  Core/            Application (composition root), Router, Container, View, Config
  Http/            Request, Response, Session, Middleware
  Controllers/     Auth, Editor, Explorer (catálogo), Query (execução) — finos, só orquestram
  Models/          Connection, ConnectionFactory, CatalogRepository, QueryExecutor, QueryResult
                   functions.php: regras puras (split_batches, normalize_server, type_label...)
  Views/           layout, login, editor (templates PHP; saída sempre escapada com $e())
  Support/         Result e funções genéricas (pipe, partial, take, pipeline, e)
  routes.php       rotas declaradas como dados
config/            config.php (padrões) e config.local.php (seu, não versionado)
tests/             run.php
Dockerfile, docker-compose.yml, docker/    empacotamento (Apache + ODBC 18 + pdo_sqlsrv)
```

Decisões:
- **Imutabilidade:** `Request`, `Response`, `Connection` e `QueryResult` são `readonly`; "alterar" devolve uma nova instância.
- **Erros esperados são valores, não exceções:** a validação do login devolve `Result`, encadeado com `flatMap`. Exceções ficam para falhas inesperadas (tratadas em `Application`, sem vazar detalhes).
- **Núcleo puro, borda impura:** só `ConnectionFactory`, `CatalogRepository`, `QueryExecutor` e `Session` fazem I/O. O resto é determinístico e testável.
- **Sem autowiring mágico:** as dependências são ligadas explicitamente em `Application::container()`.

## Configuração

Precedência: `config/config.php` < `config/config.local.php` < variáveis de ambiente. Para sobrescrever em arquivo, crie `config/config.local.php`:

```php
<?php
return [
    'default_server'  => 'meuservidor:1433',
    'allowed_servers' => ['meuservidor:1433'],
    'max_rows'        => 5000,
];
```

Variáveis de ambiente (úteis no Docker; valores vazios são ignorados — veja `.env.example`): `SQLWM_DEFAULT_SERVER`, `SQLWM_ALLOWED_SERVERS` (lista separada por vírgula), `SQLWM_ENCRYPT`, `SQLWM_TRUST_SERVER_CERTIFICATE`, `SQLWM_MAX_ROWS`, `SQLWM_QUERY_TIMEOUT`, `SQLWM_SESSION_TIMEOUT`.

## Segurança

Esta ferramenta executa SQL arbitrário com as credenciais do usuário logado.

- Use HTTPS em qualquer ambiente fora do localhost.
- Preencha `allowed_servers` para impedir o uso da aplicação como proxy para outros hosts.
- Dê ao login do SQL Server apenas as permissões necessárias; a aplicação não impõe modo somente leitura.
- A senha fica na sessão PHP, no servidor, e nunca é enviada ao navegador. O cookie é `HttpOnly` e `SameSite=Strict`, e todo POST exige token CSRF.
- Só `public/` fica exposto: código, configuração e templates ficam fora do document root.
- A sessão expira após `session_timeout` segundos sem uso.
