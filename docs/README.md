# Documentação do SQL Web Manager

Cliente web para SQL Server, no estilo do SSMS, em PHP, HTML, CSS e JavaScript puros (sem Composer e sem dependências de front-end).

## Por onde começar

| Quero… | Leia |
|---|---|
| Instalar e abrir a aplicação | [README da raiz](../README.md) (quick start) |
| Usar a ferramenta (login, explorer, editor, exportação) | [Manual do usuário](usuario.md) |
| Configurar, publicar (Docker, Apache, IIS) e resolver problemas | [Configuração e operação](configuracao.md) |
| Entender os riscos e as proteções | [Segurança](seguranca.md) |
| Chamar a API HTTP | [Referência da API](api.md) |
| Entender como o código está organizado | [Arquitetura](arquitetura.md) |
| Alterar ou estender o código | [Guia de desenvolvimento](desenvolvimento.md) |
| Saber por que algo foi feito de certo jeito | [Decisões de arquitetura (ADRs)](adr/) |
| Ver o histórico de mudanças | [CHANGELOG](../CHANGELOG.md) |

O [PRD](../PRD.md) descreve a visão do produto e o roadmap. Ele inclui itens que **ainda não estão implementados** (veja [Fora do escopo atual](usuario.md#o-que-ainda-não-existe)).

## Convenções desta documentação

- O código é a fonte da verdade. Quando houver divergência com o README ou o PRD, vale o código.
- Caminhos são relativos à raiz do projeto.
- Mensagens e rótulos da interface aparecem em **negrito**, exatamente como na tela.
