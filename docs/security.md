# Segurança

A aplicação executa **SQL arbitrário com as credenciais de quem fez login**. Ela não substitui o controle de acesso do SQL Server: quem consegue entrar consegue fazer tudo o que o login permite, inclusive `UPDATE`, `DELETE` e DDL. Não existe modo somente leitura (consta no PRD, mas não foi implementado).

## Recomendações de implantação

1. **Use HTTPS** em qualquer ambiente fora do localhost. As senhas trafegam no formulário de login.
2. **Preencha `allowed_servers`** para impedir que a aplicação sirva de proxy para qualquer host da rede (SSRF). Vazio significa "qualquer servidor".
3. **Menor privilégio:** crie logins com só as permissões necessárias. Sem `VIEW DEFINITION`, o **Ver DDL** de procedures e views não funciona.
4. **Exponha só o necessário.** Com `public/` como document root, `app/` e `config/` ficam inacessíveis. Sem essa opção, use o `index.php` da raiz e as regras de [IIS](configuracao.md#iis-projeto-inteiro-em-uma-pasta), que mandam tudo, exceto `assets/`, para o front controller.
5. Restrinja o acesso de rede (VPN, firewall, allowlist de IP) quando possível.
6. O `docker-compose.yml` não traz senha: defina `MSSQL_SA_PASSWORD` no `.env` (não versionado) para usar o perfil `demo`, e nunca versione esse arquivo.

## O que a aplicação faz

| Área | Medida | Onde |
|---|---|---|
| Senha | Fica na sessão PHP, no servidor; nunca é enviada ao navegador | `Session`, `Connection` |
| Cookie de sessão | Nome `sqlwm`, `HttpOnly`, `SameSite=Strict`, `Secure` quando HTTPS, modo estrito de sessão | `Session::start`, `docker/php.ini` |
| Fixação de sessão | `session_regenerate_id(true)` no login | `Session::login` |
| CSRF | Todo `POST` exige token (cabeçalho ou campo), comparado com `hash_equals` | `Middleware::csrf` |
| Força bruta | Atraso de 0,5 s em falha de login | `AuthController::verify` |
| Expiração | Sessão encerra após `session_timeout` s sem uso (padrão 3600) | `Session::start` |
| Servidor de destino | Nome validado por expressão regular; lista de permitidos opcional | `normalize_server`, `ensure_server_allowed` |
| Injeção em opções de conexão | O banco não entra no DSN; usa `USE` com identificador escapado (`]` vira `]]`) | `ConnectionFactory`, `quote_identifier` |
| Catálogo | Consultas com parâmetros (`prepare`/`execute`) | `CatalogRepository`, `ObjectScripter` |
| XSS | Toda saída de template passa por `e()` (`htmlspecialchars`) | `Views` |
| Clickjacking e sniffing | `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` | `Application` |
| Vazamento de erros | Exceções inesperadas vão para o log; o cliente recebe mensagem genérica. `display_errors=Off`, `expose_php=Off` | `Application`, `docker/php.ini` |
| Cache | Respostas JSON com `Cache-Control: no-store` | `Response::json` |
| Limites | `query_timeout`, `max_rows` e `memory_limit` limitam o custo de cada consulta | `Config`, `QueryExecutor` |

## Limitações conhecidas

- **Sem controle por usuário no nível da aplicação:** não há perfis, auditoria ou lista de comandos bloqueados.
- **Sem limitação de taxa** (rate limit) além do atraso de 0,5 s no login falho.
- **Senha em texto na sessão:** quem tem acesso de leitura ao armazenamento de sessões do servidor (`session.save_path`) consegue ler as credenciais dos usuários logados. Restrinja as permissões dessa pasta.
- **`localStorage`:** histórico e favoritos ficam no navegador, em texto, e podem conter dados sensíveis digitados em consultas. Use perfil de navegador próprio em máquinas compartilhadas.
- **Confiar no certificado do servidor** desativa a validação do certificado TLS entre a aplicação e o SQL Server (`TrustServerCertificate`). Prefira certificado válido e desmarque a opção.
- **CSV e fórmulas:** o CSV escreve o texto das células sem neutralizar o início (`=`, `+`, `-`, `@`). Aberto no Excel, um valor assim pode ser interpretado como fórmula. Se os dados vierem de terceiros, prefira o botão **Excel**: o `.xlsx` grava todo texto como texto (`inlineStr`), não como fórmula.

## Reportar uma vulnerabilidade

O projeto não tem canal de segurança publicado (**pendente:** definir contato e política, por exemplo um `SECURITY.md`).
