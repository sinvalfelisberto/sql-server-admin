# Manual do usuário

Para quem vai consultar e administrar um SQL Server pelo navegador.

## Conectar

1. Abra a aplicação (por exemplo, `http://localhost:8080`).
2. Preencha:
   - **Servidor:** `host`, `host\instancia`, `host,porta` ou `host:porta` (ex.: `meuservidor:1433`). `host:porta` é convertido para `host,porta`.
   - **Usuário** e **Senha:** login de autenticação SQL Server. Autenticação do Windows não é suportada.
   - **Confiar no certificado do servidor:** marque se o servidor usa certificado autoassinado (o Driver 18 criptografa por padrão).
3. Clique em **Conectar**.

A aplicação testa as credenciais de verdade e abre no banco padrão do login. Se o administrador restringiu os servidores, outro host retorna **Servidor não permitido.** Credenciais erradas mostram a mensagem do SQL Server e o login demora cerca de meio segundo para responder.

A sessão expira após um período sem uso (padrão: 1 hora). Ao expirar, a próxima ação leva de volta ao login. O botão de saída (canto direito da barra superior) encerra a sessão.

## A tela

| Área | Função |
|---|---|
| Barra superior | Seletor de banco, **Executar**, limpar editor, **Histórico**, **Favoritos**, salvar `.sql`, **AUTO TOP**, usuário@servidor, tema, sair |
| Object Explorer (esquerda) | Árvore de bancos e objetos. Arraste a divisória para redimensionar; duplo clique nela restaura a largura |
| Abas de consulta | Várias consultas ao mesmo tempo; **+** abre uma nova |
| Editor | Realce de sintaxe e numeração de linhas |
| Resultados / Mensagens | Grade de dados ou mensagens do servidor |
| Barra de status | Banco atual, linhas, tempo de execução e versão |

As abas, o histórico, os favoritos, o tema, o banco escolhido e o AUTO TOP ficam salvos no navegador (`localStorage`), não no servidor.

## Object Explorer

- Cada banco traz os grupos **Tabelas**, **Views**, **Procedures** e **Functions**. Os objetos e as colunas carregam sob demanda quando você expande o nó.
- Bancos de sistema aparecem marcados com `SYS`. Só aparecem os bancos a que o login tem acesso.
- O campo **Filtrar objetos...** filtra a árvore. Cada grupo mostra no máximo 500 objetos; acima disso aparece `… mais N (refine o filtro)`. O botão de atualizar, no topo, recarrega tudo.
- Clicar em uma coluna insere o nome dela no editor.
- Duplo clique em uma tabela executa `SELECT TOP` nela, em uma nova aba.

### Menu de contexto (botão direito)

**Tabelas e views**

| Item | O que faz |
|---|---|
| **SELECT TOP 100** | Abre nova aba e já executa |
| **SELECT \* (sem TOP)** | Abre nova aba com `SELECT *`. Se a tabela tem mais de 100 mil linhas, pede confirmação antes |
| **Ver DDL** | Mostra o script `CREATE TABLE` (colunas, identity, computadas, defaults, PK/UNIQUE, índices, chaves estrangeiras e checks). Para views, mostra a definição |
| **Informações da tabela** | Linhas aproximadas, espaço (reservado, dados, índices, não usado), índices e chaves estrangeiras |
| **Copiar nome qualificado** / **Copiar nome simples** | Copia `[schema].[nome]` ou só o nome |
| **Atualizar** | Recarrega só aquele nó |

**Bancos:** **Nova consulta** (abre aba com `USE [banco]`), **Ver DDL (script do banco)** (collation e opções ativas; os arquivos MDF/LDF não entram) e **Atualizar**.

**Procedures e functions:** **Ver DDL** (definição do objeto), copiar nome e **Atualizar**. Se a definição estiver indisponível, o script avisa: o objeto não existe, está criptografado ou falta a permissão `VIEW DEFINITION`.

## Escrever e executar consultas

- **Executar:** botão **Executar**, `F5` ou `Ctrl+Enter` (`Cmd+Enter` no macOS).
- Com texto selecionado, só a seleção é executada; sem seleção, o script inteiro.
- `Tab` insere dois espaços no editor (não muda o foco).
- **GO** em linha isolada separa os lotes, como no SSMS (aceita `GO 5` e comentário depois). Cada lote roda em sequência. O contador do `GO` é ignorado: `GO 5` não repete o lote.
- Vários result sets aparecem como botões (chips) acima da grade.
- Se um lote falha, os lotes anteriores (separados por `GO`) e os result sets já lidos são mantidos, e a mensagem de erro aparece na aba **Mensagens**. Erros de compilação derrubam o lote inteiro.
- O banco usado na execução é o do seletor da barra superior.

### AUTO TOP

Com **AUTO TOP** marcado, o resultado é limitado ao número de linhas informado ao lado (padrão: 1000) **no total**, somando todas as páginas. Se o resultado foi cortado, a aba **Mensagens** avisa. **SELECT \* (sem TOP)** ignora o AUTO TOP.

### Paginação no servidor

Quando o que você executa é **um único `SELECT`** (pode ter `WHERE`, `JOIN`, `GROUP BY`, `ORDER BY` e CTE), o servidor devolve só uma página por vez (200 linhas por padrão, ajustável em **Exibir**) e a grade mostra **‹ Anterior** / **Próxima ›** e `Linhas 201 a 400 (há mais)`. Assim o servidor e o navegador nunca carregam o resultado inteiro de uma vez.

- **Cada página reexecuta a consulta** (com `OFFSET … FETCH`). Em tabelas grandes, páginas mais ao fim custam mais.
- **Sem `ORDER BY`, a ordem entre páginas não é garantida** pelo SQL Server: linhas podem repetir ou faltar se os dados mudarem. Para paginar de forma estável, use `ORDER BY` com uma coluna única.
- **Ordenar pelo cabeçalho e Filtrar valem só para a página atual.** Para ordenar tudo, use `ORDER BY` na consulta.
- Vale o `SELECT` exato que você executou, e não um recorte do resultado. Se houver texto selecionado, só ele é executado.
- **Não é paginado** (volta o resultado inteiro, limitado por AUTO TOP e `max_rows`, como antes): scripts com `GO` ou vários comandos, `SELECT … INTO`, `SELECT TOP …` na instrução principal, `FOR XML/JSON`, `OPTION (…)`, atribuição a variável, `UNION`/`DISTINCT` sem `ORDER BY`, `EXEC` e qualquer comando que não seja `SELECT`. Se o servidor recusar a versão paginada, a consulta roda normalmente.

### Mensagens e `PRINT`

A aba **Mensagens** mostra o texto de `PRINT`, avisos do servidor, `(N linha(s) afetada(s))`, o erro (com número `Msg`), o tempo total e a contagem de linhas de cada resultado. Quando a execução não devolve nenhuma grade (por exemplo, um script só com `PRINT`), essa aba abre sozinha.

> Os `PRINT` aparecem juntos na aba, na ordem em que o servidor os enviou, sem posição relativa às grades.

### Histórico e favoritos

- **Histórico:** as últimas 50 consultas executadas (sem repetições). Clique para recarregar no editor; há **Limpar histórico**.
- **Favoritos:** **★ Adicionar consulta atual aos favoritos** pede um nome; clique no favorito para carregá-lo no editor e use o botão de remoção do item para excluir.
- **Salvar:** baixa o editor como `.sql` (o nome vem do título da aba).

## Grade de resultados

- **Ordenar:** clique no cabeçalho da coluna: 1º clique ascendente, 2º descendente, 3º remove a ordenação. Trocar de result set limpa a ordenação.
- **Filtrar:** o campo **Filtrar** mostra só as linhas em que qualquer coluna contém o texto (sem diferenciar maiúsculas); atua sobre os dados já carregados.
- **Exibir:** linhas por página (10 a 1000); o padrão é 200. Em resultado paginado no servidor, mudar o valor recarrega a primeira página. Nos demais, a paginação é só de exibição.
- Células `NULL` aparecem como **NULL** em destaque; colunas numéricas ficam alinhadas à direita; colunas binárias aparecem como `0xHEX`.
- O rodapé da grade mostra o intervalo exibido, o total e a navegação **‹ Anterior** / **Próxima ›**.
- O tipo SQL da coluna aparece como dica ao passar o mouse no cabeçalho.

## Exportar

Os botões da grade exportam o resultado da consulta:

- **Resultado paginado no servidor:** o arquivo traz o **resultado completo da consulta**, não só a página visível nem o recorte do AUTO TOP. O servidor executa a consulta de novo e grava as linhas em fluxo. A ordenação e o filtro da grade **não** entram: vale a ordem da consulta (use `ORDER BY`). O download só começa quando o servidor termina de abrir a consulta; consultas grandes podem demorar e não têm teto de linhas, a menos que o administrador defina `export_max_rows`.
- **Demais resultados (já carregados inteiros):** o arquivo traz **todas as linhas carregadas**, com a ordenação e o filtro da grade aplicados.
- **Copiar** copia só a página atual quando o resultado é paginado.

| Botão | Resultado |
|---|---|
| **Copiar** | Copia para a área de transferência, com cabeçalho, separado por tabulação (cola direto em planilhas); quebras de linha dentro das células viram espaço |
| **CSV** | Separador `;`, UTF-8 com BOM (abre direto no Excel em português) |
| **Excel** | Arquivo `.xlsx` real: cabeçalho em negrito, primeira linha congelada, filtro automático e largura de coluna ajustada |
| **JSON** | Lista de objetos; colunas com nomes repetidos recebem sufixo para não se sobrescreverem |

### Nome do arquivo

O nome segue o padrão `schema.tabela_ddMMyyyy.extensão`, por exemplo `Sales.Orders_21102026.xlsx`.

- A tabela é a do **primeiro `FROM`** da consulta executada. Para `db.schema.tabela`, só schema e tabela entram.
- Sem schema na consulta (`FROM Clientes`), o nome usa `dbo`. Isso pode não corresponder ao schema real se o seu usuário tiver outro schema padrão.
- Sem `FROM` (ex.: `SELECT 1`), o nome é `resultado_ddMMyyyy.extensão`.
- Com `JOIN` ou subconsulta, vale o primeiro `FROM`.
- Caracteres inválidos em nome de arquivo viram `_`.

### Particularidades do Excel

- Colunas numéricas viram números. Valores com mais de 15 dígitos ficam como texto, para não perder precisão.
- Textos acima de 32.767 caracteres são cortados (limite do Excel).
- Acima de 1.048.575 linhas o botão avisa para usar CSV. Em resultado paginado, o navegador recebe o resultado completo para montar o arquivo, então resultados muito grandes consomem memória do navegador; prefira CSV nesses casos.
- Nulos viram células vazias.

## O que ainda não existe

O PRD prevê, mas **não estão implementados**: exportação como script T-SQL com `INSERT INTO`, importação de CSV, modo somente leitura e os recursos de administração e monitoramento (FR-06). A aplicação executa SQL arbitrário com as permissões do login informado.

## Atalhos

| Atalho | Ação |
|---|---|
| `F5` ou `Ctrl+Enter` | Executar |
| Duplo clique em tabela | `SELECT TOP` em nova aba |
| Clique em coluna | Insere o nome no editor |
| Botão direito | Menu de contexto do Object Explorer |
| `Esc` | Fecha o menu de contexto |
| Setas ↑ ↓ | Navegam no menu de contexto |
| Setas ← → na divisória | Redimensionam o Object Explorer; `Home` restaura |
