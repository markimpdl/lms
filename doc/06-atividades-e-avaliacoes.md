# 06 — Atividades e avaliações

## Atividade

Tarefa durante a disciplina. **Sem nota** — o propósito é praticar e receber feedback.

### Propriedades
- Título e instrução (texto rico).
- Tipo: `quiz`, `pesquisa`, `formulario`, `projeto`, `codigo`.
- XP (inteiro) — valor fixo creditado ao aluno no momento da entrega.
- Arquivos aceitos: `zip`, `pdf`, `txt`. Máximo **3 MB**.
- Entrega: aberta ou fechada (toggle controlado pelo professor).
- Não há prazo automático.

### Entrega
- **Única.** Uma vez enviada, não pode ser substituída pelo aluno.
- Se a entrega estiver fechada, a submissão é bloqueada.
- Ao entregar, o aluno ganha o XP configurado (regra do doc 08).

### Feedback
- Professor lê o arquivo, escreve comentário textual.
- Salvar o feedback dispara notificação (email + sininho).
- Feedback não altera a entrega nem o XP.

### Atividade de código
- Tipo especial em que o aluno pode, além de anexar arquivo, colar ou digitar código e executá-lo via Judge0 (doc 07).
- A execução é apenas para o aluno testar; a correção continua manual.

---

## Avaliação

Prova final da CU. Tem nota.

### Propriedades
- Título.
- **PDF do enunciado** (upload feito pelo professor). Esse é o material que o aluno baixa.
- XP (inteiro) — só é concedido se a nota for ≥ 8/10.
- Arquivos aceitos na resposta: `zip`, `pdf`, `txt`. Máximo 3 MB.
- Entrega: aberta ou fechada (toggle do professor).
- No máximo **uma avaliação por CU**.

### Entrega
- O aluno baixa o PDF e envia um arquivo de resposta.
- Primeira tentativa sempre permitida enquanto a entrega estiver aberta.
- **Reenvio** só quando o professor marca "permitir nova tentativa" ao salvar o feedback. O aluno recebe notificação.
- Cada tentativa é armazenada no histórico; a "tentativa atual" é a mais recente.

### Correção
- Professor baixa o arquivo.
- Atribui nota de **0 a 10** (inteiro ou com uma casa decimal).
- Regras:
  - Nota ≥ 6 → **aprovado**. Marca a CU como concluída para fins de progresso.
  - Nota ≥ 8 → **libera XP** da avaliação, além de aprovação.
- Feedback textual obrigatório.
- Professor pode marcar "permitir nova tentativa" (habilitar reenvio).

### Notificações
- Aluno é notificado (email + sininho) quando:
  - Recebe feedback de avaliação (com nota).
  - Reenvio é liberado.

## Regras comuns a atividades e avaliações

- O professor pode **fechar** a entrega a qualquer momento; submissões existentes permanecem.
- O professor pode **editar** título, instrução, XP e PDF de enunciado enquanto não houver submissões. Depois de submissões, editar é permitido mas gera um aviso.
- Ao **excluir** uma atividade/avaliação com submissões, o sistema exige confirmação explícita; todas as submissões e XP relacionados são removidos (cascade).
