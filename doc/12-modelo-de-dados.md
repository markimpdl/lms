# 12 — Modelo de dados (rascunho)

Todas as tabelas com multi-tenant carregam `tenant_id`. Tipos indicativos — refinar na implementação.

## Tabelas principais

### `tenants`
| coluna | tipo | notas |
|--------|------|-------|
| id | BIGINT PK | |
| owner_user_id | BIGINT FK → users.id | usuário professor dono |
| name | VARCHAR(150) | |
| active | BOOLEAN | |
| created_at, updated_at | TIMESTAMP | |

### `users`
Usuários de qualquer papel.
| coluna | tipo | notas |
|--------|------|-------|
| id | BIGINT PK | |
| email | VARCHAR(191) UNIQUE | |
| password_hash | VARCHAR(255) | |
| name | VARCHAR(150) | |
| role | ENUM('super_admin','teacher','student') | |
| language | ENUM('pt','en') | |
| active | BOOLEAN | |
| created_at, updated_at | TIMESTAMP | |

### `courses`
| coluna | tipo | notas |
|--------|------|-------|
| id | BIGINT PK | |
| tenant_id | FK → tenants.id | |
| name | VARCHAR(150) | |
| description | TEXT | |
| year | SMALLINT | ano civil (2025, 2026…) |
| language | ENUM('pt','en') | idioma do conteúdo |
| structure_version | TINYINT UNSIGNED | 1 = clássico, 2 = trilha (ADR-038). Default 1; escolhido na criação e **imutável** |
| archived | BOOLEAN | |

### `core_competencies`
| coluna | tipo | notas |
|--------|------|-------|
| id | BIGINT PK | |
| course_id | FK | |
| name | VARCHAR(150) | |
| position | INT | ordem |

### `competence_units`
| coluna | tipo | notas |
|--------|------|-------|
| id | BIGINT PK | |
| core_competency_id | FK | |
| name | VARCHAR(150) | |
| position | INT | |

### `contents`
Página HTML da CU (uma por CU). Em curso V2 (ADR-038) esta mesma linha é a **capa** da trilha — nenhum dado migrou entre os formatos.
| coluna | tipo | notas |
|--------|------|-------|
| id | BIGINT PK | |
| competence_unit_id | FK UNIQUE | |
| html | MEDIUMTEXT | sanitizado antes de salvar |
| updated_at | TIMESTAMP | |

### `lessons`
As telas da trilha de uma CU. **Só existem em curso V2** (ADR-038). Sem `tenant_id`: o tenant deriva de CU → CC → `courses.tenant_id`, igual `contents` e `activities`.
| coluna | tipo | notas |
|--------|------|-------|
| id | BIGINT PK | |
| competence_unit_id | FK | CASCADE |
| title | VARCHAR(200) | |
| html | MEDIUMTEXT | sanitizado antes de salvar |
| xp_value | INT UNSIGNED | 0 = sem XP |
| published | TINYINT(1) | rascunho não aparece pro aluno nem conta no progresso |
| position | INT UNSIGNED | **mesmo espaço de numeração de `activities.position`** dentro da CU |
| created_at / updated_at | DATETIME | |

Índice `(competence_unit_id, position)`. Item novo em V2 pega a posição por `MAX` sobre `lessons` **e** `activities` — usar só o `MAX` da própria tabela gera empate e ordem indefinida.

### `lesson_completions`
Aluno marcou a lição como concluída. PK composta dá idempotência.
| coluna | tipo | notas |
|--------|------|-------|
| lesson_id | FK | PK composta, CASCADE |
| student_user_id | FK | PK composta, CASCADE |
| completed_at | DATETIME | |

### `student_cu_unlocks`
Professor liberou uma CU específica para um aluno específico, furando a trava sequencial (ADR-039). Vale nos **dois** formatos de curso.
| coluna | tipo | notas |
|--------|------|-------|
| cu_id | FK | PK composta, CASCADE |
| student_user_id | FK | PK composta, CASCADE |
| granted_by_user_id | FK NULL | **SET NULL** — remover o professor não re-tranca a CU |
| granted_at | DATETIME | |

Não marca a CU como concluída e não afeta o cálculo de `%` — é override de visibilidade, lido dentro de `course_progression_state()`.

### `content_attachments`
| coluna | tipo | notas |
|--------|------|-------|
| id | BIGINT PK | |
| content_id | FK | |
| filename | VARCHAR(255) | |
| stored_path | VARCHAR(500) | |
| mime | VARCHAR(100) | |
| size_bytes | INT | |

### `activities`
| coluna | tipo | notas |
|--------|------|-------|
| id | BIGINT PK | |
| competence_unit_id | FK | |
| title | VARCHAR(200) | |
| instruction | MEDIUMTEXT | HTML sanitizado |
| type | ENUM('projeto','codigo') | |
| xp_value | INT | |
| submission_open | BOOLEAN | |
| allow_online_code_run | BOOLEAN | só para type='codigo' |

### `activity_submissions`
| coluna | tipo | notas |
|--------|------|-------|
| id | BIGINT PK | |
| activity_id | FK | |
| student_user_id | FK → users.id | |
| filename | VARCHAR(255) NULL | |
| stored_path | VARCHAR(500) NULL | |
| code_text | MEDIUMTEXT NULL | se submissão por código |
| feedback | TEXT NULL | |
| feedback_at | TIMESTAMP NULL | |
| created_at | TIMESTAMP | |

Constraint: UNIQUE(activity_id, student_user_id) — entrega única.

### `evaluations`
| coluna | tipo | notas |
|--------|------|-------|
| id | BIGINT PK | |
| tenant_id | FK → tenants.id | redundante por design — simplifica filtro multi-tenant |
| competence_unit_id | FK UNIQUE | uma por CU (ADR-007) |
| title | VARCHAR(200) | |
| instructions | TEXT NULL | texto opcional complementar ao PDF |
| pdf_path | VARCHAR(500) NULL | enunciado (até 10 MB — ADR-028) |
| xp_value | INT | liberado só quando nota ≥ 8 (ADR-002) |
| submission_open | BOOLEAN | |

Índices: UNIQUE(competence_unit_id), KEY(tenant_id).

### `evaluation_submissions`
| coluna | tipo | notas |
|--------|------|-------|
| id | BIGINT PK | |
| tenant_id | FK → tenants.id | redundante por design |
| evaluation_id | FK | |
| student_user_id | FK | |
| attempt | INT | 1, 2, 3… |
| filename | VARCHAR(255) NULL | |
| stored_path | VARCHAR(500) NULL | |
| grade | DECIMAL(3,1) NULL | 0.0–10.0 (ADR-015) |
| feedback | TEXT NULL | |
| feedback_at | DATETIME NULL | |
| retry_allowed | BOOLEAN DEFAULT 0 | forçado a 0 quando grade ≥ 6 |
| is_current | BOOLEAN | última tentativa vira current |
| created_at | DATETIME | |

Índices: UNIQUE(evaluation_id, student_user_id, attempt), KEY(student_user_id), KEY(tenant_id), KEY(evaluation_id, is_current).

### `enrollments`
| coluna | tipo | notas |
|--------|------|-------|
| id | BIGINT PK | |
| student_user_id | FK | |
| course_id | FK | |
| enrolled_at | TIMESTAMP | |

UNIQUE(student_user_id, course_id).

### `groups`
| coluna | tipo | notas |
|--------|------|-------|
| id | BIGINT PK | |
| tenant_id | FK | |
| name | VARCHAR(100) | ex.: Skills Challenge |

### `group_members`
| coluna | tipo | notas |
|--------|------|-------|
| group_id | FK | |
| student_user_id | FK | |
| PRIMARY KEY (group_id, student_user_id) | | |

### `xp_events`
| coluna | tipo | notas |
|--------|------|-------|
| id | BIGINT PK | |
| student_user_id | FK | |
| tenant_id | FK | |
| course_id | FK NULL | |
| source_type | ENUM('activity','evaluation','cu_manual','lesson') | |
| source_id | BIGINT | |
| value | INT | |
| created_at | TIMESTAMP | |

Índice composto em `(student_user_id, created_at)` para rankings por janela rolante.

UK em `(student_user_id, source_type, source_id)` torna todo crédito idempotente. `tenant_id` é o do **aluno**, não o do dono do curso (ADR-033).

`source_type`/`source_id` são polimórficos e **não têm FK** (ADR-020): quem apaga a origem é responsável por apagar o `xp_event` — ver `Lesson::delete`.

### `notifications`
| coluna | tipo | notas |
|--------|------|-------|
| id | BIGINT PK | |
| user_id | FK | |
| type | VARCHAR(50) | |
| title | VARCHAR(200) | |
| body | TEXT | |
| link | VARCHAR(500) | |
| read_at | TIMESTAMP NULL | |
| created_at | TIMESTAMP | |

### `audit_log`
Eventos sensíveis: login, criação de conta, entregas, feedback, alteração de nota, exclusão.
| coluna | tipo |
|--------|------|
| id | BIGINT PK |
| tenant_id | FK NULL |
| actor_user_id | FK NULL |
| event | VARCHAR(80) |
| payload_json | JSON |
| created_at | TIMESTAMP |

## Índices críticos

- `xp_events(student_user_id, created_at)` — ranking rolante.
- `activity_submissions(activity_id, student_user_id)` UNIQUE.
- `evaluation_submissions(evaluation_id, student_user_id, attempt)` UNIQUE.
- `notifications(user_id, read_at, created_at DESC)`.
- `enrollments(student_user_id, course_id)` UNIQUE.

## Regras de integridade

- Deletar um curso faz cascade nas CCs, CUs, conteúdos, atividades, avaliações, submissões e xp_events relacionados.
- Deletar um usuário aluno faz cascade nas submissões e matrículas.
- Deletar um tenant exige confirmação explícita do super-admin e faz cascade completo.
