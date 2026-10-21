# Configuração e operação

## Requisitos

| Item | Versão |
|---|---|
| PHP | 8.2 ou superior (o código usa `readonly class`; no PHP 8.1 ou anterior a aplicação não inicia) |
| Extensões PHP | `pdo_sqlsrv` e `sqlsrv` |
| Driver | Microsoft ODBC Driver 17 ou 18 for SQL Server |
| Navegador | Moderno, com `<dialog>`, `fetch`, `Blob` e `localStorage` |
| Node.js | Só para rodar `tests/xlsx.test.js` (usa `zlib.crc32`, disponível no Node 20.15+/22.2+ — **pendente:** versão mínima exata não verificada) |

A imagem Docker traz PHP 8.3, Apache, ODBC Driver 18 e `pdo_sqlsrv`.

## Parâmetros

Precedência: `config/config.php` < `config/config.local.php` < variáveis de ambiente. Variáveis vazias ou inválidas são ignoradas.

| Chave | Variável de ambiente | Tipo | Padrão | Descrição |
|---|---|---|---|---|
| `app_name` | — | string | `SQL Web Manager` | Nome exibido |
| `version` | — | string | `1.0.0` | Versão exibida no rodapé |
| `default_server` | `SQLWM_DEFAULT_SERVER` | string | vazio | Servidor sugerido no login |
| `allowed_servers` | `SQLWM_ALLOWED_SERVERS` | lista (vírgula) | vazia | Servidores permitidos. Vazia = qualquer um. Formatos aceitos iguais aos do login |
| `trust_server_certificate` | `SQLWM_TRUST_SERVER_CERTIFICATE` | bool | `true` | Valor inicial do checkbox de certificado |
| `encrypt` | `SQLWM_ENCRYPT` | bool | `null` | `null` = padrão do driver; `true`/`false` força |
| `login_timeout` | — | int (s) | `10` | Tempo para conectar |
| `query_timeout` | `SQLWM_QUERY_TIMEOUT` | int (s) | `120` | Tempo máximo por consulta |
| `max_rows` | `SQLWM_MAX_ROWS` | int | `10000` | Teto de linhas por result set; em consulta paginada vale **por página** (tamanho máximo da página) |
| `export_max_rows` | `SQLWM_EXPORT_MAX_ROWS` | int | `0` | Teto de linhas na exportação completa (CSV/JSON/Excel de resultado paginado); `0` = sem teto |
| `session_timeout` | `SQLWM_SESSION_TIMEOUT` | int (s) | `3600` | Inatividade até desconectar |

Booleanos aceitam `true/false`, `1/0`, `yes/no`, `on/off`. Em lista, separe por vírgula: `SQLWM_ALLOWED_SERVERS=srv1:1433,srv2`.

Exemplo de `config/config.local.php` (não versionado):

```php
<?php
return [
    'default_server'  => 'meuservidor:1433',
    'allowed_servers' => ['meuservidor:1433'],
    'max_rows'        => 5000,
];
```

## Executar

### Docker (recomendado)

```bash
docker compose up -d
```

- Porta padrão `8080`; altere com `SQLWM_PORT` (veja `.env.example`; copie para `.env`).
- O contêiner tem `healthcheck` em `/login` e `no-new-privileges`.
- SQL Server de demonstração: `docker compose --profile demo up -d`; defina antes `MSSQL_SA_PASSWORD` (senha forte, sua) no `.env`; não há senha padrão. No login use servidor `mssql`, usuário `sa` e essa senha. A porta dele não é publicada.
- A imagem só copia `app/`, `config/` e `public/`. Para alterar a configuração, use variáveis de ambiente.

### PHP local (desenvolvimento)

```bash
php -S localhost:8080 -t public
```

Exige `pdo_sqlsrv` no PHP local. O servidor embutido atende o front controller sem configuração extra.

### Apache e Nginx

Aponte o document root para `public/`. O `public/.htaccess` redireciona para o `index.php` o que não for arquivo nem pasta (precisa de `mod_rewrite` e `AllowOverride All`). Um exemplo completo está em `docker/apache.conf`. No Nginx, use `try_files $uri /index.php$is_args$args;` com PHP-FPM (**pendente:** configuração Nginx não testada neste projeto).

### IIS, projeto inteiro em uma pasta

Para hospedagens em que não dá para apontar o site para `public/`:

1. Copie o projeto todo para a pasta do site. O `index.php` da raiz inclui o `public/index.php`.
2. Instale o módulo **URL Rewrite** do IIS.
3. Cole o conteúdo de `deploy/iis-rewrite.xml` dentro de `<system.webServer>` do `web.config` do site. As regras:
   - `assets/…` → `public/assets/…` (estáticos);
   - todo o resto → `index.php`, inclusive `app/`, `config/`, `tests/`, `.env` e `*.md`, que por isso nunca são expostos.
4. Use um handler FastCGI de PHP 8.2+ com `pdo_sqlsrv`.
5. Garanta que o usuário do pool de aplicativos escreva em `session.save_path`.

A aplicação detecta a subpasta (`SCRIPT_NAME`) e monta as URLs a partir dela. No IIS, quando `REQUEST_URI` não existe, usa `HTTP_X_ORIGINAL_URL`, e trata `HTTPS=off` como conexão não segura.

> **Atenção:** sem URL Rewrite as rotas (`/login`, `/api/*`) não funcionam. Sem ele seria preciso trocar as URLs para o formato `index.php/…`, o que o código atual não faz.
>
> Isso não foi testado em um IIS real: validado apenas o tratamento da requisição por simulação das variáveis de servidor.

## Operação

### Logs

Erros inesperados são gravados com `error_log` (`Classe: mensagem em arquivo:linha`). No Docker, vão para o stderr do contêiner: `docker compose logs app`. O Apache envia acesso e erro para stdout/stderr.

### Sessões

Ficam no armazenamento padrão do PHP (arquivos em `session.save_path`). Em mais de uma instância atrás de um balanceador, use sessão fixa (sticky) ou um armazenamento de sessão compartilhado.

### Atualizar

Basta substituir os arquivos (ou reconstruir a imagem: `docker compose build && docker compose up -d`). Sessões antigas com formato inválido contam como deslogadas, sem erro. Não há banco próprio nem migrações.

## Solução de problemas

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| Página em branco ou "Erro interno do servidor." logo na abertura | PHP anterior a 8.2, extensão `pdo_sqlsrv` ausente ou erro de PHP | Veja o log do PHP; confirme `php -v` e `php -m` |
| `could not find driver` / `SQLSTATE[IM002]` no login | Falta `pdo_sqlsrv` ou o ODBC Driver | Instale as extensões e o msodbcsql 17/18 |
| `SSL Provider: certificate verify failed` ou erro de certificado | Certificado autoassinado | Marque **Confiar no certificado do servidor**, ou defina `SQLWM_TRUST_SERVER_CERTIFICATE=true` |
| `Servidor não permitido.` | `allowed_servers` preenchido sem o servidor informado | Inclua o servidor (mesma forma `host,porta`) ou limpe a lista |
| `Nome de servidor inválido.` | Formato fora de `host`, `host\instancia`, `host,porta`, `host:porta` | Corrija o texto do servidor |
| Login em HTTP "não pega" (volta ao login) em IIS | Cookie marcado como seguro por `HTTPS=off` | Atualize para a versão atual; confirme `Request::fromGlobals` |
| 404 em `/login`, `/api/...` no IIS/Apache | Rewrite ausente | Instale/ative URL Rewrite (IIS) ou `mod_rewrite` + `AllowOverride All` (Apache) |
| CSS e JS não carregam em subpasta | `assets/` não mapeado para `public/assets/` | Aplique `deploy/iis-rewrite.xml` (IIS) ou aponte o document root para `public/` |
| `Token CSRF inválido. Recarregue a página.` | Sessão expirada, cookies bloqueados ou site em iframe/outro domínio | Recarregue a página e faça login de novo |
| `Sessão expirada.` ao usar o editor | Inatividade maior que `session_timeout` | Faça login novamente |
| Resultado "limitado às primeiras N linhas" | AUTO TOP ou `max_rows` (em resultado que não é paginado) | Aumente o AUTO TOP, ou `max_rows` (teto do servidor) |
| Exportação completa demora ou satura o servidor | Resultado paginado exporta sem teto | Defina `SQLWM_EXPORT_MAX_ROWS`; revise `query_timeout` |
| Consulta cai por tempo | Excedeu `query_timeout` | Otimize a consulta ou aumente `SQLWM_QUERY_TIMEOUT` |
| **Ver DDL** diz "Definição indisponível" | Objeto criptografado, inexistente ou sem `VIEW DEFINITION` | Conceda a permissão ao login |
| Botão **Excel** avisa limite de linhas | Mais de 1.048.575 linhas | Exporte em CSV |
