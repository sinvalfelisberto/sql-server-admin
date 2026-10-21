# Product Requirements Document (PRD)

## SQL Server Web Management Studio (PHP-SSMS Web)

### 1. Visão Geral do Produto

* **Nome do Projeto (Provisório):** PHP-SSMS Web
* **Objetivo:** Fornecer uma interface web moderna, leve e responsiva desenvolvida em PHP para administração e execução de queries em instâncias Microsoft SQL Server (on-premises e Azure SQL), reproduzindo a experiência ágil de clientes desktop como o SSMS diretamente pelo navegador.
* **Público-Alvo:** Desenvolvedores PHP/Full Stack, DBAs, DevOps e times de suporte que trabalham em ambientes multiplataforma (Linux, macOS, Windows) e precisam de acesso rápido e seguro a instâncias SQL Server.

---

### 2. Justificativa e Oportunidade

* **Restrição de SO do SSMS:** O SSMS original é restrito ao ecossistema Windows e requer instalação pesada de software local.
* **Carência no Ecossistema PHP:** Ferramentas consagradas como o phpMyAdmin são focadas primordialmente em MySQL/MariaDB. Utilitários genéricos (ex.: Adminer) oferecem suporte limitado ao SQL Server, sem suporte nativo a delimitadores de lote (`GO`), múltiplos result sets, granularidade de schemas, atalhos de produtividade de DBAs e menus de contexto práticos.

---

### 3. Personas de Usuário

| Persona | Perfil | Caso de Uso Principal |
| :--- | :--- | :--- |
| **Desenvolvedor Web** | Desenvolve em Linux/macOS com PHP/Laravel/Symfony. | Inspecionar dados, rodar scripts de migração, testar consultas e visualizar DDLs rapidamente via browser. |
| **Administrador / DBA** | Responsável por integridade, desempenho e segurança. | Inspecionar metadados de tabelas, checar índices, auditar consumo de espaço e gerenciar acessos. |
| **Suporte Técnico / N2** | Atua na sustentação e correção de dados operacionais. | Fazer consultas pontuais (`SELECT TOP 100`), copiar nomes de objetos e exportar relatórios para CSV/Excel. |

---

### 4. Requisitos Funcionais (FR)

#### FR-01: Autenticação e Gestão de Conexões
* **Múltiplos Perfis:** Configuração, teste e armazenamento de perfis de conexão (Host, Porta, Banco Padrão, Criptografia/Encrypt, TrustServerCertificate).
* **Modos de Autenticação:**
  * SQL Server Authentication (Usuário e Senha).
  * Windows Authentication / Kerberos (quando aplicável ao ambiente de hospedagem).
  * Azure SQL Database (suporte a Service Principal / Entra ID).
* **Persistência Segura:** Credenciais armazenadas em sessão de PHP criptografada em memória, sem persistência de credenciais em texto puro.

#### FR-02: Navegador de Objetos (Object Explorer) & Menus de Contexto
* **Árvore de Metadados:**
  * Instância $\rightarrow$ Bancos de Dados $\rightarrow$ Schemas (ex.: `dbo`, `sales`, etc.).
  * Tabelas, Views, Stored Procedures, Functions (Scalar e Table-Valued), Triggers e Sequences.
  * Lazy loading sob demanda de colunas, índices, chaves primárias e constraints.

* **Menu de Contexto (Clique com o Botão Direito):**
  * **Em Tabelas e Views:**
    * **SELECT TOP 100:** Abre uma nova aba no editor já preenchida com a query `SELECT TOP 100 * FROM [schema].[objeto]` e a dispara imediatamente.
    * **SELECT \* SEM TOP:** Gera `SELECT * FROM [schema].[objeto]` no editor, disparando alerta/confirmação prévia caso a tabela possua volumetria elevada (prevenção contra sobrecarga de memória).
    * **VER DDL:** Gera e abre em uma aba/modal o script DDL completo (`CREATE TABLE` ou `CREATE VIEW`), contemplando colunas, tipos de dados, chaves primárias/estrangeiras, defaults e índices.
    * **Informações da Tabela:** Exibe modal detalhado de metadados contendo:
      * Quantidade aproximada de linhas (`sys.dm_db_partition_stats`).
      * Espaço em disco alocado (dados vs. índices vs. espaço não utilizado).
      * Lista completa de índices (Clustered, Non-Clustered) e chaves estrangeiras relacionadas.
    * **Copiar o Nome da Tabela:** Copia para a área de transferência do usuário o identificador qualificado (ex.: `[dbo].[Usuarios]`) ou nome simples.
    * **Atualizar:** Dispara requisição assíncrona para atualizar apenas os metadados do nó selecionado sem precisar recarregar toda a árvore.
  * **Em Bancos de Dados:**
    * **Nova Consulta:** Abre um novo editor com o contexto explicitado (`USE [database]`).
    * **Ver DDL / Script Database:** Exibe o script de criação da base com collation e opções ativas.
    * **Atualizar:** Recarrega todos os objetos filhos do banco selecionado.

#### FR-03: Editor T-SQL e Atalhos de Teclado
* **Interface do Editor:** Monaco Editor ou CodeMirror com realce de sintaxe T-SQL, numeração de linhas e autocomplete contextual para schemas, tabelas e colunas.
* **Atalhos de Execução de Consulta:**
  * **`F5`**: Executa o script inteiro ou somente o bloco de texto selecionado pelo cursor (comportamento clássico SSMS).
  * **`Ctrl + Enter` (ou `Cmd + Enter` no macOS)**: Executa a consulta ativa ou trecho em foco (padrão de ferramentas web).
* **Divisão de Lotes (`GO`):** Parser para segmentar e disparar múltiplos batches de forma sequencial respeitando o delimitador nativo `GO`.
* **Múltiplos Result Sets:** Capacidade de renderizar e alternar entre mais de um grid de resultados quando a query contiver múltiplas instruções de `SELECT`.
* **Painel de Mensagens e Diagnóstico:** Exibição clara de saídas de comando `PRINT`, número de linhas afetadas por comando e avisos/erros T-SQL com número da linha.
* **Histórico de Comandos:** Armazenamento local das últimas consultas executadas na sessão para repetição e auditoria rápida.

#### FR-04: Grade de Dados Interativa (Data Grid)
* **Paginação Eficiente:** Paginação server-side construída com `OFFSET ... FETCH NEXT`.
* **Edição Inline / CRUD:** Edição direta em células com validação prévia de chave primária para gerar o comando de `UPDATE`.
* **Filtros e Ordenação:** Filtros visuais rápidos por coluna sem exigir digitação de SQL manual.

#### FR-05: Exportação e Importação de Dados
* **Exportação:** Suporte a saídas em CSV, JSON, Excel (`.xlsx`) e script T-SQL com instruções `INSERT INTO` agrupadas em lote.
* **Importação:** Upload de CSV com mapeamento visual de colunas para inserção estruturada.

#### FR-06: Administração e Monitoramento
* **Monitor de Sessões Ativas:** Visualização de processos em execução via `sys.dm_exec_requests` e `sys.dm_exec_sessions`, exibindo status, locks e tempo de execução.
* **Gestão de Logins e Permissões:** Interface para criação e manutenção de Logins e atribuição a Database Roles predefinidas (ex.: `db_owner`, `db_datareader`, `db_datawriter`).

---

### 5. Requisitos Não Funcionais (NFR)

* **NFR-01 (Desempenho):** Tempo de resposta para carregamento de nós de metadados inferior a 200ms em rede local; carregamento assíncrono (Lazy Loading).
* **NFR-02 (Segurança):**
  * Proteção mandatória contra Cross-Site Scripting (XSS) e Cross-Site Request Forgery (CSRF).
  * Conexões forçadas com TLS/SSL (`Encrypt=yes`).
  * Modo **Somente Leitura** (*Read-Only Mode*) configurável globalmente para conexões com bases de produção críticas.
* **NFR-03 (Compatibilidade e Stack):**
  * Versão PHP: $\ge$ 8.2 (utilizando driver oficial Microsoft `pdo_sqlsrv` ou `sqlsrv`).
  * Microsoft ODBC Driver for SQL Server (versões 17 ou 18).
  * Compatibilidade de Banco: Microsoft SQL Server 2016, 2017, 2019, 2022 e Azure SQL Database.
* **NFR-04 (Empacotamento):** Disponibilização de `Dockerfile` e `docker-compose.yml` oficiais com todas as dependências ODBC compiladas.

---

### 6. Arquitetura Técnica Proposta

```
┌─────────────────────────────────────────────────────────────┐
│                       Frontend Web                          │
│        Tailwind CSS + Alpine.js / Vue.js 3 (SPA Leve)       │
│   • Monaco Editor (Atalhos: F5 / Ctrl+Enter)                │
│   • Custom Context Menu (SELECT TOP 100, DDL, Infos, etc.)  │
└──────────────────────────────┬──────────────────────────────┘
                               │ JSON / REST API
┌──────────────────────────────▼──────────────────────────────┐
│                    Backend PHP 8.2+                         │
│            Slim Framework 4 ou Laravel Mini                 │
├─────────────────────────────────────────────────────────────┤
│  Driver: Microsoft ODBC Driver + pdo_sqlsrv                 │
└──────────────────────────────┬──────────────────────────────┘
                               │ Porta TCP 1433 (TLS/SSL)
┌──────────────────────────────▼──────────────────────────────┐
│              Microsoft SQL Server / Azure SQL               │
└─────────────────────────────────────────────────────────────┘
```

---

### 7. Roadmap de Lançamento

```
MVP (Fase 1)              Produtividade (Fase 2)       Admin & Escala (Fase 3)
┌─────────────────────┐   ┌────────────────────────┐   ┌────────────────────────┐
│• Auth pdo_sqlsrv    │   │• Context Menu Botão    │   │• Monitor de sessões    │
│• Árvore de Objetos  │──>│  Direito (DDL, TOP 100)│──>│  (sys.dm_exec_*)       │
│• Editor T-SQL       │   │• Infos de Tabela       │   │• Gestão de Logins/Roles│
│• Atalhos F5 e       │   │• Copiar nome do objeto │   │• Suporte a Tema Escuro │
│  Ctrl + Enter       │   │• Exportação CSV/Excel  │   │• Read-Only Mode global │
└─────────────────────┘   └────────────────────────┘   └────────────────────────┘
```

---

### 8. Critérios de Aceite e Métricas de Sucesso

1. **Agilidade nos Atalhos:** Pressionar `F5` ou `Ctrl + Enter` no editor web executa a query sem acionar a recarga padrão da página do navegador.
2. **Fluidez no Menu de Contexto:** O clique com botão direito em qualquer tabela ou view abre o menu em menos de 50ms, oferecendo opções de `SELECT TOP 100`, `SELECT * SEM TOP`, `VER DDL`, `Informações da Tabela`, `Copiar nome` e `Atualizar`.
3. **Deploy Prático:** A aplicação sobe pronta para conexão em ambiente conteinerizado via comando único `docker compose up -d` em menos de 3 minutos.