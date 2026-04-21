# 03 — Domínio e hierarquia

## Hierarquia de conteúdo

```
Tenant (Professor)
└── Curso
    └── Core Competence
        └── Competence Unit (matéria)
            ├── Conteúdo (página HTML)
            ├── Atividade(s)
            └── Avaliação (uma por CU)
```

- Um **curso** tem 1..N core competences.
- Uma **core competence** tem 1..N competence units.
- Uma **competence unit** tem 1 página de conteúdo, 0..N atividades e 0 ou 1 avaliação.
- A ordem das CCs dentro de um curso e das CUs dentro de uma CC é definida pelo professor.

## Pessoas

```
Tenant (Professor) ──┬── cadastra ──> Aluno
                     └── cria ──────> Grupo ──── contém ──> Aluno
                                                            │
                                            matriculado em ─┘
                                                 │
                                                 v
                                               Curso
```

- Um **aluno** é cadastrado dentro de um tenant, mas pode estar matriculado em cursos de outros tenants. Nesse caso, o mesmo usuário (mesmo login) tem matrículas independentes.
- Um **grupo** pertence a um tenant. Um aluno pode estar em vários grupos simultaneamente.
- Matrícula (`enrollment`) liga um aluno a um curso e carrega o progresso daquele aluno naquele curso.

## Entregas e avaliações

```
Aluno
├── ActivitySubmission  ──── pertence a ───> Atividade
└── EvaluationSubmission ──── pertence a ──> Avaliação
         │
         └── pode ter múltiplas tentativas se o professor permitir
```

- Atividade: **uma** submissão por aluno. Se a entrega estiver fechada, não aceita.
- Avaliação: **uma** tentativa inicial. Reenvio só é habilitado quando o professor marca "permitir nova tentativa" no feedback.

## Gamificação

```
Aluno ── acumula ──> XP Event
                     ├── origem: atividade (ao entregar)
                     └── origem: avaliação (quando nota ≥ 8)
```

Cada evento de XP guarda: aluno, origem (activity/evaluation), id da origem, valor, data. Rankings são calculados por agregação sobre essa tabela.

## Relacionamentos-chave (resumo)

| Relação | Cardinalidade |
|--------|----------------|
| Tenant → Curso | 1 : N |
| Curso → Core Competence | 1 : N |
| Core Competence → Competence Unit | 1 : N |
| Competence Unit → Conteúdo | 1 : 1 |
| Competence Unit → Atividade | 1 : N |
| Competence Unit → Avaliação | 1 : 0..1 |
| Aluno ↔ Curso | N : N via `enrollments` |
| Aluno ↔ Grupo | N : N via `group_members` |
| Aluno → XP Event | 1 : N |
