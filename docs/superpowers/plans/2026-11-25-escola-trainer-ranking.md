# Escola e trainer no aluno — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gravar escola (texto com sugestões) e flag trainer no aluno, e filtrar/exibir ambos no ranking.

**Architecture:** Duas colunas novas em `users`. Normalização da escola num método estático puro de `Student`. `RankingService` ganha filtros `school` e `trainer` no mesmo padrão de `group_id`; telas de ranking (professor e aluno) ganham dois selects, coluna Escola e selo Trainer.

**Tech Stack:** PHP 8.3 sem framework, PDO/MySQL (MariaDB em prod), Bootstrap 5.

**Spec:** `docs/superpowers/specs/2026-11-25-escola-trainer-ranking-design.md`

## Global Constraints

- `declare(strict_types=1)`, prepared statements, zero concatenação de input em SQL.
- Toda query do professor filtra `tenant_id`; ranking do aluno usa o tenant do aluno.
- Toda string visível via `__t()` (placeholders `:key`), chaves em `lang/pt.php` e `lang/en.php`.
- Saída dinâmica sempre com `e()`.
- Mudança em `install/schema.sql` só via `/mysql-schema`, idempotente (padrão `information_schema.COLUMNS` + `PREPARE` já usado nas linhas ~740–850).
- `php -l` em todo PHP alterado antes de commit. Sem DB local: SQL é validado por leitura + smoke em prod (batch no fim).
- Sem `?? 'default'` silencioso em `Student::update`.

## Review Focus

1. Escola com espaços/maiúsculas diferentes ("Al Noor ", "al noor") → mesma escola no filtro e uma só sugestão.
2. `?school=` com valor que não existe no tenant (ou de outro tenant) → ignorado, ranking sem filtro de escola.
3. `?trainer=` com valor inválido → tratado como "todos".
4. Paginação e troca de janela (7d/30d/all) preservam `school` e `trainer`.
5. Editar aluno sem tocar no checkbox trainer desmarcado → grava 0 (checkbox ausente no POST = não trainer), não mantém o valor antigo.

---

### Task 1: Schema

**Files:**
- Modify: `install/schema.sql` (tabela `users` linha ~59 + bloco idempotente de ALTER junto aos demais)

- [ ] **Step 1:** Rodar `/mysql-schema` para adicionar em `users`, após `id_document`:
  - `school VARCHAR(150) NULL DEFAULT NULL`
  - `is_trainer TINYINT(1) NOT NULL DEFAULT 0`
  - índice `KEY idx_users_tenant_school (tenant_id, school)`
  - no `CREATE TABLE` e como `ALTER` condicional (information_schema) para bancos existentes.
- [ ] **Step 2:** Conferir que o bloco roda 2x sem erro (leitura: cada ALTER protegido por COUNT=0).
- [ ] **Step 3:** Commit `feat: colunas school e is_trainer em users`

### Task 2: Model + cadastro do aluno

**Files:**
- Modify: `src/models/Student.php` (`findForTenant`, `create`, `update`, novo `normalizeSchool`, novo `listSchools`)
- Modify: `src/services/StudentProvisioningService.php` (create)
- Modify: `src/controllers/TeacherStudentsController.php` (update)
- Modify: `src/pages/teacher/students/new.php`, `src/pages/teacher/students/show.php`
- Modify: `lang/pt.php`, `lang/en.php`

**Interfaces:**
- Produces:
  - `Student::normalizeSchool(string $raw): ?string` — `trim`, colapsa `\s+` em um espaço, corta em 150 chars (`mb_substr`); vazio → `null`.
  - `Student::listSchools(int $tenantId): list<string>` — `SELECT school FROM users WHERE tenant_id = ? AND role='student' AND school IS NOT NULL GROUP BY school ORDER BY school` (collation `_ci` agrupa maiúsc./minúsc.).
  - `Student::create/update` aceitam `school:?string` e `is_trainer:int(0|1)` obrigatórios no array.
  - `findForTenant` retorna `school`, `is_trainer` (adicionar no SELECT **e** no GROUP BY).

- [ ] **Step 1: Teste do normalizador** (script em scratchpad, sem DB):

```php
require 'src/models/Student.php';
assert(Student::normalizeSchool('  Al   Noor  ') === 'Al Noor');
assert(Student::normalizeSchool("   ") === null);
assert(Student::normalizeSchool('') === null);
assert(mb_strlen(Student::normalizeSchool(str_repeat('á', 200))) === 150);
echo "ok\n";
```
Run: `php -d zend.assertions=1 -d assert.exception=1 <script>` → esperado FAIL (método inexistente).
- [ ] **Step 2:** Implementar `normalizeSchool` e `listSchools`; rodar o script → `ok`.
- [ ] **Step 3:** `create`/`update`/`findForTenant` com as colunas novas. Provisioning e controller: `school` = `Student::normalizeSchool($input['school'] ?? '')`, `is_trainer` = `!empty($input['is_trainer']) ? 1 : 0`.
- [ ] **Step 4:** Formulários `new.php` e `show.php`: `$old['school']`, `$old['is_trainer']` (POST: `isset($_POST['is_trainer'])`, **não** cair no valor antigo); input `name="school" list="lms-schools" maxlength="150"` + `<datalist id="lms-schools">` com `Student::listSchools($tenantId)`; checkbox `name="is_trainer" value="1"` (form-check/switch Bootstrap).
- [ ] **Step 5:** i18n: `students.form.school`, `students.form.school.help`, `students.form.is_trainer`, `students.form.is_trainer.help` (pt + en, texto passado pelo humanizer).
- [ ] **Step 6:** `php -l` em todos os arquivos tocados; `grep -rn "Student::create\|Student::update" src` confirma que todos os callers passam as chaves novas.
- [ ] **Step 7:** Commit `feat: escola e trainer no cadastro do aluno`

### Task 3: Filtros no RankingService

**Files:**
- Modify: `src/services/RankingService.php`

**Interfaces:**
- Consumes: colunas `users.school`, `users.is_trainer`.
- Produces:
  - `$filters` aceita `school?:string` e `trainer?:'only'|'exclude'`.
  - `normaliseFilters` retorna `array{group_id:?int, year:?int, course_id:?int, school:?string, trainer:?string}` (`school` vazio → null; `trainer` fora de `only|exclude` → null).
  - Linhas de `compute` ganham `school:?string` e `is_trainer:bool`. `computeForCourse` passa a devolver `school => null, is_trainer => false` para manter o shape.
  - Novo `private static userAttrClause(array $f): array{0:string,1:array<string,string>}` → SQL `AND u.school = :school` e/ou `AND u.is_trainer = 1|0` (literal, não input) + params.

- [ ] **Step 1:** Implementar `userAttrClause`; incluir no `WHERE` de `compute` (count e rows) e `myPosition`, logo após `{$enrollSql}`. `bindFilters` ganha `$attrParams` e faz bind de `:school` como `PDO::PARAM_STR`.
- [ ] **Step 2:** SELECT de rows: `u.school AS school, u.is_trainer AS is_trainer`; adicionar ambos no `GROUP BY u.id, u.name, u.school, u.is_trainer`.
- [ ] **Step 3:** Atualizar docblocks de `@param`/`@return`.
- [ ] **Step 4:** `php -l src/services/RankingService.php`; conferir que nenhum placeholder é reusado na mesma query (emulação OFF).
- [ ] **Step 5:** Commit `feat: filtros de escola e trainer no RankingService`

### Task 4: Telas de ranking

**Files:**
- Modify: `src/pages/teacher/ranking.php`, `src/pages/student/ranking.php`
- Modify: `lang/pt.php`, `lang/en.php`
- Modify (se preciso p/ selo): CSS do ranking em `public/assets/`

**Interfaces:**
- Consumes: `Student::listSchools(int)`, filtros `school`/`trainer` do Task 3, chaves de linha `school`/`is_trainer`.

- [ ] **Step 1:** Ler `?school=` e aceitar só se existir em `Student::listSchools($tenantId)` (comparação `mb_strtolower`, usando o valor canônico da lista); ler `?trainer=` aceitando só `only|exclude`.
- [ ] **Step 2:** Passar para `$filters`; incluir ambos no closure `$qs` (paginação/janela).
- [ ] **Step 3:** Form: select "Escola" (Todas + lista) e select "Trainer" (Todos / Só trainers / Sem trainers). Professor: reorganizar colunas (`col-md-3` ×4 + botão). Aluno: seguir o markup `lms-ranking-filter` existente.
- [ ] **Step 4:** Tabela: coluna "Escola" (`d-none d-md-table-cell`, `—` quando null) e selo `badge` "Trainer" após o nome quando `is_trainer`.
- [ ] **Step 5:** i18n: `ranking.filter.school`, `ranking.filter.all_schools`, `ranking.filter.trainer`, `ranking.filter.trainer.all`, `ranking.filter.trainer.only`, `ranking.filter.trainer.exclude`, `ranking.col.school`, `ranking.badge.trainer`. Rodar `/i18n-sync`.
- [ ] **Step 6:** `php -l` nos arquivos; prévia visual dos selects/selo via harness estático (`php -S`) se o layout mudar.
- [ ] **Step 7:** Commit `feat: filtros e selo de escola/trainer no ranking`

### Task 5: Fechamento

- [ ] `/code-review`; corrigir; PR para `develop` com o SQL do Task 1 destacado para o PO rodar no phpMyAdmin antes do deploy.
- [ ] Smoke em prod (após PO rodar o SQL + deploy): cadastrar escola em 2 alunos, conferir sugestão, marcar trainer, filtrar ranking por escola e trainer, paginar.
