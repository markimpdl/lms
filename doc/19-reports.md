# Reports PDF (E26 — F17)

> Geração automática de PDF formal pós-feedback em avaliações Actvet+LO. Entregue em v0.23.0.

## TL;DR

- **Quem usa:** professor com `is_actvet=1`, curso `grading_mode='learning_outcomes'` E `report_mode='skill_hub'`. Aluno **nunca** vê o PDF nem o link.
- **Quando dispara:** após o professor lançar a 5ª nota de LO no feedback (`gradeByLo` retorna `'ok'`), trigger automático chama `ReportService::generate(submissionId)`.
- **Onde fica:** `storage/reports/eval_{evalId}_student_{studentId}_attempt_{N}.pdf` (gitignored; servido só via endpoint PHP).
- **Re-correção:** sobrescreve o arquivo (path determinístico).
- **Template Skills Hub** entregue pelo PO em formato Word "Save as Web Page Filtered" → migrado pra `public/assets/report-templates/skill_hub/`.

## Arquitetura

```
Professor → /teacher/.../submission (POST feedback)
       │
       ▼
EvaluationSubmissionService::gradeByLo
       │
       ├─ Database::tx { REPLACE 5 LO grades + applyGrade }  ← commitado
       │
       └─ Trigger best-effort (fora da tx):
              ReportService::generate(submissionId)
                │
                ├─ loadContext: 1 query JOIN + LearningOutcome::find{ByCu, GradesBySubmission}
                ├─ Validações: Actvet + LO + report_mode=skill_hub + 5 grades
                ├─ buildVariables: {{NAME_*}}, {{WORKLOAD_UC}}, {{AVG_LEARN}}, {{LEARN_X_*}}
                ├─ strtr template HTML
                └─ mPDF render → storage/reports/eval_X_student_Y_attempt_N.pdf
              │
              └─ Se 'ok': UPDATE evaluation_submissions.report_pdf_path
```

Falha do mPDF → log via `error_log`; feedback fica intacto. UI mostra link "Baixar Report" só quando `report_pdf_path !== NULL`.

## Renderer: mPDF (E30-01, 2026-04-27)

Renderer original era **dompdf v3** (E26 / v0.23.0). Trocado pra **mPDF v8.3** em E30-01 porque:

- **Fidelidade superior:** mPDF lida melhor com tabelas complexas, fontes embutidas (TTF), CSS3 moderno
- **Mesmo footprint:** PHP-puro, composer-installable, compatível com Hostinger compartilhado
- **Sem custo recorrente:** descartamos serviço externo (PDFShift/DocRaptor) por orçamento

**Requisito de produção:** extensão PHP `gd` precisa estar habilitada. Hostinger shared tem por default em PHP 8.x; verificado em deploy.

**Configuração consolidada:**
- `tempDir` confinado a `storage/reports/_mpdf-tmp/` (mPDF cria sozinho; .htaccess herda do parent)
- `default_font: dejavusans` (built-in do mPDF, suporta acentos PT-BR)
- `format: A4` portrait, margens 15mm
- `SetBasePath` aponta pro template dir (assets relativos resolvem dali)
- Imagens remotas (URLs http://) ignoradas pela config padrão

## Variáveis do template (catálogo)

| Variável | Origem | Formato |
|---|---|---|
| `{{NAME_COURSE}}` | `courses.name` | string (HTML-escapada) |
| `{{NAME_STUDENT}}` | `users.name` (aluno) | string |
| `{{NAME_UC}}` | `competence_units.name` | string |
| `{{WORKLOAD_UC}}` | `competence_units.workload_hours` | `30h` |
| `{{AVG_LEARN}}` | média dos 5 LO scores × 10 | inteiro quando exato (`85`); 1 decimal só se necessário (`85,2`) |
| `{{LEARN_1_NAME}}` … `{{LEARN_5_NAME}}` | `learning_outcomes[N-1].description` | string |
| `{{LEARN_1_SCORE}}` … `{{LEARN_5_SCORE}}` | grade × 10 | inteiro sempre (DECIMAL(3,1) × 10) |

**Variáveis fora do catálogo** (ex.: `{{NOT_DEFINED}}` no template) ficam **literais** no PDF — debug-friendly (decisão consolidada na spec).

## Defesa em camadas (consistente com E25)

| Layer | Onde | Defesa |
|---|---|---|
| **UI** | `course_progression_fields.php` | Select `report_mode` só visível em Actvet+LO; Alpine `x-show` reativo |
| **Server-side validate** | `TeacherCoursesController::validate` | Força `'disabled'` se `!isActvet \|\| gradingMode !== 'learning_outcomes'` |
| **Trigger gate** | `ReportService::generate` | Retorna `'not_eligible'` ou `'not_ready'` silenciosamente (não roda renderer) |
| **mPDF gated** | `Mpdf` config | `tempDir` confinado a `storage/reports/_mpdf-tmp/` + `SetBasePath` no template_dir; sem fetch de URLs remotas (config default) |
| **Endpoint download** | `report-pdf.php` | Tenant ownership via `findForGrading` + `realpath` confinado a `LMS_ROOT/storage/reports` |

## Edge cases tratados

- **Curso vira não-LO depois com PDFs gerados**: `report_pdf_path` permanece no DB, mas próxima geração não dispara (force `report_mode='disabled'`). Endpoint ainda serve PDFs antigos enquanto path estiver no DB. **Aceitável** — re-correção em curso não-LO seria via `gradeByLo` que nem é chamado.
- **Re-correção em LO**: ReportService usa naming determinístico (`eval_X_student_Y_attempt_N.pdf`) — sobrescreve o arquivo + UPDATE (path não muda; só mtime).
- **Template v1 (Word + dompdf)** rendia diferente do PDF de referência — markup MSO + limites do dompdf. Resolvido em **E30** (renderer trocado pra mPDF + template reescrito limpo, ver story #385). Histórico fica documentado pra contexto.
- **Performance**: render síncrono no request pode ficar lento (1-3s) com template carregado. Anotado em pendências; se virar problema, mover pra cron/queue.
- **Aluno vê tudo de LO mas nada de report**: o aluno sabe que existem critérios (E25-05) e vê suas notas, mas ignora a existência do PDF gerado.

## Pendências relacionadas

Ver `doc/99-pendencias-tecnicas.md`:
- `[E26-04]` bootstrap-icons CSS não carrega fora de student area (afeta visual do botão `<i>` em `/teacher/`; já mitigado removendo o ícone do botão Baixar Report)

## Próximo épico

E27 (F18) — Dark mode aluno. Não relacionado a Reports.

Spec operacional em `doc/15-roadmap-pos-mvp.md` F18 (E27).
