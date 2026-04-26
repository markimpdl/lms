# Learning Outcomes (E25 — F16)

> Modo de avaliação alternativo, exclusivo para tenants Actvet. Notas por critério (5 LOs por UC) com média = nota final. Entregue em v0.22.0.

## TL;DR

- **Quem usa:** professor com tenant `is_actvet=1` e curso `grading_mode='learning_outcomes'`.
- **O que muda:** avaliação tipo `projeto` ganha 5 inputs de nota (um por LO) em vez de 1 input de nota única. Média vai pra `evaluation_submissions.grade` (compat com queries existentes).
- **Restrições:**
  - **Atvet-only**: não-Actvet força `'grade'` server-side.
  - **5 LOs exatos por UC**: forma única (sem 4, sem 6).
  - **Quiz indisponível** em LO mode (auto-corrigido vs feedback manual por critério).
- **Não muda:** atividade-quiz, regras de XP (≥8), retry (≥6), histórico de tentativas, ranking.

## Schema

```sql
-- 1 coluna nova
ALTER TABLE courses ADD COLUMN grading_mode ENUM('grade','learning_outcomes') NOT NULL DEFAULT 'grade';

-- 2 tabelas novas
learning_outcomes (id, cu_id FK CASCADE, description VARCHAR(500), position INT)
evaluation_submission_lo_grades (PK composite submission_id+lo_id, FKs CASCADE, CHECK 0..10)
```

`learning_outcomes` cascateia em `competence_units` (delete CU apaga LOs). `evaluation_submission_lo_grades` cascateia em ambos `evaluation_submissions` e `learning_outcomes` — apagar uma submissão ou um LO apaga as notas associadas.

## Defesa server-side em camadas

Conceito introduzido em E24 e reforçado neste épico: **toda regra de visibilidade no UI também é validada no backend**, pra resistir a POST manipulado.

| Page / Handler | Camada 1 | Camada 2 | Camada 3 |
|---|---|---|---|
| `/teacher/courses/{id}/edit` (E25-01) | `validate(input, isActvet)` força `'grade'` se !Actvet | `Tenant::findById($tid)` | — |
| `/teacher/cu/{id}/learning-outcomes` (E25-02) | tenant ownership via `CompetenceUnit::findForTenant` | `is_actvet=1` | `course_grading_mode='learning_outcomes'` |
| `/teacher/cu/{id}/evaluation/new` (E25-04) | `$isLoMode` do CU | bloqueia `type=quiz` se LO | — |
| `/teacher/evaluation/{id}/submission/{sid}` (E25-03) | detecta `grading_mode` | bloqueia handler se UC sem 5 LOs | grade calculada server-side |

## Padrão `applyGrade` (refactor em E25-03)

`EvaluationSubmissionService::applyGrade(PDO, ...)` extraído como helper privado pra ser reusado entre `grade()` (clássico) e `gradeByLo()` (LO mode). Lógica core (clamp retry ≥6, UPDATE submission grade, XP award ≥8 idempotente) num **único lugar** — independente de como a média é calculada.

```php
// grade() — caller passa 1 nota
public static function grade(...): array {
    return Database::tx(fn(PDO $pdo) => self::applyGrade($pdo, ..., $grade, ...));
}

// gradeByLo() — caller passa N grades, service grava + média + delega
public static function gradeByLo(..., array $loGrades, ...): array {
    return Database::tx(function (PDO $pdo) use (...) {
        // REPLACE INTO evaluation_submission_lo_grades (5 rows)
        // calcula média (DECIMAL(3,1))
        return self::applyGrade($pdo, ..., $average, ...);
    });
}
```

`REPLACE INTO` aproveita o PK composite — re-correção é idempotente sem `DELETE` explícito.

## Audit chain de `course.grading_mode`

Pra evitar carregar o curso em cada page, adicionado o campo nos SELECTs explícitos relevantes (padrão E24 audit):

| Model | Coluna alias | Usado por |
|---|---|---|
| `Course::findForTenant` | `grading_mode` | course edit |
| `StudentCurriculum::buildForCourse` | `grading_mode` | (preparado pra futuro consumo no aluno) |
| `CompetenceUnit::findForTenant` | `course_grading_mode` | CU show, LO cadastro, evaluation new |
| `Evaluation::findForTenant` | `course_grading_mode` | evaluation edit |
| `EvaluationSubmission::findForGrading` | `grading_mode` | feedback do professor |
| `EvaluationSubmission::findForStudentEvaluation` | `grading_mode` | aluno vê critérios |

## Edge cases tratados

- **Curso vira LO depois** com UCs já existentes sem 5 LOs: page de feedback bloqueia POST com alerta + link pro cadastro (E25-03). Aluno em LO mode com UC sem LOs: bloco "Critérios avaliados" simplesmente não aparece (defesa silenciosa em E25-05).
- **Evaluation tipo quiz pré-existente** quando curso vira LO: edit preserva o type visível no select (não esconde) — type é imutável, só o cadastro novo bloqueia (E25-04).
- **Re-correção** (professor edita feedback): `REPLACE INTO` sobrescreve as 5 rows + recomputa média + reenvia XP idempotente (`XpEvents::awardEvaluation` já é gated por unique key).
- **Editar critérios após feedback existir**: `LearningOutcome::replaceForCu` cascateia DELETE em `evaluation_submission_lo_grades` via FK CASCADE — apaga as notas. **Aceitável no MVP** (raro re-cadastrar LOs após corrigir); se virar problema, gatear com confirmação UI.

## Próximo épico

E26 (F17) — Reports PDF. Depende inteiramente de LO (5 LOs + grades por LO compõem o template).

Spec operacional em `doc/15-roadmap-pos-mvp.md` F17.
