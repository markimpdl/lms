# 09 — Notificações

## Canais

1. **Email** — via SMTP configurado pelo super-admin.
2. **Sininho in-app** — ícone no topo da interface, com contador de não-lidas e dropdown com últimas notificações.

Ambos os canais recebem as mesmas notificações, exceto quando marcado ao contrário na tabela abaixo.

## Entrega em tempo real?

**Não.** A atualização do sininho ocorre no **próximo carregamento de página**. Não há WebSocket, SSE ou polling agressivo.

## Gatilhos

| Evento | Destinatário | Email | Sininho |
|--------|--------------|-------|---------|
| Professor cria conta para o aluno | Aluno | Sim (com credenciais iniciais) | Não (aluno ainda não logou) |
| Aluno matriculado em novo curso | Aluno | Sim | Sim |
| Nova atividade publicada na CU | Alunos matriculados | Opcional (configurável) | Sim |
| Feedback em atividade | Aluno autor | Sim | Sim |
| Nota lançada em avaliação | Aluno autor | Sim | Sim |
| Reenvio de avaliação liberado | Aluno autor | Sim | Sim |
| Professor fechou entrega | Alunos da CU | Não | Sim |
| Nova submissão recebida | Professor | Sim (digest diário) | Sim |

**Digest diário** para o professor: um email por dia às 20h local listando todas as submissões do dia, em vez de um email por submissão.

## Modelo da notificação

Cada notificação guarda:
- Destinatário (user_id).
- Tipo (enum: feedback_activity, grade_evaluation, new_content, retry_enabled, etc.).
- Título curto.
- Corpo (texto ou HTML pequeno).
- Link (URL interna para a ação/tela relevante).
- `read_at` (null se não lida).
- Timestamp de criação.

## Comportamento do sininho

- Contador mostra quantidade de não-lidas.
- Dropdown mostra as 10 mais recentes, ordenadas por data desc.
- Clicar numa notificação:
  1. Marca como lida.
  2. Redireciona para o link associado.
- Opção "Marcar todas como lidas".
- Tela "Todas as notificações" com paginação.

## Email

- Templates em HTML com versão texto fallback.
- Templates bilíngues (PT/EN) — escolhe conforme o **idioma do curso relacionado à notificação**. Para emails sem contexto de curso (boas-vindas, recuperação de senha), usa o idioma do perfil do usuário.
- Remetente: configurável por tenant (com fallback para remetente global da plataforma).
- Link no email sempre aponta para a mesma URL interna da notificação correspondente.

## Falhas de envio de email

- Se SMTP falhar, a notificação no sininho **ainda é criada**.
- Log de falhas de email em uma tabela separada para inspeção do super-admin.
- Tentativa de reenvio automático não é obrigatória nesta fase.
