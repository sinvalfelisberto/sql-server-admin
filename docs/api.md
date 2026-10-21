# Referência da API HTTP

A API é interna: existe para o front-end da própria aplicação e **não é uma API pública estável**. Rotas, campos e formatos podem mudar entre versões. Esta página documenta o que o código faz hoje (`app/routes.php` e controllers).

## Convenções

- **Base:** a raiz da aplicação (por exemplo `http://localhost:8080` ou `https://servidor/sql-admin`). O front-end lê a base da `<meta name="base-url">`.
- **Autenticação:** cookie de sessão `sqlwm` (`HttpOnly`, `SameSite=Strict`, `Secure` quando a requisição é HTTPS). Não há tokens de API.
- **CSRF:** todo `POST` exige o token da sessão, no cabeçalho `X-CSRF-Token` (API) ou no campo de formulário `csrf` (login). O token está na página HTML servida após o login.
- **Formato:** requisições de API enviam JSON (`Content-Type: application/json`); respostas de API são JSON UTF-8 com `Cache-Control: no-store`. Corpo JSON inválido é tratado como `{}`.
- **Cabeçalhos de segurança** em todas as respostas: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`.
- **Banco de dados:** o campo `database` de cada chamada seleciona o banco (via `USE`); vazio mantém o banco padrão do login.

### Envelope de resposta

Sucesso: `{ "ok": true, ... }`. Falha do banco: HTTP 200 com

```json
{ "ok": false, "error": { "message": "Invalid object name 'x'.", "number": 208, "state": "42S02" } }
```

`number` é o código do SQL Server e `state` o SQLSTATE; ambos podem ser `null`. O prefixo `[Microsoft][ODBC Driver 18…]` é removido da mensagem.

### Erros de protocolo

| HTTP | Quando | Corpo |
|---|---|---|
| 401 | Sem sessão válida (API) | `{"ok":false,"error":{"message":"Sessão expirada. Faça login novamente."}}` |
| 403 | Token CSRF ausente ou inválido | API: `{"ok":false,"error":{"message":"Token CSRF inválido. Recarregue a página."}}`; páginas: HTML "Sessão expirada." |
| 404 | Caminho inexistente | HTML `Página não encontrada.` |
| 405 | Método não permitido (cabeçalho `Allow`) | HTML `Método não permitido.` |
| 500 | Exceção inesperada | `{"ok":false,"error":{"message":"Erro interno do servidor."}}`; o detalhe vai só para o log do PHP |

## Rotas de páginas

| Método | Caminho | Middlewares | Descrição |
|---|---|---|---|
| GET | `/` | `auth.page` | Editor. Sem login, redireciona (302) para `/login` |
| GET | `/login` | `guest` | Formulário de login. Já logado, redireciona para `/` |
| POST | `/login` | `guest`, `csrf` | Valida e abre a sessão. Sucesso: 302 para `/`. Falha: 200 com o formulário e a mensagem de erro |

Campos do `POST /login` (formulário): `csrf`, `server`, `user`, `pass`, `trust` (presente = confiar no certificado). Mensagens de falha: `Nome de servidor inválido.`, `Servidor não permitido.`, `Informe o usuário.` ou a mensagem do SQL Server.

## Rotas da API

Todas: `POST`, JSON, middlewares `auth.api` e `csrf`.

### `POST /api/logout`

Encerra a sessão. Resposta: `{ "ok": true }`.

### `POST /api/databases`

Lista os bancos acessíveis e a versão do servidor. Sem parâmetros.

```json
{
  "ok": true,
  "current": "master",
  "databases": [ { "name": "master", "system": true }, { "name": "Vendas", "system": false } ],
  "version": "16.0.4135.4"
}
```

Bancos com `database_id <= 4` são de sistema. Só entram bancos em que o login tem acesso.

### `POST /api/objects`

Objetos de usuário de um banco (tabelas, views, procedures e funções).

| Campo | Tipo | Descrição |
|---|---|---|
| `database` | string | Banco a consultar |

```json
{ "ok": true, "objects": [ { "schema": "dbo", "name": "Clientes", "type": "U" } ] }
```

`type`: `U` tabela, `V` view, `P` procedure, `FN` / `IF` / `TF` funções (escalar, inline, de tabela). Objetos da Microsoft (`is_ms_shipped`) ficam de fora.

### `POST /api/columns`

Colunas de uma tabela ou view; para procedures e funções, devolve os parâmetros.

| Campo | Tipo | Descrição |
|---|---|---|
| `database`, `schema`, `name` | string | Objeto |
| `type` | string | Tipo do objeto (`U`/`V` retorna colunas; outros valores, parâmetros) |

```json
{ "ok": true, "columns": [ { "name": "Id", "type": "int", "nullable": false, "pk": true } ] }
```

`type` é o rótulo legível (`nvarchar(100)`, `decimal(18,2)`, `varchar(max)`). Parâmetros vêm com `nullable: true` e `pk: false`.

### `POST /api/ddl`

Script de um objeto. Tabela (`type = "U"`): `CREATE TABLE` com colunas, identity, colunas computadas, defaults, chaves, checks, índices e chaves estrangeiras. Demais tipos: definição obtida com `OBJECT_DEFINITION`.

Campos: `database`, `schema`, `name`, `type`.

```json
{ "ok": true, "ddl": "CREATE TABLE [dbo].[Clientes] (...)" }
```

Se o objeto não existe, o texto vem como comentário SQL (`-- Tabela ... não encontrada.` ou `-- Definição indisponível ...`), ainda com `ok: true`.

### `POST /api/db-ddl`

Script de criação do banco com collation e opções ativas. Campo: `database`. Resposta: `{ "ok": true, "ddl": "..." }`. Banco inexistente: `-- Banco de dados não encontrado.`

### `POST /api/table-info`

Metadados de uma tabela ou view. Campos: `database`, `schema`, `name`.

```json
{
  "ok": true,
  "rows": 1520,
  "reservedKb": 400, "dataKb": 240, "indexKb": 96, "unusedKb": 64,
  "indexes": [
    { "name": "PK_Clientes", "type": "CLUSTERED", "unique": true, "primaryKey": true,
      "uniqueConstraint": false, "filter": null,
      "keys": [ { "name": "Id", "desc": false } ], "included": [] }
  ],
  "foreignKeys": [
    { "name": "FK_Pedidos_Clientes", "direction": "in", "parentTable": "dbo.Pedidos",
      "refSchema": "dbo", "refTable": "dbo.Clientes", "refName": "Clientes",
      "onDelete": "NO ACTION", "onUpdate": "NO ACTION",
      "columns": ["ClienteId"], "refColumns": ["Id"] }
  ]
}
```

`rows` é aproximado (vem de `sys.dm_db_partition_stats`) e é `null` quando a view/objeto não tem estatística. `direction`: `out` = esta tabela referencia outra; `in` = é referenciada por outra.

### `POST /api/execute`

Executa um script T-SQL.

| Campo | Tipo | Padrão | Descrição |
|---|---|---|---|
| `sql` | string | — | Script. `GO` em linha isolada separa lotes |
| `database` | string | banco do login | Banco em que o script roda |
| `limit` | int | 0 | Máximo de linhas **por result set**. `0` ou ausente = o teto do servidor; valores acima de `max_rows` são reduzidos a `max_rows`. Com `page`, vira o teto **total** (AUTO TOP) somando as páginas; `0` = sem teto |
| `page` | int | — | Pede paginação no servidor (1 = primeira página). Só vale para um único `SELECT` paginável; senão a resposta é a normal, sem o campo `page` |
| `page_size` | int | 200 | Linhas por página; limitado a `max_rows` |

```json
{
  "ok": true,
  "error": null,
  "sets": [
    { "columns": [ { "name": "a", "type": "int" } ], "rows": [ [1] ], "truncated": false }
  ],
  "messages": [ "depois" ],
  "total_rows": 1,
  "elapsed_ms": 12
}
```

- `rows` é uma lista de listas, na ordem de `columns`. Binários viram `"0xHEX"`. `int`, `float` e afins vêm como número JSON; `decimal`, `money` e `bigint` vêm como **string** (ex.: `"1.50"`, `"9007199254740993"`), para não perder precisão. `NULL` vem como `null`.
- `columns[].type` é o nome-base do tipo no driver (`decimal`, `nvarchar`, `varbinary`), **sem** tamanho ou precisão, diferente de `/api/columns`.
- `truncated: true` indica que o result set tinha mais linhas que o limite.
- `page` é `null` (resultado normal) ou `{ "page": 2, "size": 200, "hasMore": true }` quando a consulta foi paginada. Nesse caso `sets` tem um único result set com no máximo `size` linhas e `truncated` é sempre `false`; use `hasMore` para saber se há próxima página. A consulta é reexecutada com `OFFSET … FETCH` a cada chamada; sem `ORDER BY` a ordem entre páginas não é garantida. Se a consulta não for paginável ou o servidor recusar a versão paginada, o campo `page` vem `null` e a execução é a normal.
- `messages` reúne `PRINT`, avisos e `(N linha(s) afetada(s))`. Sem result set, sem mensagem e sem erro, o servidor devolve `["Comando(s) concluído(s) com sucesso."]`.
- Se um lote falha, `ok` é `false` e `error` traz `message`/`number`/`state`. O que foi lido **antes do lote com erro** (lotes anteriores separados por `GO`, ou result sets anteriores no mesmo lote, quando o erro ocorre em tempo de execução) permanece em `sets` e `messages`. Erros de compilação, como `SELECT 1; SELECT coluna_inexistente`, derrubam o lote inteiro e nada dele é executado. O HTTP continua 200.
- Comandos DDL e `INSERT` sem result set geram mensagens `(N linha(s) afetada(s))`; para DDL o `N` é 0.
- O tempo máximo de cada consulta é `query_timeout` (padrão 120 s). O script PHP recebe esse valor + 30 s.
- O script roda sem transação implícita e **com as permissões do login**: `INSERT`/`UPDATE`/`DDL` são executados de verdade.

### `POST /api/export`

Exporta o resultado **completo** de um `SELECT` único, reexecutando a consulta e escrevendo a resposta em fluxo (sem montar o arquivo na memória). Aceita formulário (`application/x-www-form-urlencoded`, com o campo `csrf`) ou JSON (com `X-CSRF-Token`).

| Campo | Tipo | Descrição |
|---|---|---|
| `sql` | string | Um único comando; `GO` ou vários lotes são recusados |
| `database` | string | Banco |
| `format` | string | `csv` (padrão), `json` ou `raw` |
| `filename` | string | Nome do arquivo sem extensão (`csv` e `json`); caracteres fora de `A-Za-z0-9._-` viram `_` |
| `limit` | int | Máximo de linhas; `0` = sem teto (ou `export_max_rows`, se definido, que sempre prevalece como máximo) |

- `csv`: UTF-8 com BOM, separador `;`, `CRLF`, mesma regra de aspas da exportação do navegador; `Content-Disposition: attachment`.
- `json`: lista de objetos (colunas repetidas ganham sufixo `_2`, `_3`); `Content-Disposition: attachment`.
- `raw`: `{ "columns": ["Id"], "types": ["bigint"], "rows": [["1"], ...] }`, usado pelo navegador para montar o `.xlsx`.
- Se a consulta falha antes de começar a escrever, a resposta é o envelope de erro normal (`{"ok":false,"error":{...}}`). Um erro **durante** a leitura interrompe o arquivo, que fica incompleto.
- O PHP roda sem limite de tempo nesta rota (`set_time_limit(0)`); o `query_timeout` continua valendo para a execução da consulta no SQL Server.

## Exemplo com curl

A API depende de sessão e token CSRF. O token está em `<meta name="csrf-token">` de qualquer página HTML (o do login serve para o login; depois de logado, leia de novo, em `/`). Fluxo testado contra um SQL Server 2022:

```bash
BASE=http://localhost:8080
token() { sed -n 's/.*name="csrf-token" content="\([^"]*\)".*/\1/p'; }

# 1. Token do login (e cookie de sessão)
CSRF=$(curl -s -c cookies.txt "$BASE/login" | token)

# 2. Logar (resposta esperada: 302)
curl -s -b cookies.txt -c cookies.txt -o /dev/null -w '%{http_code}\n' "$BASE/login" \
  --data-urlencode "csrf=$CSRF" --data-urlencode "server=SEU_SERVIDOR:1433" \
  --data-urlencode "user=SEU_USUARIO" --data-urlencode "pass=SUA_SENHA" --data-urlencode "trust=1"

# 3. Token da sessão logada
TOKEN=$(curl -s -b cookies.txt "$BASE/" | token)

# 4. Chamar a API
curl -s -b cookies.txt "$BASE/api/execute" \
  -H "X-CSRF-Token: $TOKEN" -H 'Content-Type: application/json' \
  -d '{"sql":"SELECT @@VERSION AS v","database":"master","limit":10}'
```

Use `SEU_SERVIDOR`, `SEU_USUARIO` e `SUA_SENHA` conforme o seu ambiente; nunca versione credenciais reais.
