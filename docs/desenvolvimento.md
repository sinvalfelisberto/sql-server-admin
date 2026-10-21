# Guia de desenvolvimento

## Preparar o ambiente

Não há `composer install` nem `npm install`. Você precisa de PHP 8.2+ (com `pdo_sqlsrv` para rodar a aplicação) e, para o teste do `.xlsx`, Node.js.

```bash
php -S localhost:8080 -t public      # aplicação (precisa de um SQL Server para logar)
docker compose --profile demo up -d  # alternativa: app + SQL Server de demonstração
```

## Testes

```bash
php tests/run.php          # funções puras e objetos de valor: 79 verificações, sem banco
node tests/xlsx.test.js    # gerador de .xlsx: 10 testes, sem banco nem navegador
```

- `tests/run.php` é um runner próprio (função `check(nome, atual, esperado)`) que cobre `Result`, `pipe`/`partial`/`take`/`pipeline`, `Config::fromEnv`, funções de `functions.php`/`ddl.php` e `Connection::fromLogin`. Não precisa de banco.
- `tests/xlsx.test.js` lê o ZIP gerado, confere assinaturas, CRC-32 e o conteúdo dos XMLs.
- **Não há testes automáticos** para controllers, `QueryExecutor`, `CatalogRepository` (dependem de SQL Server) nem para o `app.js`. Valide essas partes manualmente, por exemplo com o perfil `demo` do Docker.

Antes de abrir um PR: rode os dois comandos acima e `php -l` nos arquivos alterados.

## Convenções de código

O projeto combina três estilos, cada um onde rende mais (veja a [arquitetura](arquitetura.md)):

- **OO** onde há estado ou efeito: `Session`, `Router`, `QueryExecutor`, repositórios, controllers.
- **Funcional** para regras de domínio: funções puras em `app/Models/functions.php` e `ddl.php`, `Result` (Either), `pipe`, `pipeline`.
- **Imutabilidade:** `Request`, `Response`, `Connection`, `QueryResult`, `Config` e `Result` são `readonly`.
- Todo arquivo PHP começa com `declare(strict_types=1);`.
- Erros esperados (validação) são valores (`Result`); exceções ficam para o inesperado.
- Saída de template sempre com `$e(...)`.
- Texto da interface e comentários em português do Brasil; identificadores em inglês.
- Sem autowiring: registre cada dependência em `Application::container()`.

## Receitas

### Adicionar um endpoint de API

1. Declare a rota em `app/routes.php`: `['POST', '/api/minha-acao', [MeuController::class, 'acao'], ['auth.api', 'csrf']]`.
2. Crie o método no controller. Se ele consultar o banco, leia a conexão com `$this->session->connection()` e chame `$this->session->release()` em seguida.
3. Coloque a lógica de acesso a dados em um repositório (`app/Models/`) e as regras puras em `functions.php`.
4. No `ExplorerController`, use `respond(fn () => [...])` para obter o envelope `{ok, error}` automático em falhas do banco.
5. Registre controller e dependências novas em `Application::container()`.
6. Escreva testes das funções puras em `tests/run.php`.
7. Documente a rota em [`docs/api.md`](api.md).

O `Router` casa o caminho **exato**; não há parâmetros na URL. Envie os dados no corpo JSON.

### Adicionar um parâmetro de configuração

1. Inclua a chave e o padrão em `config/config.php`.
2. Se deve existir variável de ambiente, adicione a linha em `Config::ENV_MAP` (nome, chave, tipo `string|int|bool|list`).
3. Repasse a variável no `docker-compose.yml` e documente em `.env.example`.
4. Atualize a tabela em [Configuração](configuracao.md#parâmetros).

### Adicionar um formato de exportação

No `app.js`, a seção `// ---------- Exportação ----------` tem `exportRows()` (dados), os conversores (`toCsv`, `toJson`, `XlsxWriter.build`) e `exportName(sql, ext)` (nome do arquivo no padrão `schema.tabela_ddMMyyyy.ext`). Crie o conversor, o botão no `app/Views/editor.php` e o listener em `bind()` (junto de `btn-csv`/`btn-xls`/`btn-json`).

### Mexer no gerador `.xlsx`

`public/assets/js/xlsx.js` é um módulo UMD enxuto: no navegador cria `XlsxWriter`; no Node faz `module.exports`. Para células numéricas ele usa o tipo SQL da coluna (`NUMERIC_TYPE`) e só grava número quando não há perda de precisão (até 15 dígitos). Alterações devem manter `node tests/xlsx.test.js` passando; para validar de ponta a ponta, abra o arquivo gerado no Excel ou no LibreOffice.

## Estrutura do DDL gerado

`build_create_table` monta, nesta ordem: `CREATE TABLE` (colunas, identity, computadas, defaults, PK/UNIQUE e checks), `CREATE INDEX` para os demais índices e `ALTER TABLE … ADD CONSTRAINT … FOREIGN KEY`, cada bloco terminado em `GO`. Só chaves estrangeiras de saída (`direction = out`) entram.

## Contribuir

**Pendente:** não há `CONTRIBUTING.md`, licença ou política de branches definidas no repositório. O histórico usa mensagens de commit descritivas em inglês (por exemplo, "Add XLSX export functionality and corresponding tests").
