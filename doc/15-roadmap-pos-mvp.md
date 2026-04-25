# 15 — Roadmap pós-MVP (próximas funcionalidades)

Documento agregador das 12 funcionalidades mapeadas pelo PO em 2026-04-25, após o fechamento da release v0.11.0 (Epic E9 — Rankings). Cada feature está como **F1-F12** com resumo, decisões já consolidadas com o PO, impacto de schema, critérios de aceite top-level, dependências e tamanho estimado. Quando uma feature virar épico ativo, ganha doc dedicado (`doc/16-...`, `doc/17-...`).

> **Status:** especificação aprovada pelo PO em 2026-04-25. Issues e épicos serão materializados conforme priorização.

---

## Visão geral

| ID | Feature | Tamanho | Épico sugerido |
|----|---------|---------|----------------|
| F1  | Login redireciona direto pro dashboard | P | E15 (UX e fundamentos) |
| F2  | Card de Ranking separado no sidebar | P | E15 |
| F3  | Cadastro do aluno: sexo + doc identificação | P | E16 (Aluno e tenant — fundamentos) |
| F4  | Acesso ao curso (período + bloqueio + avatar default) | G | E17 (Acesso e identidade visual) |
| F5  | Conquistas (achievements) | G | **E18 — épico próprio** |
| F6  | Status no curso (visível só pro professor) | P | E16 |
| F7  | Curso sequencial vs ordem livre | M | E19 (Modos de progressão) |
| F8  | Quiz em atividades e avaliações | G | **E20 — épico próprio** |
| F9  | Notificações configuráveis pelo professor | M | E21 (Configuração do tenant) |
| F10 | Histórico de conexões do aluno | M | E16 |
| F11 | Avatares dos alunos no painel (depende F3 + F4e) | P | E17 |
| F12 | `/teacher/students`: nome curto + doc + busca | P | E16 |

**Total estimado:** 5 épicos, ~25-30 stories.

**Ordem de execução sugerida:**
1. **E15** (F1, F2) — wins rápidos de UX, sem schema change.
2. **E16** (F3, F6, F10, F12) — fundamentos do aluno (sexo, status, conexões, listagem). Schema change moderado.
3. **E17** (F4, F11) — bloco maior de acesso ao curso + avatar default + integração visual.
4. **E18** (F5) — épico dedicado de conquistas.
5. **E19** (F7) — modos de progressão.
6. **E20** (F8) — épico dedicado de quiz.
7. **E21** (F9) — config de notificações.

---

## F1 — Login redireciona direto pro dashboard

### Contexto
Hoje, após login, o usuário vai pra `src/pages/home.php` (rota `/`) que mostra "Welcome, X — Go to dashboard" + botão. Click extra desnecessário pros 3 papéis (aluno, professor, super-admin).

### Decisão consolidada
Quando há sessão válida, `/` redireciona via `header('Location: ...')` para o dashboard apropriado:
- `super_admin` → `/admin`
- `teacher` → `/teacher`
- `student` → `/student`

`AuthController::dashboardFor($role)` já existe — só faltava aplicar antes de renderizar.

### Critérios de aceite
- [ ] User logado em `/` é redirecionado pro dashboard sem ver a tela intermediária
- [ ] User deslogado em `/` continua vendo a tela atual (login + flash demo)
- [ ] Redirect usa `Location:` HTTP 302, `exit` imediato
- [ ] Sem mudança em rota de login (`/login` continua redirecionando pra `/` no sucesso, que agora redireciona pro dashboard)

### Schema
Sem mudança.

### Tamanho
**P** (~5 linhas em `src/pages/home.php`).

---

## F2 — Card de Ranking separado no sidebar

### Contexto
Em E9-07 (#193), `#posição` foi adicionado **dentro** do `lms-xp-block` (mesmo card do XP). PO não gostou da integração visual.

### Decisão consolidada
- Remove o bloco `.lms-xp-position` de dentro do XP card
- Cria **terceiro card** novo no `ProfileSidebar`, abaixo do XP block, dedicado ao ranking
- Conteúdo: posição (`#N` em destaque), label "POSIÇÃO" + botão **"Ver ranking"** que leva pra `/student/ranking`
- Card segue o mesmo design language dos demais (radius, shadow, paleta tokens E14)

### Critérios de aceite
- [ ] `lms-xp-block` não tem mais `.lms-xp-position` interno
- [ ] Novo card `lms-ranking-block` aparece logo abaixo do XP no `ProfileSidebar`
- [ ] Mostra `#N` (Plus Jakarta Sans, 18-24px) + label "POSIÇÃO"
- [ ] Botão CTA "Ver ranking" estilo outline-primary, leva pra `/student/ranking`
- [ ] Aluno zero XP: mostra "—" em vez de `#último` (mantém comportamento de F5/E9-07)
- [ ] Aluno fora do tenant ou sem dados: card oculto graciosamente

### Schema
Sem mudança.

### Tamanho
**P** (refactor curto em `src/templates/partials/profile_sidebar.php` + CSS novo em `student-area.css`).

### Dependências
Já entregue na v0.11.0. Esta feature é refinamento visual.

---

## F3 — Cadastro do aluno: sexo + doc identificação

### Contexto
Coletar duas informações novas no form de cadastro de aluno (`/teacher/students/new`). Aplicação:
- **Sexo** (`gender`): obrigatório. Necessário pra F11 (avatar baseado em sexo).
- **Doc identificação** (`id_document`): opcional, só números, sem máscara.

Nenhuma das duas é exibida no acesso do aluno (`/student/*`) — informação de gestão pro professor.

### Decisões consolidadas
- `users.gender` ENUM(`'male','female'`) NOT NULL — obrigatório no form
- `users.id_document` VARCHAR(30) NULL — opcional, validação `^[0-9]{1,30}$`
- Alunos legados: backfill automático `gender = 'male'` em migração idempotente (PO confirmou: "só tem eu")
- Doc legado: NULL — professor preenche depois manualmente
- Sem máscara — input free-form, validação só permite dígitos

### Critérios de aceite
- [ ] Schema migrado em `install/schema.sql` (idempotente) com backfill `UPDATE users SET gender = 'male' WHERE role = 'student' AND gender IS NULL`
- [ ] Form `/teacher/students/new` ganha `<select>` Sexo (Masculino/Feminino) — obrigatório, sem default
- [ ] Form `/teacher/students/new` ganha `<input type="text" pattern="[0-9]{1,30}">` Doc identificação — opcional
- [ ] Form `/teacher/students/{id}` (edição) também permite alterar sexo + doc
- [ ] Server-side rejeita gender fora do ENUM e doc não-numérico ou > 30 chars
- [ ] Tela do aluno (`/student/*` + `/profile`) **não exibe** sexo nem doc
- [ ] i18n PT/EN das labels e mensagens de erro

### Schema (impacto idempotente em `install/schema.sql`)
```sql
ALTER TABLE users
    ADD COLUMN gender      ENUM('male','female') NOT NULL DEFAULT 'male'
        AFTER name,
    ADD COLUMN id_document VARCHAR(30) NULL DEFAULT NULL
        AFTER gender;
-- Backfill idempotente (defensivo, redundante com DEFAULT mas explícito):
UPDATE users SET gender = 'male' WHERE role = 'student' AND gender IS NULL;
```

> Vai pela skill `/mysql-schema` na execução real.

### Tamanho
**P** (1 form + schema + i18n).

### Dependências
**Bloqueia F11** (avatar por sexo) e **F4e** (avatar default depende de sexo).

---

## F4 — Acesso ao curso

Bloco grande dividido em 5 sub-features. Tamanho total **G**.

### F4a — Período de acesso na matrícula

#### Decisões consolidadas
- 2 colunas novas em `enrollments`: `access_starts_at DATETIME NULL`, `access_ends_at DATETIME NULL`
- `access_starts_at = NULL` → acesso imediato (sem espera)
- `access_ends_at = NULL` → acesso ilimitado (sem expiração)
- Form de matrícula (`/teacher/students/{id}/enroll` e similares) ganha 2 campos opcionais

#### Critérios de aceite
- [ ] Schema migrado idempotente
- [ ] Form de matrícula ganha 2 inputs `datetime-local`, ambos opcionais
- [ ] Validação: se ambos preenchidos, `start < end`
- [ ] Tela de gestão de alunos do curso (`/teacher/courses/{id}` ou similar) mostra período no roster
- [ ] Aluno fora da janela vê o curso como "indisponível" (F4c)

### F4b — Bloquear/Remover aluno do curso

#### Decisão consolidada
**Bloquear ≠ remover** (PO confirmou):
- **Bloquear**: mantém `enrollments` com flag `blocked_at` setada. Preserva XP, progresso e histórico. Reversível (professor pode desbloquear).
- **Remover**: DELETE da `enrollments`. XP gerado naquele curso permanece em `xp_events` (intacto), mas o curso some pra esse aluno.

#### Critérios de aceite
- [ ] `enrollments.blocked_at DATETIME NULL` adicionada
- [ ] Action **"Bloquear acesso"** em `/teacher/courses/{id}` (roster do curso) seta `blocked_at = NOW()`
- [ ] Action **"Desbloquear"** seta `blocked_at = NULL`
- [ ] Action **"Remover do curso"** com confirmação destrutiva (modal de confirmação tipo E3-05) faz DELETE
- [ ] CSRF + auth do professor + ownership do curso (tenant_id) em todas as actions

### F4c — Curso indisponível pro aluno

#### Decisão consolidada
Quando aluno tem matrícula mas:
- `blocked_at IS NOT NULL`, OU
- `NOW() < access_starts_at`, OU
- `NOW() > access_ends_at`

…o curso aparece pro aluno em `/student` (My Courses) como **card desabilitado** com:
- Visual: opacity reduzida, badge "Indisponível"
- Mensagem do motivo:
  - "Acesso bloqueado pelo professor"
  - "Acesso liberado a partir de DD/MM/YYYY"
  - "Acesso expirou em DD/MM/YYYY"
- Click no card NÃO abre `/student/course/{id}` (mantém na home)

#### Critérios de aceite
- [ ] Helper `enrollment_access_status($studentId, $courseId): array{available:bool, reason?:string, message:string}`
- [ ] Tela `/student` filtra/marca cursos indisponíveis
- [ ] Acesso direto via URL `/student/course/{id}` redireciona pra `/student` com flash + motivo
- [ ] Sub-rotas (`/student/cu/{id}`, `/student/activity/{id}`, `/student/evaluation/{id}`) também respeitam — via curso pai
- [ ] i18n PT/EN das 3 mensagens

### F4d — Curso removido some pro aluno

#### Decisão consolidada
DELETE da `enrollments` faz o curso desaparecer de `/student`. XP gerado pelo aluno naquele curso permanece em `xp_events` mas não conta pro ranking-por-curso (queries com filtro `course_id` continuam batendo). XP geral do tenant não é afetado.

> **Nota técnica:** alternativa de "soft delete" foi discutida implicitamente; PO escolheu DELETE limpo.

### F4e — Avatar default por tenant (estilo + sexo)

#### Decisões consolidadas
- Config no tenant: `tenants.avatar_style` ENUM(`'arabe','ocidental'`) DEFAULT `'arabe'` (assumindo contexto principal EAU)
- 4 imagens SVG ficam em `public/assets/avatars/`:
  - `arabe-male.svg`
  - `arabe-female.svg`
  - `ocidental-male.svg`
  - `ocidental-female.svg`
- Aluno é renderizado com `avatars/{tenant.avatar_style}-{user.gender}.svg`
- **Aluno sem sexo** (cenário impossível pós-backfill da F3, mas defensivo): fallback `default-male.svg` ou silhueta neutra
- Professor configura `avatar_style` em uma nova página `/teacher/settings` (ou similar)

#### Geração das imagens
**O agente (Claude) gera as 4 SVGs** como entregável da story. Estilo:
- Vetorial flat illustration
- Headshot centralizado, ~256x256 viewBox
- Paleta neutra (sem afetar tema do app)
- Árabes: cobertura tradicional (kandura/keffiyeh masculino, hijab feminino)
- Ocidentais: estilo casual genérico (cabelo curto/médio, sem head cover)

**Caso a geração não atenda à qualidade desejada**, PO substitui depois (asset estático em `public/assets/avatars/`).

#### Critérios de aceite
- [ ] 4 SVGs em `public/assets/avatars/` com peso < 10KB cada
- [ ] `tenants.avatar_style` migrada idempotente
- [ ] Página de config do tenant (`/teacher/settings` ou similar — propor nova rota) com toggle Árabe/Ocidental
- [ ] Helper `student_avatar_url($studentId): string` retorna o path correto baseado em (tenant.avatar_style, user.gender)
- [ ] Fallback gracioso se asset não existe

### Schema (impacto F4 inteiro)
```sql
ALTER TABLE enrollments
    ADD COLUMN access_starts_at DATETIME NULL DEFAULT NULL,
    ADD COLUMN access_ends_at   DATETIME NULL DEFAULT NULL,
    ADD COLUMN blocked_at       DATETIME NULL DEFAULT NULL;

ALTER TABLE tenants
    ADD COLUMN avatar_style ENUM('arabe','ocidental') NOT NULL DEFAULT 'arabe';
```

### Tamanho
**G** total — divisível em 4-5 stories independentes:
- F4a: período (M)
- F4b: block/remove (M)
- F4c: indisponível UI (M)
- F4d: remoção propaga (P, parte de F4b)
- F4e: avatar default (M)

### Dependências
- **F4e bloqueado por F3** (gender obrigatório)
- **F11 bloqueado por F4e** (avatares no painel usam o default)

---

## F5 — Conquistas (achievements)

Épico próprio. Ver detalhamento abaixo.

### Visão geral
Conquistas são marcos pré-definidos que o aluno desbloqueia conforme avança. **Não dão XP** (XP continua sendo a métrica principal de gamificação — ADR-002). Conquistas são **medalhas** visíveis no perfil/sidebar e numa tela cheia de progresso.

### Catálogo inicial (família, contagem, descrição)

| Família | Conquistas | Total |
|---------|-----------|-------|
| **UC concluídas** | 1, 2, 3, 4, 5, 10, 20, 30, 40, 50, 100 | 11 |
| **CC concluídas** | 1, 2, 3, 4, 5, 10 | 6 |
| **Cursos concluídos** | 1, 2, 3, 4, 5, 10 | 6 |
| **Atividades enviadas** | 1, 2, 3, 4, 5, 10, 20, 30, 40, 50, 100, 200, 300, 400, 500 | 15 |
| **Avaliações enviadas** | 1, 2, 3, 4, 5, 10, 20, 30, 40, 50 | 10 |
| **Nota máxima por escopo** | UC, CC, Curso (3 conquistas) | 3 |
| **Nota mínima por avaliação** | 60%, 80%, 100% | 3 |
| **Eventos pontuais** | Leu primeira notificação, Subiu de patente, Começou curso | 3 |
| **Total** | | **57** |

### Definições precisas (consolidadas com PO)
- **"Concluiu UC"**: `StudentProgress::cuStatus` retorna `completed` (regra atual).
- **"Concluiu CC"**: todas as UCs daquela CC `completed`.
- **"Concluiu Curso"**: todas as CCs do curso `completed`. **Não há avaliação final de curso** — terminar todas as CCs = terminar o curso.
- **"Nota máxima em UC"**: todas as atividades da UC entregues + avaliação da UC com nota = 10.0.
- **"Nota máxima em CC"**: todas as UCs da CC com nota máxima (todas atividades entregues + todas avaliações 10.0).
- **"Nota máxima em Curso"**: todas as CCs com nota máxima.
- **"Leu uma notificação"**: primeira chamada de `Notification::markRead` pra aquele aluno.
- **"Subiu de patente"**: primeira mudança de patente atingida (saiu da patente inicial). Apenas **uma** conquista — não desbloqueia a cada nova patente.
- **"Começou um curso"**: primeira vez que o aluno abre `/student/course/{id}` (gatilho via `Enrollment::touchLastAccess` — primeiro `last_access_at IS NOT NULL`).
- **"Alcançou X% em uma avaliação"**: `evaluation_submissions.grade >= X * 0.10` em qualquer avaliação corrente.

### Regras gerais (consolidadas com PO)
1. **Por aluno × tenant** — cada conquista é um par (aluno, tenant).
2. **Tela mostra apenas as alcançáveis pelo tenant** — ex.: tenant com 1 curso não exibe "Concluiu 5 cursos". Catálogo dinâmico filtrado por estado atual do tenant.
3. **Permanência:** desbloqueio nunca é revogado. Se condição se torna impossível depois (ex.: professor apaga curso), aluno mantém a conquista.
4. **Crescimento dinâmico:** se professor adiciona conteúdo que torna conquistas novas alcançáveis, elas aparecem como "disponíveis" pra todo aluno do tenant.
5. **Ordem da tela:** desbloqueadas mais recentes primeiro; bloqueadas depois (cinza).

### Schema novo

```sql
-- Catálogo estático de conquistas (seed inicial via INSERT IGNORE).
CREATE TABLE IF NOT EXISTS achievements (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(60)  NOT NULL,         -- ex.: 'uc_completed_5'
    family      VARCHAR(40)  NOT NULL,         -- ex.: 'uc_completed'
    threshold   INT UNSIGNED NULL,             -- ex.: 5 (NULL para conquistas pontuais)
    icon_key    VARCHAR(40)  NOT NULL,         -- ex.: 'mortarboard'
    name_pt     VARCHAR(120) NOT NULL,
    name_en     VARCHAR(120) NOT NULL,
    sort_order  INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ach_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Desbloqueios por aluno × tenant.
CREATE TABLE IF NOT EXISTS student_achievements (
    student_user_id BIGINT UNSIGNED NOT NULL,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    achievement_id  BIGINT UNSIGNED NOT NULL,
    unlocked_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (student_user_id, tenant_id, achievement_id),
    KEY idx_sa_recent (student_user_id, tenant_id, unlocked_at),
    CONSTRAINT fk_sa_student FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_sa_tenant  FOREIGN KEY (tenant_id)        REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_sa_ach     FOREIGN KEY (achievement_id)   REFERENCES achievements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Catálogo seed (`INSERT IGNORE`) popula as 57 conquistas iniciais.

### Engine — híbrido event-driven + on-demand (aprovado pelo PO)

**Event-driven (eficiente, fast path):**
- Aluno entrega atividade → checa família `activity_submitted` (com nova contagem)
- Aluno entrega avaliação → checa famílias `evaluation_submitted` + `evaluation_grade_X_percent`
- Aluno conclui UC → checa famílias `uc_completed`, `uc_max_grade`
- Aluno conclui CC → checa famílias `cc_completed`, `cc_max_grade`
- Aluno conclui curso → checa famílias `course_completed`, `course_max_grade`
- `markRead` → checa `notification_read`
- Patente subiu → checa `rank_first_promotion`
- `touchLastAccess` (primeira vez) → checa `course_started`

**On-demand (defensivo, slow path):**
- Tela `/student/achievements` re-roda check completo no carregamento — garante que conquistas não foram perdidas por bugs/eventos não disparados

Service novo: `src/services/AchievementsService.php`
- `evaluateForEvent(int $studentId, int $tenantId, string $eventCode, array $context): array<int>` — retorna ids de conquistas recém-desbloqueadas
- `evaluateAll(int $studentId, int $tenantId): array<int>` — slow path completo
- `availableForTenant(int $tenantId): list<array>` — lista catálogo aplicável (filtra por estado atual do tenant)

### UI

#### Card no `ProfileSidebar` (entre XP e Ranking)
- Título: "CONQUISTAS"
- 3 miniaturas (icon only) das últimas desbloqueadas
- Click em qualquer miniatura ou em "Ver todas →" leva pra `/student/achievements`

#### Tela `/student/achievements`
- Header com totalizador: `12 / 35 conquistas desbloqueadas`
- Grid de cards (3-4 colunas em desktop, 2 em tablet, 1 em mobile)
- Cada card:
  - Ícone (cinza se bloqueada, colorido se desbloqueada)
  - Nome
  - Data de desbloqueio (se desbloqueada)
  - Badge de família + level (ex.: "UC × 5")
- Ordem: desbloqueadas DESC por `unlocked_at`, depois bloqueadas por `sort_order`
- Conquistas indisponíveis no tenant **não aparecem** (filtradas em `availableForTenant`)

### Ícones — design

**Pacote escolhido:** **Bootstrap Icons** (alinhado com Bootstrap 5 já no stack, CDN-friendly, ~30KB).

Mapeamento por família:

| Família | Ícone (Bootstrap Icons) |
|---------|------------------------|
| `uc_completed` | `bi-mortarboard` |
| `cc_completed` | `bi-bullseye` |
| `course_completed` | `bi-trophy` |
| `activity_submitted` | `bi-pencil-square` |
| `evaluation_submitted` | `bi-clipboard-check` |
| `uc_max_grade`, `cc_max_grade`, `course_max_grade` | `bi-stars` |
| `eval_grade_60` / `80` / `100` | `bi-percent` |
| `notification_read` | `bi-bell-fill` |
| `rank_first_promotion` | `bi-award` |
| `course_started` | `bi-rocket-takeoff` |

**Variantes da mesma família** (1, 2, 3, 5, 10, ...) usam **mesmo ícone** + **badge de nível** sobreposto no canto inferior direito (decisão do PO em F5.6). Badge: círculo colorido com número (1, 2, 3, ..., 100).

**Cores:**
- Desbloqueada: gradient da família (UC indigo, atividade roxo, avaliação rosa, etc.)
- Bloqueada: cinza (`opacity: 0.4`, `filter: grayscale(1)`)

### Critérios de aceite (top-level)
- [ ] Schema das 2 tabelas + seed das 57 conquistas
- [ ] Service `AchievementsService` com `evaluateForEvent`, `evaluateAll`, `availableForTenant`
- [ ] Hook em todas as 9 origens de evento (entregas, conclusões, notif, patente, primeiro access)
- [ ] Página nova `/student/achievements`
- [ ] Card no `ProfileSidebar` (entre XP e Ranking, conforme F2)
- [ ] Bootstrap Icons via CDN no layout
- [ ] i18n PT/EN das 57 conquistas (`name_pt` e `name_en` no catálogo)
- [ ] Mobile 360px

### Tamanho
**G** — épico próprio (~6-8 stories):
1. Schema + seed do catálogo (M)
2. Service + engine event-driven (M)
3. Service + engine on-demand (P)
4. Página `/student/achievements` (M)
5. Card no `ProfileSidebar` (P) — depende de F2
6. Hooks dos 9 eventos (M)
7. Polish + i18n + mobile (P)

### Dependências
- **F2** (card de ranking separado) precisa estar feito antes do card de conquistas pra layout do sidebar ficar consistente
- **F11** (avatares) pode rodar em paralelo

### Doc dedicado futuro
`doc/16-conquistas.md` — quando virar épico ativo.

---

## F6 — Status no curso (visível só pro professor)

### Decisões consolidadas
- 3 estados: `active` (frequente/ativo), `absent` (evadido/ausente), `completed` (concluído)
- **Manual sempre** — professor seta no roster do curso. Sem auto-set.
- Visível só no acesso do professor. Aluno **não** vê.
- Atributo da matrícula (não do aluno) — o status muda por curso.

### Schema
```sql
ALTER TABLE enrollments
    ADD COLUMN status ENUM('active','absent','completed') NOT NULL DEFAULT 'active'
        AFTER blocked_at;
```

### Critérios de aceite
- [ ] Coluna nova migrada idempotente
- [ ] Roster do curso (`/teacher/courses/{id}` na seção de alunos) mostra coluna "Status" + dropdown inline ou botão "Alterar"
- [ ] PUT/POST endpoint pra alterar (CSRF + auth + ownership)
- [ ] i18n PT/EN das 3 labels
- [ ] Aluno **não** vê (verificar que nenhuma view de `/student/*` lê o campo)

### Tamanho
**P**.

### Dependências
Nenhuma. Independente das demais.

---

## F7 — Curso sequencial vs ordem livre

### Decisões consolidadas

3 configs novas no curso:

| Config | ENUM/Tipo | Default | Efeito |
|--------|-----------|---------|--------|
| `cc_mode` | ENUM(`sequential`,`free`) | `sequential` | CCs e UCs dentro de CC seguem ordem (sequencial) ou livre |
| `activity_mode` | ENUM(`sequential`,`free`) | `sequential` | Atividades dentro de UC sequenciais ou livres |
| `eval_after_activities` | BOOLEAN | `true` | Avaliação só libera após todas atividades da UC entregues |

### Comportamento sequencial — visibilidade pro aluno

**Decidido pelo PO:**
- Sempre mostra a CC/UC **atual** nítida + a **próxima** com blur + mensagem "Conclua X primeiro"
- CCs/UCs **mais distantes que a próxima** ficam ocultas
- Quando aluno conclui a atual, a próxima vira atual e a seguinte aparece em blur
- Mesmo padrão pra UCs dentro de CC e atividades dentro de UC

**Exemplo:** curso com 10 CCs em modo sequencial:
- Estado inicial: CC1 nítida (atual), CC2 blur ("Conclua CC1 primeiro"), CC3-10 ocultas
- Aluno conclui CC1: CC1 marcada done, CC2 nítida (atual), CC3 blur, CC4-10 ocultas

### Schema
```sql
ALTER TABLE courses
    ADD COLUMN cc_mode             ENUM('sequential','free') NOT NULL DEFAULT 'sequential',
    ADD COLUMN activity_mode       ENUM('sequential','free') NOT NULL DEFAULT 'sequential',
    ADD COLUMN eval_after_activities TINYINT(1)              NOT NULL DEFAULT 1;
```

### Critérios de aceite
- [ ] Schema migrado
- [ ] Form de criar/editar curso ganha 3 toggles (CC mode, atividade mode, avaliação só pós-atividades)
- [ ] `StudentProgress` (ou helper novo) calcula CC/UC/atividade "atual" e "próxima"
- [ ] Tela `/student/course/{id}` aplica a nova lógica de visibilidade (blur + mensagem)
- [ ] Tela `/student/cu/{id}` aplica blur em atividades futuras (modo sequencial)
- [ ] Avaliação `/student/evaluation/{id}` retorna 403 amigável se `eval_after_activities=1` e atividades pendentes
- [ ] Modo `free`: tudo aparece nítido, sem blur — comportamento atual preservado
- [ ] i18n PT/EN das mensagens de bloqueio

### Tamanho
**M-G** — borderline.

### Dependências
Independente. Mas mexe em `StudentProgress` e nas pages do aluno — risco de regressão.

---

## F8 — Quiz em atividades e avaliações

Épico próprio. Ver detalhamento abaixo.

### Decisões consolidadas

#### Tipos
- **Atividade ENUM:** mantém `('projeto','codigo')` no banco, **adiciona `'quiz'`**. Frontend pode mostrar como "Code" via i18n, mas no banco fica `codigo` (sem migration de dados).
- **Avaliação ENUM:** novo. Hoje avaliação não tem campo `type`. Adicionar `evaluations.type ENUM('projeto','quiz') NOT NULL DEFAULT 'projeto'`.

#### Estrutura do quiz
- Quiz tem **N questões** (variável)
- Cada questão tem **M opções** (variável, normalmente 4)
- **Tipo de questão MVP:** apenas múltipla escolha **1 correta** (V/F, múltiplas corretas, dissertativa ficam pra futuro)
- **Peso por questão:** `DECIMAL(4,2)` — pode ser 0.5, 1.25, etc. **Soma das pesos = 10.00**
- Validação: form do professor não permite salvar quiz com soma ≠ 10.00

#### Snapshot na entrega
Quando aluno submete, **snapshot do quiz** é gravado em `evaluation_submissions.quiz_snapshot` (JSON) ou similar. Garante que se professor edita o quiz depois, o aluno mantém visão da versão respondida.

#### Edição depois de respondido
- Professor **pode editar** o quiz mesmo se há submissões
- Submissões existentes mantêm a nota original (snapshot)
- Próximas tentativas (caso retry seja liberado) usam quiz atual

#### Comportamento de XP/aprovação
- **Atividade-quiz:** entregar = ganha XP (igual atividade atual). Sem retry. Nota é informativa (não há "falha" pra atividade).
- **Avaliação-quiz:** mesma regra das avaliações de projeto:
  - `grade >= 6.0` → aprovado, sem reenvio possível
  - `grade >= 8.0` → libera XP
  - `grade < 6.0` → professor pode liberar retry (mesma regra atual)

#### Gabarito
- Flag por quiz: `quizzes.show_answers BOOLEAN DEFAULT 0`
- Quando `1`: aluno vê opções corretas após submeter
- Quando `0`: aluno vê só a nota

#### UI
- **Professor:** form único pra criar/editar quiz com lista de questões + opções inline. JS pra adicionar/remover questões e opções dinamicamente. Validação client-side da soma de pesos = 10.00.
- **Aluno:** todas as questões em uma página (sem paginação). Submit final.

### Schema
```sql
-- ENUM novo no evaluations:
ALTER TABLE evaluations
    ADD COLUMN type ENUM('projeto','quiz') NOT NULL DEFAULT 'projeto'
        AFTER instructions;

-- ENUM expandido em activities:
ALTER TABLE activities
    MODIFY COLUMN type ENUM('projeto','codigo','quiz') NOT NULL;

-- Quizzes (1:1 com activity ou evaluation, polimórfico):
CREATE TABLE IF NOT EXISTS quizzes (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id     BIGINT UNSIGNED NOT NULL,
    owner_type    ENUM('activity','evaluation') NOT NULL,
    owner_id      BIGINT UNSIGNED NOT NULL,
    show_answers  TINYINT(1)      NOT NULL DEFAULT 0,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_quiz_owner (owner_type, owner_id),
    KEY idx_quiz_tenant (tenant_id),
    CONSTRAINT fk_quiz_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_questions (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quiz_id     BIGINT UNSIGNED NOT NULL,
    text        TEXT            NOT NULL,
    weight      DECIMAL(4,2)    NOT NULL,
    position    INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_qq_quiz (quiz_id, position),
    CONSTRAINT fk_qq_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_options (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    question_id BIGINT UNSIGNED NOT NULL,
    text        VARCHAR(500)    NOT NULL,
    is_correct  TINYINT(1)      NOT NULL DEFAULT 0,
    position    INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_qo_question (question_id, position),
    CONSTRAINT fk_qo_question FOREIGN KEY (question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Snapshot na submissão (em activity_submissions e evaluation_submissions):
ALTER TABLE activity_submissions
    ADD COLUMN quiz_snapshot JSON NULL DEFAULT NULL;

ALTER TABLE evaluation_submissions
    ADD COLUMN quiz_snapshot JSON NULL DEFAULT NULL;
```

### Critérios de aceite (top-level)
- [ ] Schema novo (4 tabelas + 2 ALTER + 2 colunas snapshot)
- [ ] Form do professor pra criar quiz (atividade e avaliação)
- [ ] Validação de soma de pesos = 10.00 (server-side estrita + client-side UX)
- [ ] UI do aluno: form de quiz com submit final
- [ ] Cálculo de nota no submit: `sum(question.weight if option_marked.is_correct)` — armazena em `submissions.grade`
- [ ] Snapshot do quiz no submit (JSON contendo questões + opções + correta no momento)
- [ ] Flag `show_answers`: pós-submit mostra ou não as corretas
- [ ] XP/aprovação seguem regras existentes (atividade dá XP por entregar; avaliação dá XP se >= 8.0)
- [ ] Edição do quiz pelo professor permitida; submissões antigas mantêm snapshot
- [ ] Mobile 360px

### Tamanho
**G** — épico próprio (~6-8 stories):
1. Schema + models (M)
2. Form do professor: criar/editar quiz (M)
3. UI do aluno: responder quiz (M)
4. Cálculo + snapshot (M)
5. Gabarito condicional (P)
6. Integração com retry/aprovação (M)
7. Polish + mobile (P)

### Dependências
Independente. Mas reusa fluxos de submission (atividade e avaliação) — cuidado com regressão.

### Doc dedicado futuro
`doc/17-quiz.md`.

---

## F9 — Notificações configuráveis pelo professor

### Decisões consolidadas
- Matriz **evento × canal** — granularidade por par
- Default: **ON** em todos os pares
- Por **tenant** (config do professor afeta todos os alunos do tenant)

### Catálogo de eventos (existentes hoje)

| Evento | Sino | Email |
|--------|------|-------|
| `enrollment` | ✓ | ✓ |
| `activity_new` | ✓ | (não existe hoje) |
| `submission_closed` | ✓ | (não existe hoje) |
| `activity_feedback` | ✓ | ✓ |
| `new_evaluation` | ✓ | ✓ |
| `grade_evaluation` | ✓ | ✓ |
| `retry_enabled` | ✓ | ✓ |
| `content_published` | ✓ | ✓ |

A matriz de config tem **8 linhas × 2 colunas** = 16 toggles por tenant.

### Schema
```sql
CREATE TABLE IF NOT EXISTS notification_settings (
    tenant_id BIGINT UNSIGNED NOT NULL,
    event     VARCHAR(40)     NOT NULL,
    channel   ENUM('bell','email') NOT NULL,
    enabled   TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (tenant_id, event, channel),
    CONSTRAINT fk_ns_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Quando ausente da tabela, **default = enabled** (não precisa popular seed).

### Critérios de aceite
- [ ] Schema migrado
- [ ] Página `/teacher/settings/notifications` (ou similar) com matriz de toggles
- [ ] `NotificationService` consulta o setting antes de criar sino + antes de enviar email
- [ ] Helper `notification_enabled($tenantId, $event, $channel): bool` (default true se sem row)
- [ ] CSRF + auth do professor + ownership do tenant
- [ ] i18n PT/EN dos labels dos 8 eventos

### Tamanho
**M**.

### Dependências
Independente.

---

## F10 — Histórico de conexões do aluno

### Decisões consolidadas
- Tabela nova `user_logins` que registra cada login bem-sucedido
- Coleta: `user_id`, `ip`, `location`, `user_agent`, `logged_in_at`
- **Geo-IP via ip-api.com** (free tier — 45 req/min limit)
- **Retenção: 180 dias** com cron de purge
- Visível **apenas no detalhe do aluno** pelo professor — últimas **10 conexões**

### Schema
```sql
CREATE TABLE IF NOT EXISTS user_logins (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NOT NULL,
    tenant_id     BIGINT UNSIGNED NULL,           -- NULL para teacher/super_admin
    ip            VARCHAR(45)     NOT NULL,        -- IPv4/IPv6
    location      VARCHAR(120)    NULL,            -- "Dubai, AE" (preenchido por geo-IP)
    user_agent    VARCHAR(255)    NULL,
    logged_in_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ul_user_recent (user_id, logged_in_at),
    CONSTRAINT fk_ul_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Geo-IP
- Lib HTTP: `Judge0Client` já existe como modelo de cliente HTTP — reusar padrão.
- Endpoint: `http://ip-api.com/json/{ip}?fields=country,city,status` (free, 45 req/min limit)
- Fallback: `location = NULL` se request falhar ou rate-limit ativo
- **Não bloqueia o login** — geo-IP roda assíncrono ou em try/catch envolvendo o INSERT

### Privacy
- Retenção 180 dias — cron novo `scripts/cron/purge-old-logins.php`
- Adicionar nota em ToS/política de privacidade do tenant (responsabilidade do PO)

### Critérios de aceite
- [ ] Schema migrado
- [ ] Hook em `AuthController::authenticate` ou no fluxo de login pra inserir row
- [ ] Geo-IP via ip-api.com com try/catch defensivo
- [ ] Cron novo `purge-old-logins.php` que apaga rows com `logged_in_at < NOW() - 180 DAY`
- [ ] Página de detalhe do aluno (`/teacher/students/{id}`) ganha seção "Últimos acessos" com tabela das 10 mais recentes
- [ ] Mobile 360px
- [ ] LGPD note: documentar em `doc/14-decisoes-e-pendencias.md`

### Tamanho
**M**.

### Dependências
Independente.

---

## F11 — Avatares dos alunos no painel

### Decisões consolidadas
- Helper `student_avatar_url($studentId): string` retorna `/assets/avatars/{tenant.avatar_style}-{user.gender}.svg`
- Aplicar em:
  - **`ProfileSidebar`** (substitui a inicial atual)
  - **Linhas de ranking** (`/student/ranking` e `/teacher/ranking`)
- Aluno sempre tem `gender` definido (F3 garante via DEFAULT 'male' + backfill)

### Critérios de aceite
- [ ] Helper novo `student_avatar_url`
- [ ] `ProfileSidebar`: substitui `<div class="lms-sidebar-avatar">{INITIAL}</div>` por `<img src="{avatar_url}" class="lms-sidebar-avatar-img">`
- [ ] Linhas de ranking ganham avatar à esquerda do nome (em desktop apenas; mobile pode ocultar via `d-none d-md-inline-block`)
- [ ] CSS responsivo pras imagens
- [ ] Lazy loading (`loading="lazy"`) nos avatares de ranking pra economia em listas longas

### Tamanho
**P**.

### Dependências
- **F3** (gender obrigatório)
- **F4e** (assets SVG dos 4 avatares + tenants.avatar_style)

---

## F12 — `/teacher/students`: nome curto + doc + busca

### Decisões consolidadas
- **Coluna nome** mostra "Primeiro + Último" (ex.: "Marcos Soares" pra "Marcos Aparecido Ortolani Soares")
- **Tooltip no nome** (`title="Nome Completo"`) preserva acesso ao nome inteiro
- Aluno com nome único (ex.: "Madonna") mostra só o token único
- **Coluna nova "Doc Identificação"** (vazia se NULL)
- **Busca** (filtro `q`) já cobre nome + email; **adiciona doc identificação** ao escopo

### Critérios de aceite
- [ ] Helper `format_short_name($fullName): string` que pega primeiro + último token (split por espaço)
- [ ] Coluna "Nome" mostra short name + tooltip com nome completo
- [ ] Coluna nova "Doc identificação" (oculta em mobile via `d-none d-md-table-cell`)
- [ ] Filtro `q` no SQL adiciona `OR id_document LIKE :q`
- [ ] i18n PT/EN do header da coluna

### Tamanho
**P**.

### Dependências
**F3** (precisa do `id_document`).

---

# Resumo de épicos sugeridos

| Épico | Features | Ordem | Tamanho |
|-------|----------|-------|---------|
| **E15 — UX e fundamentos** | F1, F2 | 1º | P+P (1-2 stories) |
| **E16 — Aluno e tenant: fundamentos** | F3, F6, F10, F12 | 2º | 4 stories M-P |
| **E17 — Acesso e identidade visual** | F4 (a-e), F11 | 3º | 6-7 stories M-P |
| **E18 — Conquistas** | F5 | 4º | 6-8 stories M-P (épico próprio) |
| **E19 — Modos de progressão** | F7 | 5º | 3-4 stories M |
| **E20 — Quiz** | F8 | 6º | 6-8 stories M-P (épico próprio) |
| **E21 — Notificações configuráveis** | F9 | 7º | 2-3 stories M-P |

**Total estimado:** ~28-37 stories distribuídas em 7 épicos.

---

# Pendências e dúvidas remanescentes

Nenhuma. Todas as 30+ perguntas críticas levantadas foram respondidas pelo PO em 2026-04-25.

# Próximos passos

1. **PO escolhe ordem de execução** — sugestão acima (E15 → E16 → ...) ou custom.
2. Quando um épico for ativado, rodar `/story-breakdown` sobre a seção correspondente deste doc pra materializar como issues no GitHub.
3. F5 e F8 ganham docs dedicados (`doc/16-conquistas.md`, `doc/17-quiz.md`) na ativação dos épicos respectivos.
