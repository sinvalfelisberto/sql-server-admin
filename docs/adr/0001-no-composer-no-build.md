# 0001 – Sem Composer e sem build de front-end

- **Status:** aceita
- **Contexto:** a ferramenta é um cliente de SQL Server pequeno, pensado para rodar em servidores com acesso limitado (há implantação em IIS sem controle do document root). O README define o projeto como "PHP, HTML, CSS e JavaScript puros, sem dependências de front-end nem de Composer".
- **Decisão:** autoload PSR-4 próprio (`app/bootstrap.php`), funções livres carregadas com `require`, JavaScript em arquivos estáticos sem bundler, e utilitários próprios (`pipe`, `Result`, gerador de `.xlsx`) no lugar de bibliotecas.
- **Alternativas consideradas:** Composer com framework (Laravel/Slim) e bundler (Vite). *Inferidas:* descartadas pelo custo de instalação e manutenção em ambientes restritos.
- **Consequências:**
  - (+) Implantação é copiar arquivos; nada para instalar ou atualizar.
  - (+) Superfície de dependências, e de vulnerabilidades de terceiros, mínima.
  - (−) Funcionalidades comuns (roteador, container, ZIP/XLSX) são mantidas aqui e precisam de testes próprios.
  - (−) Sem checagem automática de tipos ou lint de front-end.
