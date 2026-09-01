# 10 — Progresso e dashboards

## Definições de progresso

### Progresso de uma Competence Unit (CU)

Uma CU contém **N atividades** (E6), opcionalmente **1 avaliação** (E7), opcionalmente **conclusão manual** (v0.31.0) e — em curso V2 — **N lições** (E36).

- **Porcentagem** = `(licoes_concluidas + entregues + aprovada_na_avaliacao + concluida_manualmente) / (licoes_publicadas + N + tem_avaliacao + tem_conclusao_manual)` × 100
  - `licoes_concluidas` = nº de lições **publicadas** que o aluno marcou como concluídas
  - `licoes_publicadas` = nº de lições publicadas da CU (rascunho não conta em lado nenhum — o aluno não o vê, então ele não pode pesar no denominador)
  - `entregues` = nº de atividades com submissão pelo aluno
  - `aprovada_na_avaliacao` = 1 se avaliação final tem nota ≥ 6, 0 caso contrário
  - `tem_avaliacao` = 1 se a CU tem avaliação configurada, 0 caso contrário
- **Em curso V1 a fórmula é idêntica à anterior** — não existem lições, as duas contagens dão 0.
- **Concluída** quando % = 100.
- **Em andamento** quando % > 0 mas < 100.
- **Não iniciada** quando % = 0.

CUs sem nada avaliável retornam 0% e **contam na média** de CC/Curso, puxando-a para baixo (decisão com o PO em 2026-04-25, que substituiu a regra anterior de excluí-las — ela gerava percentual otimista frente ao que o aluno percebia).

> **A fórmula vive em dois lugares no código:** `StudentProgress` (PHP, por CU) e `CourseMatrix` (SQL agregado, que evita até 600 round-trips na matriz do professor). **As duas mudam sempre juntas** — quando divergiram, entre a v0.31.0 e o E36, o mesmo aluno aparecia com percentuais diferentes no dashboard e na matriz.

### Progresso de uma Core Competence (CC)
- Porcentagem = `(nº de CUs concluídas) / (nº total de CUs na CC) × 100`.

### Progresso de um Curso
- Porcentagem = `(nº de CUs concluídas no curso) / (nº total de CUs no curso) × 100`.

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
