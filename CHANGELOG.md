# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/). O projeto ainda não publica versões numeradas; `config/config.php` declara `version = 1.0.0`.

## [Não lançado]

### Adicionado
- Exportação para Excel em `.xlsx` real (gerador próprio, sem dependências), com cabeçalho em negrito, primeira linha congelada, filtro automático e largura de colunas; testes em `tests/xlsx.test.js`.
- Nome dos arquivos exportados no padrão `schema.tabela_ddMMyyyy.ext` (CSV, Excel e JSON).
- Suporte às mensagens de `PRINT` e avisos do servidor na aba **Mensagens**; a aba abre sozinha quando a execução não devolve resultados.
- Implantação em IIS com o projeto inteiro em uma pasta: `index.php` na raiz e `deploy/iis-rewrite.xml`.
- Documentação completa em `docs/`.

### Alterado
- O botão **Excel** deixou de gerar uma tabela HTML `.xls` e passou a gerar `.xlsx`; acima de 1.048.575 linhas avisa para usar CSV.
- `Request` lê a URL original de `HTTP_X_ORIGINAL_URL` quando `REQUEST_URI` não existe (IIS).

### Corrigido
- No IIS, `HTTPS=off` era tratado como conexão segura, marcando o cookie de sessão como `Secure` em HTTP.

### Removido
- Gerador de planilha HTML (`toHtmlTable`).
