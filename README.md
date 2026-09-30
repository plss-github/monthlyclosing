# Fechamento Mensal — GLPI Plugin

**Autor:** Pellissari  
**Versão:** 1.0.0  
**Compatibilidade:** GLPI 11.0.x / PHP ≥ 8.2  
**Licença:** GPLv3+

---

## O que faz

Durante uma **janela de fechamento** configurada (intervalo de data/hora), o plugin:

- **Bloqueia o fechamento** de Chamados, Problemas e Mudanças — o status só pode avançar até *Solucionado*; a tentativa de marcar como *Fechado* é rejeitada com mensagem de aviso.
- **Desativa o autoclose** das entidades (`autoclose_delay = 0`) para que nenhum chamado seja fechado automaticamente no período.
- Ao encerrar a janela, **restaura** todos os valores de autoclose salvos em backup.

O bloqueio respeita a lista de **perfis-alvo** configurada: se a lista estiver vazia, todos os perfis são bloqueados; caso contrário, somente os perfis selecionados.

---

## Configuração de perfis

Em **Configuração → Plugins → Fechamento Mensal**:

| Campo | Função |
|---|---|
| Perfis que podem configurar o plugin | Quais perfis GLPI acessam esta tela (vazio = qualquer admin) |
| Perfis com fechamento bloqueado | Quais perfis têm o status "Fechado" bloqueado durante a janela (vazio = todos) |

---

## Gerenciamento de janelas

Menu **Gestão → Janelas de Fechamento**.

| Campo | Descrição |
|---|---|
| Nome | Identificação da janela (ex.: "Fechamento Jan/2025") |
| Início / Fim | Intervalo exato em que o bloqueio estará vigente |
| Status | Pendente → Ativa → Encerrada (gerenciado pelo cron) |

O cron **MonthlyClosing** (executado a cada minuto) ativa e desativa as janelas automaticamente.

---

## Instalação

1. Copie o diretório `monthlyclosing` para `GLPI_ROOT/plugins/`.
2. Em **Configuração → Plugins**, instale e ative o plugin.
3. Configure em **Configuração → Plugins → Fechamento Mensal** os perfis desejados.
4. Crie janelas em **Gestão → Janelas de Fechamento**.
5. Certifique-se de que o cron externo do GLPI está ativo para acionamento pontual.
