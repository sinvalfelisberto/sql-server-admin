# 0003 – Exportação no cliente e gerador `.xlsx` próprio

- **Status:** aceita
- **Contexto:** a exportação para Excel começou como uma tabela HTML com extensão `.xls`, que o Excel abre com aviso de formato e sem tipos de dados. O PRD (FR-05) pede `.xlsx`.
- **Decisão:** gerar o `.xlsx` no navegador (`public/assets/js/xlsx.js`), escrevendo o pacote Office Open XML como um ZIP *stored* (sem compressão), com CRC-32 próprio. CSV e JSON também são gerados no cliente a partir da grade atual (já filtrada e ordenada).
- **Alternativas consideradas:** biblioteca de terceiros (ex.: SheetJS), geração no servidor, manter o `.xls` em HTML. Por [ADR 0001](0001-sem-composer-nem-build.md), dependências foram evitadas; *inferido:* gerar no servidor exigiria reexecutar a consulta ou trafegar o resultado de volta.
- **Consequências:**
  - (+) Sem dependência nova e sem carga extra no servidor; exporta exatamente o que o usuário vê.
  - (+) Números viram números (até 15 dígitos), cabeçalho em negrito, filtro automático e linha congelada.
  - (−) Sem compressão, arquivos grandes ocupam mais espaço.
  - (−) Limites do formato tratados à mão: 1.048.575 linhas e 32.767 caracteres por célula.
  - (−) Estilos de data/hora não são aplicados (datas saem como texto).
  - Nome do arquivo: `schema.tabela_ddMMyyyy.ext`, derivado do primeiro `FROM` da consulta.
