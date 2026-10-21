# 0002 – Credenciais na sessão PHP e conexão nova por requisição

- **Status:** aceita
- **Contexto:** a aplicação usa a autenticação SQL Server de cada usuário, não uma conta de serviço. É preciso reutilizar as credenciais entre requisições sem enviá-las de volta ao navegador.
- **Decisão:** a senha fica na sessão PHP, no servidor (`Session`), e cada requisição de API abre uma conexão `PDO` nova por meio de `ConnectionFactory`, selecionando o banco com `USE [banco]`. O controller chama `Session::release()` antes de consultar o banco para não bloquear a sessão.
- **Alternativas consideradas:** conexões persistentes; token/credencial guardada no navegador; conta de serviço única. *Inferidas:* conexão persistente não combina com credenciais por usuário, e guardar a senha no cliente exporia o segredo.
- **Consequências:**
  - (+) A senha nunca é enviada ao navegador; cookie `HttpOnly`/`SameSite=Strict` e token CSRF.
  - (+) O SQL Server aplica as permissões de cada usuário; não existe um "superusuário" da aplicação.
  - (−) Custo de abrir conexão a cada chamada (login timeout de 10 s por padrão).
  - (−) A senha fica em texto no armazenamento de sessões do servidor; proteja `session.save_path` (veja [Segurança](../seguranca.md)).
  - (−) Transações e variáveis de sessão do SQL Server **não** persistem entre execuções.
