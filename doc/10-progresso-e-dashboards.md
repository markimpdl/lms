# 10 — Progresso e dashboards

## Definições de progresso

### Progresso de uma Competence Unit (CU)
- **Concluída** quando o aluno tem avaliação aprovada (nota ≥ 6) na CU.
- **Em andamento** quando há submissão de atividade ou avaliação, mas ainda não foi aprovado.
- **Não iniciada** quando não há nenhuma submissão.

### Progresso de uma Core Competence (CC)
- Porcentagem = `(nº de CUs concluídas) / (nº total de CUs na CC) × 100`.

### Progresso de um Curso
- Porcentagem = `(nº de CUs concluídas no curso) / (nº total de CUs no curso) × 100`.

CUs sem avaliação configurada são consideradas "não avaliáveis" e são ignoradas no cálculo do progresso.

## Dashboard do aluno

**Tela inicial após login:**
- Cartões dos cursos em que está matriculado, com barra de progresso e próxima CU sugerida.
- Bloco "Atividades pendentes": atividades com entrega aberta, sem submissão.
- Bloco "Feedback novo": últimas notificações de feedback/nota.
- Posição atual no ranking (geral).

**Dentro de um curso:**
- Lista de CCs com barras de progresso.
- Expandir CC mostra CUs com status (concluída/em andamento/não iniciada).

**Dentro de uma CU:**
- Barra de progresso da CU.
- Status de cada atividade (entregue/não entregue/com feedback).
- Status da avaliação (não entregue/entregue/aprovada com nota X).

## Dashboard do professor

**Tela inicial do tenant:**
- Total de cursos ativos, alunos ativos, entregas pendentes de correção (chamada à ação).
- Lista de submissões recentes (últimas 10).
- Alunos sem atividade recente (últimos 14 dias).

**Dentro de um curso:**
- Tabela de alunos × CUs, cada célula mostrando o status (ícone + cor):
  - verde: aprovado
  - amarelo: em andamento
  - cinza: não iniciada
- Filtro por grupo, ano, status.

**Dentro de uma CU:**
- Lista de alunos matriculados com:
  - Status da avaliação (nota, se houver).
  - Lista das atividades e status de cada uma.
- Ações rápidas: abrir/fechar entrega, ir para lista de submissões.

**Por atividade/avaliação:**
- Lista de submissões: aluno, data, arquivo, status (aguardando/feedback dado).
- Ação: abrir entrega para dar feedback/nota.

## Métricas exibidas

- % de alunos aprovados por CU.
- Nota média por avaliação.
- Tempo médio entre entrega e feedback (útil para o professor se autorregular).
- Distribuição de notas (histograma simples) — fase posterior.

## Mobile

Todos os dashboards têm variante responsiva. Em mobile, tabelas viram cartões empilhados; filtros colapsam em um drawer lateral.
