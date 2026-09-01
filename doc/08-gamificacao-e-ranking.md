# 08 — Gamificação e ranking

## Princípio

Nota acadêmica e XP são conceitos **separados**. A nota mede aprovação; o XP mede engajamento e mérito, e gera o ranking.

## Regras de XP

### Atividades
- O professor configura um valor de XP para cada atividade (inteiro, p.ex. 10).
- O aluno recebe o XP **ao entregar** a atividade, independentemente de feedback.
- A entrega é única; o XP é creditado uma única vez por atividade.

### Avaliações
- O professor configura um valor de XP para cada avaliação.
- O aluno recebe o XP **somente se a nota for ≥ 8/10** (80%).
- Se o aluno reenvia e melhora a nota de 7 para 9, o XP é concedido na correção da nova tentativa.
- Se a tentativa atual depois for substituída por uma nota < 8, o XP **não é retirado** (o mérito já foi registrado).

### Lições (só em curso V2)
- O professor configura um valor de XP para cada lição (0 = sem XP).
- O aluno recebe o XP **ao marcar a lição como concluída** — só abrir não conta.
- Desfazer a conclusão **revoga** o XP; apagar a lição também.

### Conclusão manual de CU
- Quando o professor habilita "Mark as completed" numa CU, o clique do aluno credita `competence_units.manual_completion_xp`.

### Registro
Cada crédito de XP gera um `xp_event` com: aluno, tenant, curso, origem (`activity` / `evaluation` / `cu_manual` / `lesson`), id da origem, valor, timestamp.

O `tenant_id` gravado é o do **aluno**, não o do dono do curso: em curso compartilhado (ADR-033) o XP tem de contar no tenant de quem estudou.

A unicidade por `(aluno, origem, id_da_origem)` torna todo crédito idempotente — clicar duas vezes, recarregar o POST ou reprocessar não duplica XP.

## Rankings

Três janelas de tempo:

| Janela | Cálculo |
|--------|---------|
| **Geral** | Soma de todos os `xp_events` do aluno. |
| **Últimos 7 dias** | Soma dos `xp_events` com timestamp >= `NOW() - 7 dias`. |
| **Últimos 30 dias** | Soma dos `xp_events` com timestamp >= `NOW() - 30 dias`. |

Janela rolante (sliding), não calendário.

## Filtros de ranking

O ranking é sempre exibido com dois filtros combináveis:

1. **Grupo** — um grupo específico, ou "todos os grupos".
2. **Ano civil** — 2025, 2026, etc. Filtra eventos de XP cujo timestamp cai no ano selecionado. "Todos os anos" também disponível.

Combinações válidas:
- Grupo específico + ano específico.
- Todos os grupos + ano específico.
- Grupo específico + todos os anos.
- Todos + todos.

## Tela de ranking

- Lista paginada de alunos.
- Colunas: posição, nome, grupo(s), XP na janela.
- Destaque no aluno logado (se for estudante).
- Filtros no topo: janela, grupo, ano.
- Opção de ver ranking **do curso específico** (considerando apenas XP gerado dentro daquele curso) além do ranking global do tenant.

## Empates

Em caso de empate no XP, desempate por:
1. Data do último `xp_event` (quem pontuou por último primeiro).
2. Nome (alfabético) como desempate final.

## Visibilidade

- Alunos veem todos os outros alunos do mesmo tenant no ranking. Não há modo anônimo.
- Professores veem o ranking completo do tenant.
- Alunos **não** veem o ranking de tenants diferentes, mesmo se estudarem em múltiplos.
