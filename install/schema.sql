-- ============================================================================
-- LMS — schema inicial (MySQL 8 + InnoDB + utf8mb4)
-- ----------------------------------------------------------------------------
-- Como rodar no phpMyAdmin (Hostinger cPanel):
--   1. Crie o banco pelo painel (nome a seu critério) e um usuário com todas
--      as permissões sobre ele.
--   2. Abra o banco no phpMyAdmin → aba "SQL" → cole este arquivo inteiro → "Executar".
--   3. Copie as credenciais para config/env.php (DB_HOST/DB_NAME/DB_USER/DB_PASS).
--   4. No servidor, rode `php install/seed-admin.php` para gerar a senha do super-admin.
--
-- Decisões que impactam o schema:
--   ADR-026: alunos são exclusivos do tenant; professores/super-admin não têm tenant.
--            Uniqueness por (email, tenant_id) via coluna gerada `email_tenant_key`.
--   ADR-030: SEM tabela audit_log.
--   ADR-020: excluir avaliação → cascade em submissões (xp_events fica polimórfico,
--            cascade tratado na camada de aplicação — ver comentário em xp_events).
--   ADR-007: 1 avaliação por CU → UNIQUE em evaluations.competence_unit_id.
--   ADR-023: courses.year como SMALLINT (inteiro do ano civil).
--   ADR-015: evaluation_submissions.grade DECIMAL(3,1).
--
-- Convenções do projeto (ver skill /mysql-schema):
--   - Timestamps: DATETIME (não TIMESTAMP — evita o teto de 2038-01-19).
--   - Charset utf8mb4 + collation utf8mb4_unicode_ci; engine InnoDB.
--   - IDs: BIGINT UNSIGNED AUTO_INCREMENT.
--   - Idempotência: CREATE TABLE IF NOT EXISTS + INSERT IGNORE. Rodar duas vezes é no-op.
--   - FK circular tenants<->users resolvida via SET FOREIGN_KEY_CHECKS=0 no topo.
--   - Palavras reservadas (`groups`) usam backticks; demais identificadores, sem.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE';

-- ----------------------------------------------------------------------------
-- 1. tenants — FK para users já declarada (FOREIGN_KEY_CHECKS desligada)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tenants (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id   BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(150) NOT NULL,
    active          TINYINT(1) NOT NULL DEFAULT 1,
    avatar_style    ENUM('arabe','ocidental') NOT NULL DEFAULT 'arabe',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_tenants_name (name),
    KEY idx_tenants_owner (owner_user_id),
    CONSTRAINT fk_tenants_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. users — ADR-026 (email_tenant_key garante unicidade correta por papel)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id             BIGINT UNSIGNED NULL,
    email                 VARCHAR(191) NOT NULL,
    password_hash         VARCHAR(255) NOT NULL,
    password_changed_at   DATETIME NULL,
    last_login_at         DATETIME NULL,
    name                  VARCHAR(150) NOT NULL,
    gender                ENUM('male','female') NOT NULL DEFAULT 'male',
    id_document           VARCHAR(30)  NULL DEFAULT NULL,
    role                  ENUM('super_admin','teacher','student') NOT NULL,
    language              ENUM('pt','en') NOT NULL DEFAULT 'pt',
    active                TINYINT(1) NOT NULL DEFAULT 1,
    email_tenant_key      VARCHAR(220) GENERATED ALWAYS AS (CONCAT(email, ':', COALESCE(tenant_id, 0))) STORED,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_users_email_tenant (email_tenant_key),
    KEY idx_users_tenant (tenant_id),
    KEY idx_users_role (role),
    CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT chk_users_role_tenant CHECK (
        (role = 'student'     AND tenant_id IS NOT NULL) OR
        (role IN ('teacher','super_admin') AND tenant_id IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. settings — chave/valor para flags globais
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    setting_key    VARCHAR(100) NOT NULL,
    setting_value  VARCHAR(500) NOT NULL,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. courses
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS courses (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id            BIGINT UNSIGNED NOT NULL,
    name                 VARCHAR(150) NOT NULL,
    description          TEXT NULL,
    year                 SMALLINT UNSIGNED NOT NULL,
    language             ENUM('pt','en') NOT NULL DEFAULT 'pt',
    archived             TINYINT(1) NOT NULL DEFAULT 0,
    archived_at          DATETIME NULL,
    cc_mode              ENUM('sequential','free') NOT NULL DEFAULT 'sequential',
    activity_mode        ENUM('sequential','free') NOT NULL DEFAULT 'sequential',
    eval_after_activities TINYINT(1) NOT NULL DEFAULT 1,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_courses_tenant_year (tenant_id, year),
    KEY idx_courses_tenant_archived (tenant_id, archived),
    CONSTRAINT fk_courses_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT chk_courses_year CHECK (year BETWEEN 1900 AND 2100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. core_competencies
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS core_competencies (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    course_id  BIGINT UNSIGNED NOT NULL,
    name       VARCHAR(150) NOT NULL,
    position   INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cc_course_pos (course_id, position),
    CONSTRAINT fk_cc_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. competence_units
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS competence_units (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    core_competency_id  BIGINT UNSIGNED NOT NULL,
    name                VARCHAR(150) NOT NULL,
    position            INT UNSIGNED NOT NULL DEFAULT 0,
    workload_hours      INT UNSIGNED NOT NULL DEFAULT 0,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cu_cc_pos (core_competency_id, position),
    CONSTRAINT fk_cu_cc FOREIGN KEY (core_competency_id) REFERENCES core_competencies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. contents (1 página HTML por CU — UNIQUE competence_unit_id)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contents (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    competence_unit_id  BIGINT UNSIGNED NOT NULL,
    html                MEDIUMTEXT NOT NULL,
    published           TINYINT(1) NOT NULL DEFAULT 0,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_contents_cu (competence_unit_id),
    CONSTRAINT fk_contents_cu FOREIGN KEY (competence_unit_id) REFERENCES competence_units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 8. content_attachments (arquivos anexos a uma página de conteúdo)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS content_attachments (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    content_id  BIGINT UNSIGNED NOT NULL,
    filename    VARCHAR(255) NOT NULL,
    stored_path VARCHAR(500) NOT NULL,
    mime        VARCHAR(100) NOT NULL,
    size_bytes  INT UNSIGNED NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ca_content (content_id),
    CONSTRAINT fk_ca_content FOREIGN KEY (content_id) REFERENCES contents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 9. activities
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activities (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    competence_unit_id    BIGINT UNSIGNED NOT NULL,
    title                 VARCHAR(200) NOT NULL,
    instruction           MEDIUMTEXT NOT NULL,
    type                  ENUM('projeto','codigo') NOT NULL,
    code_language         ENUM('python','csharp','javascript','html') NULL,
    xp_value              INT UNSIGNED NOT NULL DEFAULT 0,
    submission_open       TINYINT(1) NOT NULL DEFAULT 1,
    allow_online_code_run TINYINT(1) NOT NULL DEFAULT 0,
    position              INT UNSIGNED NOT NULL DEFAULT 0,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activities_cu (competence_unit_id),
    CONSTRAINT fk_activities_cu FOREIGN KEY (competence_unit_id) REFERENCES competence_units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 10. activity_submissions — entrega única por (atividade, aluno) — ADR-027
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_submissions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    activity_id     BIGINT UNSIGNED NOT NULL,
    student_user_id BIGINT UNSIGNED NOT NULL,
    filename        VARCHAR(255) NULL,
    stored_path     VARCHAR(500) NULL,
    code_text       MEDIUMTEXT NULL,
    feedback        TEXT NULL,
    feedback_at     DATETIME NULL DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_as_activity_student (activity_id, student_user_id),
    KEY idx_as_student (student_user_id),
    CONSTRAINT fk_as_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    CONSTRAINT fk_as_student  FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 11. evaluations — 1 por CU (ADR-007). tenant_id redundante por design:
-- simplifica toda query do professor (filtra sem JOIN até core_competencies).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS evaluations (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    competence_unit_id  BIGINT UNSIGNED NOT NULL,
    title               VARCHAR(200) NOT NULL,
    instructions        TEXT NULL,
    pdf_path            VARCHAR(500) NULL,
    xp_value            INT UNSIGNED NOT NULL DEFAULT 0,
    submission_open     TINYINT(1) NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_evaluations_cu (competence_unit_id),
    KEY idx_evaluations_tenant (tenant_id),
    CONSTRAINT fk_evaluations_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_evaluations_cu     FOREIGN KEY (competence_unit_id) REFERENCES competence_units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 12. evaluation_submissions — múltiplas tentativas (attempt). idx_es_eval_current
-- acelera listagem do professor (E7-04: WHERE evaluation_id = ? AND is_current = 1).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS evaluation_submissions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    evaluation_id   BIGINT UNSIGNED NOT NULL,
    student_user_id BIGINT UNSIGNED NOT NULL,
    attempt         INT UNSIGNED NOT NULL DEFAULT 1,
    filename        VARCHAR(255) NULL,
    stored_path     VARCHAR(500) NULL,
    grade           DECIMAL(3,1) NULL,
    feedback        TEXT NULL,
    feedback_at     DATETIME NULL DEFAULT NULL,
    retry_allowed   TINYINT(1) NOT NULL DEFAULT 0,
    is_current      TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_es_eval_student_attempt (evaluation_id, student_user_id, attempt),
    KEY idx_es_student (student_user_id),
    KEY idx_es_tenant (tenant_id),
    KEY idx_es_eval_current (evaluation_id, is_current),
    CONSTRAINT fk_es_tenant     FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_es_evaluation FOREIGN KEY (evaluation_id) REFERENCES evaluations(id) ON DELETE CASCADE,
    CONSTRAINT fk_es_student    FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_es_grade     CHECK (grade IS NULL OR (grade >= 0.0 AND grade <= 10.0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 13. enrollments
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS enrollments (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_user_id   BIGINT UNSIGNED NOT NULL,
    course_id         BIGINT UNSIGNED NOT NULL,
    enrolled_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_access_at    DATETIME NULL DEFAULT NULL,
    access_starts_at  DATETIME NULL DEFAULT NULL,
    access_ends_at    DATETIME NULL DEFAULT NULL,
    blocked_at        DATETIME NULL DEFAULT NULL,
    status            ENUM('active','absent','completed') NOT NULL DEFAULT 'active',
    PRIMARY KEY (id),
    UNIQUE KEY uk_enr_student_course (student_user_id, course_id),
    KEY idx_enr_course (course_id),
    CONSTRAINT fk_enr_student FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_enr_course  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 14. groups — agrupamentos de alunos (ex.: Skills Challenge)
-- Backticks obrigatórios: `groups` é palavra reservada no MySQL 8.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `groups` (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id  BIGINT UNSIGNED NOT NULL,
    name       VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_groups_tenant_name (tenant_id, name),
    CONSTRAINT fk_groups_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 15. group_members
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS group_members (
    group_id        BIGINT UNSIGNED NOT NULL,
    student_user_id BIGINT UNSIGNED NOT NULL,
    joined_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id, student_user_id),
    KEY idx_gm_student (student_user_id),
    CONSTRAINT fk_gm_group   FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    CONSTRAINT fk_gm_student FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 16. xp_events — ranking rolante via (student_user_id, created_at)
-- source_type + source_id é polimórfico. Cascade na exclusão de
-- activity/evaluation é tratado na camada de aplicação (ADR-020).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS xp_events (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_user_id BIGINT UNSIGNED NOT NULL,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    course_id       BIGINT UNSIGNED NULL,
    source_type     ENUM('activity','evaluation') NOT NULL,
    source_id       BIGINT UNSIGNED NOT NULL,
    value           INT NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_xp_student_source (student_user_id, source_type, source_id),
    KEY idx_xp_student_created (student_user_id, created_at),
    KEY idx_xp_tenant_created (tenant_id, created_at),
    KEY idx_xp_source (source_type, source_id),
    CONSTRAINT fk_xp_student FOREIGN KEY (student_user_id) REFERENCES users(id)   ON DELETE CASCADE,
    CONSTRAINT fk_xp_tenant  FOREIGN KEY (tenant_id)       REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_xp_course  FOREIGN KEY (course_id)       REFERENCES courses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 17. notifications
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    type       VARCHAR(50) NOT NULL,
    title      VARCHAR(200) NOT NULL,
    body       TEXT NULL,
    link       VARCHAR(500) NULL,
    read_at    DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notif_user_read_created (user_id, read_at, created_at),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 18. password_resets — tokens uso único para recuperação de senha (E1-03).
-- Armazenamos SHA-256 hex do token (não o token em si). FK CASCADE com users.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pr_token (token_hash),
    KEY idx_pr_user_created (user_id, created_at),
    CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 19. login_attempts — usado para rate limit de login (E1-04).
-- Sem FK para users: registra tentativas inclusive com email não cadastrado.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email       VARCHAR(191) NOT NULL,
    ip_address  VARCHAR(45) NOT NULL,
    success     TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_la_email_created (email, created_at),
    KEY idx_la_ip_created (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 20. ranks — patentes por tenant (E9-01). Professor cadastra faixas de XP
-- com nome; aluno vê a patente atual no ProfileSidebar (E14-01). Faixa no
-- topo tem xp_max = NULL ("sem teto"). UK por (tenant_id, name). Índice
-- (tenant_id, xp_min) acelera busca da patente atual por XP do aluno.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ranks (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id  BIGINT UNSIGNED NOT NULL,
    name       VARCHAR(80)  NOT NULL,
    xp_min     INT UNSIGNED NOT NULL,
    xp_max     INT UNSIGNED NULL,
    color_hex  VARCHAR(7)   NOT NULL DEFAULT '#6366F1',
    position   INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ranks_tenant_name (tenant_id, name),
    KEY idx_ranks_tenant_xpmin (tenant_id, xp_min),
    CONSTRAINT fk_ranks_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT chk_ranks_xp_max CHECK (xp_max IS NULL OR xp_max > xp_min)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 18. achievements + student_achievements (E18-01)
-- Conquistas (medalhas). NAO dao XP (XP continua sendo a metrica principal,
-- ADR-002). Catalogo estatico em `achievements` (seed via INSERT IGNORE);
-- desbloqueios em `student_achievements` por (aluno, tenant). Tela mostra
-- apenas alcancaveis pelo tenant; desbloqueio nunca e revogado, mesmo se a
-- condicao virar impossivel depois (ex.: professor apaga curso).
-- Spec: doc/15-roadmap-pos-mvp.md F5.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS achievements (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(60)  NOT NULL,         -- ex.: 'uc_completed_5'
    family      VARCHAR(40)  NOT NULL,         -- ex.: 'uc_completed'
    threshold   INT UNSIGNED NULL,             -- ex.: 5 (NULL para conquistas pontuais)
    icon_key    VARCHAR(40)  NOT NULL,         -- bootstrap-icons name (sem prefixo)
    name_pt     VARCHAR(120) NOT NULL,
    name_en     VARCHAR(120) NOT NULL,
    sort_order  INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ach_code (code),
    KEY idx_ach_family (family, threshold)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Desbloqueios por aluno × tenant. PK composta + FKs CASCADE.
CREATE TABLE IF NOT EXISTS student_achievements (
    student_user_id BIGINT UNSIGNED NOT NULL,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    achievement_id  BIGINT UNSIGNED NOT NULL,
    unlocked_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (student_user_id, tenant_id, achievement_id),
    KEY idx_sa_recent (student_user_id, tenant_id, unlocked_at),
    KEY idx_sa_tenant (tenant_id),
    CONSTRAINT fk_sa_student FOREIGN KEY (student_user_id) REFERENCES users(id)        ON DELETE CASCADE,
    CONSTRAINT fk_sa_tenant  FOREIGN KEY (tenant_id)        REFERENCES tenants(id)     ON DELETE CASCADE,
    CONSTRAINT fk_sa_ach     FOREIGN KEY (achievement_id)   REFERENCES achievements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 19. notification_settings (E21-01)
-- Config por tenant: matriz evento × canal (8 eventos × 2 canais = 16 toggles).
-- Default ON quando linha ausente — não popula seed; nova tabela pra tenant
-- novo é vazia e o helper `notification_enabled` retorna true por default.
-- Spec: doc/15-roadmap-pos-mvp.md F9.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_settings (
    tenant_id   BIGINT UNSIGNED NOT NULL,
    event       VARCHAR(40)     NOT NULL,            -- code do evento (whitelist em app)
    channel     ENUM('bell','email') NOT NULL,
    enabled     TINYINT(1)      NOT NULL DEFAULT 1,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, event, channel),
    CONSTRAINT fk_ns_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SEEDS
-- ============================================================================

-- Flag global: cadastro público de professores desligado no MVP.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('public_registration', 'off');

-- Super-admin placeholder. O hash "!" é um bcrypt inválido que não valida
-- contra nenhuma senha. Rode `php install/seed-admin.php` para gerar uma
-- senha real e sobrescrever este hash.
INSERT IGNORE INTO users (email, password_hash, name, role, language, active, tenant_id) VALUES
    ('admin@lms.local', '!', 'Super Admin', 'super_admin', 'pt', 1, NULL);

-- [E18-01] Seed das 57 conquistas iniciais. INSERT IGNORE absorve UK em
-- re-run (catálogo é estático — `code` é a chave natural). Pra adicionar
-- novas conquistas, basta acrescentar linhas; nunca renomear código de
-- existente (quebraria desbloqueios já gravados em student_achievements).
INSERT IGNORE INTO achievements (code, family, threshold, icon_key, name_pt, name_en, sort_order) VALUES
    -- UC concluídas (11) — icon=mortarboard
    ('uc_completed_1',    'uc_completed', 1,    'mortarboard', 'Concluiu 1 unidade de competência',     'Completed 1 competence unit',    10),
    ('uc_completed_2',    'uc_completed', 2,    'mortarboard', 'Concluiu 2 unidades de competência',    'Completed 2 competence units',   20),
    ('uc_completed_3',    'uc_completed', 3,    'mortarboard', 'Concluiu 3 unidades de competência',    'Completed 3 competence units',   30),
    ('uc_completed_4',    'uc_completed', 4,    'mortarboard', 'Concluiu 4 unidades de competência',    'Completed 4 competence units',   40),
    ('uc_completed_5',    'uc_completed', 5,    'mortarboard', 'Concluiu 5 unidades de competência',    'Completed 5 competence units',   50),
    ('uc_completed_10',   'uc_completed', 10,   'mortarboard', 'Concluiu 10 unidades de competência',   'Completed 10 competence units',  60),
    ('uc_completed_20',   'uc_completed', 20,   'mortarboard', 'Concluiu 20 unidades de competência',   'Completed 20 competence units',  70),
    ('uc_completed_30',   'uc_completed', 30,   'mortarboard', 'Concluiu 30 unidades de competência',   'Completed 30 competence units',  80),
    ('uc_completed_40',   'uc_completed', 40,   'mortarboard', 'Concluiu 40 unidades de competência',   'Completed 40 competence units',  90),
    ('uc_completed_50',   'uc_completed', 50,   'mortarboard', 'Concluiu 50 unidades de competência',   'Completed 50 competence units', 100),
    ('uc_completed_100',  'uc_completed', 100,  'mortarboard', 'Concluiu 100 unidades de competência',  'Completed 100 competence units',110),
    -- CC concluídas (6) — icon=bullseye
    ('cc_completed_1',    'cc_completed', 1,    'bullseye',    'Concluiu 1 competência',                'Completed 1 core competence',   200),
    ('cc_completed_2',    'cc_completed', 2,    'bullseye',    'Concluiu 2 competências',               'Completed 2 core competencies', 210),
    ('cc_completed_3',    'cc_completed', 3,    'bullseye',    'Concluiu 3 competências',               'Completed 3 core competencies', 220),
    ('cc_completed_4',    'cc_completed', 4,    'bullseye',    'Concluiu 4 competências',               'Completed 4 core competencies', 230),
    ('cc_completed_5',    'cc_completed', 5,    'bullseye',    'Concluiu 5 competências',               'Completed 5 core competencies', 240),
    ('cc_completed_10',   'cc_completed', 10,   'bullseye',    'Concluiu 10 competências',              'Completed 10 core competencies',250),
    -- Cursos concluídos (6) — icon=trophy
    ('course_completed_1',  'course_completed', 1,  'trophy', 'Concluiu 1 curso',     'Completed 1 course',     300),
    ('course_completed_2',  'course_completed', 2,  'trophy', 'Concluiu 2 cursos',    'Completed 2 courses',    310),
    ('course_completed_3',  'course_completed', 3,  'trophy', 'Concluiu 3 cursos',    'Completed 3 courses',    320),
    ('course_completed_4',  'course_completed', 4,  'trophy', 'Concluiu 4 cursos',    'Completed 4 courses',    330),
    ('course_completed_5',  'course_completed', 5,  'trophy', 'Concluiu 5 cursos',    'Completed 5 courses',    340),
    ('course_completed_10', 'course_completed', 10, 'trophy', 'Concluiu 10 cursos',   'Completed 10 courses',   350),
    -- Atividades enviadas (15) — icon=pencil-square
    ('activity_submitted_1',   'activity_submitted', 1,   'pencil-square', 'Enviou 1 atividade',     'Submitted 1 activity',    400),
    ('activity_submitted_2',   'activity_submitted', 2,   'pencil-square', 'Enviou 2 atividades',    'Submitted 2 activities',  410),
    ('activity_submitted_3',   'activity_submitted', 3,   'pencil-square', 'Enviou 3 atividades',    'Submitted 3 activities',  420),
    ('activity_submitted_4',   'activity_submitted', 4,   'pencil-square', 'Enviou 4 atividades',    'Submitted 4 activities',  430),
    ('activity_submitted_5',   'activity_submitted', 5,   'pencil-square', 'Enviou 5 atividades',    'Submitted 5 activities',  440),
    ('activity_submitted_10',  'activity_submitted', 10,  'pencil-square', 'Enviou 10 atividades',   'Submitted 10 activities', 450),
    ('activity_submitted_20',  'activity_submitted', 20,  'pencil-square', 'Enviou 20 atividades',   'Submitted 20 activities', 460),
    ('activity_submitted_30',  'activity_submitted', 30,  'pencil-square', 'Enviou 30 atividades',   'Submitted 30 activities', 470),
    ('activity_submitted_40',  'activity_submitted', 40,  'pencil-square', 'Enviou 40 atividades',   'Submitted 40 activities', 480),
    ('activity_submitted_50',  'activity_submitted', 50,  'pencil-square', 'Enviou 50 atividades',   'Submitted 50 activities', 490),
    ('activity_submitted_100', 'activity_submitted', 100, 'pencil-square', 'Enviou 100 atividades',  'Submitted 100 activities',500),
    ('activity_submitted_200', 'activity_submitted', 200, 'pencil-square', 'Enviou 200 atividades',  'Submitted 200 activities',510),
    ('activity_submitted_300', 'activity_submitted', 300, 'pencil-square', 'Enviou 300 atividades',  'Submitted 300 activities',520),
    ('activity_submitted_400', 'activity_submitted', 400, 'pencil-square', 'Enviou 400 atividades',  'Submitted 400 activities',530),
    ('activity_submitted_500', 'activity_submitted', 500, 'pencil-square', 'Enviou 500 atividades',  'Submitted 500 activities',540),
    -- Avaliações enviadas (10) — icon=clipboard-check
    ('evaluation_submitted_1',  'evaluation_submitted', 1,  'clipboard-check', 'Enviou 1 avaliação',    'Submitted 1 evaluation',    600),
    ('evaluation_submitted_2',  'evaluation_submitted', 2,  'clipboard-check', 'Enviou 2 avaliações',   'Submitted 2 evaluations',   610),
    ('evaluation_submitted_3',  'evaluation_submitted', 3,  'clipboard-check', 'Enviou 3 avaliações',   'Submitted 3 evaluations',   620),
    ('evaluation_submitted_4',  'evaluation_submitted', 4,  'clipboard-check', 'Enviou 4 avaliações',   'Submitted 4 evaluations',   630),
    ('evaluation_submitted_5',  'evaluation_submitted', 5,  'clipboard-check', 'Enviou 5 avaliações',   'Submitted 5 evaluations',   640),
    ('evaluation_submitted_10', 'evaluation_submitted', 10, 'clipboard-check', 'Enviou 10 avaliações',  'Submitted 10 evaluations',  650),
    ('evaluation_submitted_20', 'evaluation_submitted', 20, 'clipboard-check', 'Enviou 20 avaliações',  'Submitted 20 evaluations',  660),
    ('evaluation_submitted_30', 'evaluation_submitted', 30, 'clipboard-check', 'Enviou 30 avaliações',  'Submitted 30 evaluations',  670),
    ('evaluation_submitted_40', 'evaluation_submitted', 40, 'clipboard-check', 'Enviou 40 avaliações',  'Submitted 40 evaluations',  680),
    ('evaluation_submitted_50', 'evaluation_submitted', 50, 'clipboard-check', 'Enviou 50 avaliações',  'Submitted 50 evaluations',  690),
    -- Nota máxima por escopo (3) — icon=stars
    ('uc_max_grade',     'uc_max_grade',     NULL, 'stars', 'Nota máxima em uma unidade de competência', 'Perfect score in a competence unit', 700),
    ('cc_max_grade',     'cc_max_grade',     NULL, 'stars', 'Nota máxima em uma competência',            'Perfect score in a core competence', 710),
    ('course_max_grade', 'course_max_grade', NULL, 'stars', 'Nota máxima em um curso',                   'Perfect score in a course',          720),
    -- Nota mínima por avaliação (3) — icon=percent
    ('eval_grade_60_percent',  'eval_grade_60_percent',  60,  'percent', 'Alcançou pelo menos 60% em uma avaliação', 'Achieved at least 60% on an evaluation', 730),
    ('eval_grade_80_percent',  'eval_grade_80_percent',  80,  'percent', 'Alcançou pelo menos 80% em uma avaliação', 'Achieved at least 80% on an evaluation', 740),
    ('eval_grade_100_percent', 'eval_grade_100_percent', 100, 'percent', 'Alcançou 100% em uma avaliação',           'Achieved 100% on an evaluation',         750),
    -- Eventos pontuais (3)
    ('notification_read',     'notification_read',     NULL, 'bell-fill',      'Leu uma notificação',     'Read a notification', 800),
    ('rank_first_promotion',  'rank_first_promotion',  NULL, 'award',          'Obteve uma nova patente', 'Earned a new rank',   810),
    ('course_started',        'course_started',        NULL, 'rocket-takeoff', 'Começou um curso',        'Started a course',    820);

-- ============================================================================
-- MIGRAÇÕES INCREMENTAIS (pós-v0.1.0)
-- ============================================================================
-- Cada ALTER é idempotente via INFORMATION_SCHEMA + PREPARE/EXECUTE. Permite
-- rodar schema.sql inteiro em banco que já tem v0.1.0 aplicado sem erro. O
-- CREATE TABLE IF NOT EXISTS acima contempla bases novas; este bloco alinha
-- bases antigas. Funciona em MySQL 8+ e MariaDB 10.5+.

-- [E3-01] courses.archived_at — timestamp do arquivamento.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'courses'
       AND COLUMN_NAME  = 'archived_at'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE courses ADD COLUMN archived_at DATETIME NULL AFTER archived',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- [E3-01] idx_courses_tenant_archived — acelera filtro "ativos vs arquivados".
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'courses'
       AND INDEX_NAME   = 'idx_courses_tenant_archived'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE courses ADD KEY idx_courses_tenant_archived (tenant_id, archived)',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- [E5-01] contents.published — flag de publicação do conteúdo da CU.
-- Default 0: conteúdo só fica visível ao aluno quando o professor publica
-- explicitamente. Edição existente = conteúdo permanece publicado entre saves
-- se o checkbox "Publicar" continuar marcado.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'contents'
       AND COLUMN_NAME  = 'published'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE contents ADD COLUMN published TINYINT(1) NOT NULL DEFAULT 0 AFTER html',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- [E6-01] activities.position — usada em E6-02 para reordenação (swap +
-- renormalização, mesmo padrão de CC/CU). Default 0 não conflita porque a
-- tela de listagem ordena por (position ASC, id ASC) e todas as atividades
-- existentes vão começar com 0 e são reordenadas na primeira interação.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'activities'
       AND COLUMN_NAME  = 'position'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE activities ADD COLUMN position INT UNSIGNED NOT NULL DEFAULT 0 AFTER allow_online_code_run',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- [Post-E6-05] Reduzir ENUM de activities.type para só `projeto` e `codigo`.
-- Tipos `quiz`, `pesquisa`, `formulario` foram retirados até a modelagem
-- correta ser definida. Antes do ALTER ENUM, rows com valores antigos são
-- migradas pra `projeto` (valor seguro por default). As duas statements
-- são idempotentes: a UPDATE filtra pelos tipos antigos (zero linhas após
-- primeira execução) e o MODIFY reaplica a mesma definição em runs
-- seguintes sem efeito visível. Guarda por INFORMATION_SCHEMA pra pular
-- quando a ENUM já tem só os dois valores.
SET @has_old_types := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'activities'
       AND COLUMN_NAME  = 'type'
       AND (COLUMN_TYPE LIKE '%quiz%' OR COLUMN_TYPE LIKE '%pesquisa%' OR COLUMN_TYPE LIKE '%formulario%')
);
SET @sql := IF(@has_old_types > 0,
    "UPDATE activities SET type = 'projeto' WHERE type IN ('quiz','pesquisa','formulario')",
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_old_types > 0,
    "ALTER TABLE activities MODIFY COLUMN type ENUM('projeto','codigo') NOT NULL",
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- [E6-03] UK composite em xp_events — garante 1 evento de XP por
-- (aluno, source_type, source_id). Permite `INSERT IGNORE` idempotente
-- quando o aluno re-salva a submissão (XP é concedido só na primeira
-- entrega, ADR-002). Se o aluno remove a submissão antes do feedback,
-- o DELETE do xp_event cuida disso (revocação explícita em código).
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'xp_events'
       AND INDEX_NAME   = 'uk_xp_student_source'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE xp_events ADD UNIQUE KEY uk_xp_student_source (student_user_id, source_type, source_id)',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- [E7-00] evaluations.instructions — texto opcional do enunciado,
-- complementar ao PDF (que é o anexo principal).
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'evaluations'
       AND COLUMN_NAME  = 'instructions'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE evaluations ADD COLUMN instructions TEXT NULL AFTER title',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- [E7-00] evaluations.tenant_id — redundância por design pra simplificar
-- filtro de multi-tenancy sem JOIN. Sequência: (1) ADD COLUMN nullable,
-- (2) backfill via JOIN cu→cc→courses, (3) MODIFY NOT NULL, (4) FK + KEY.
-- Em prod v0.5.0 a tabela está vazia (E7 não executado ainda), mas o
-- backfill é defensivo caso alguma base tenha dados herdados.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'evaluations'
       AND COLUMN_NAME  = 'tenant_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE evaluations ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill (no-op após primeira execução — WHERE tenant_id IS NULL zera).
UPDATE evaluations ev
  JOIN competence_units cu  ON cu.id  = ev.competence_unit_id
  JOIN core_competencies cc ON cc.id  = cu.core_competency_id
  JOIN courses c            ON c.id   = cc.course_id
   SET ev.tenant_id = c.tenant_id
 WHERE ev.tenant_id IS NULL;

SET @is_nullable := (
    SELECT IS_NULLABLE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'evaluations'
       AND COLUMN_NAME  = 'tenant_id'
);
SET @sql := IF(@is_nullable = 'YES',
    'ALTER TABLE evaluations MODIFY COLUMN tenant_id BIGINT UNSIGNED NOT NULL',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA    = DATABASE()
       AND TABLE_NAME      = 'evaluations'
       AND CONSTRAINT_NAME = 'fk_evaluations_tenant'
       AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE evaluations ADD CONSTRAINT fk_evaluations_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'evaluations'
       AND INDEX_NAME   = 'idx_evaluations_tenant'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE evaluations ADD KEY idx_evaluations_tenant (tenant_id)',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- [E7-00] evaluation_submissions.tenant_id — mesma lógica de evaluations:
-- redundância por design pra simplificar queries do professor. Backfill
-- via JOIN evaluations→cu→cc→courses.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'evaluation_submissions'
       AND COLUMN_NAME  = 'tenant_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE evaluation_submissions ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE evaluation_submissions es
  JOIN evaluations ev        ON ev.id  = es.evaluation_id
  JOIN competence_units cu   ON cu.id  = ev.competence_unit_id
  JOIN core_competencies cc  ON cc.id  = cu.core_competency_id
  JOIN courses c             ON c.id   = cc.course_id
   SET es.tenant_id = c.tenant_id
 WHERE es.tenant_id IS NULL;

SET @is_nullable := (
    SELECT IS_NULLABLE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'evaluation_submissions'
       AND COLUMN_NAME  = 'tenant_id'
);
SET @sql := IF(@is_nullable = 'YES',
    'ALTER TABLE evaluation_submissions MODIFY COLUMN tenant_id BIGINT UNSIGNED NOT NULL',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA    = DATABASE()
       AND TABLE_NAME      = 'evaluation_submissions'
       AND CONSTRAINT_NAME = 'fk_es_tenant'
       AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE evaluation_submissions ADD CONSTRAINT fk_es_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'evaluation_submissions'
       AND INDEX_NAME   = 'idx_es_tenant'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE evaluation_submissions ADD KEY idx_es_tenant (tenant_id)',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- [E7-00] idx_es_eval_current — acelera listagem do professor (E7-04)
-- filtrando por (evaluation_id, is_current = 1). Composto cobre o caminho
-- quente sem depender do PRIMARY ou de uk_es_eval_student_attempt.
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'evaluation_submissions'
       AND INDEX_NAME   = 'idx_es_eval_current'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE evaluation_submissions ADD KEY idx_es_eval_current (evaluation_id, is_current)',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- [E14-00] competence_units.workload_hours — carga horária em horas cheias
-- exibida nos UnitCards e somada nos CourseCards do painel do aluno.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'competence_units'
       AND COLUMN_NAME  = 'workload_hours'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE competence_units ADD COLUMN workload_hours INT UNSIGNED NOT NULL DEFAULT 0 AFTER position',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- [E8-00] activities.code_language — linguagem da atividade tipo codigo
-- (Python/C#/JS/HTML). NULL pra atividades legacy ou que o professor ainda
-- nao configurou. Editor CodeMirror e Judge0 decidem o fluxo com base nela.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'activities'
       AND COLUMN_NAME  = 'code_language'
);
SET @sql := IF(@col_exists = 0,
    "ALTER TABLE activities ADD COLUMN code_language ENUM('python','csharp','javascript','html') NULL AFTER type",
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- [E14-00] enrollments.last_access_at — timestamp do último acesso do aluno
-- ao curso; atualizado em /student/course/{id} pra CourseCard mostrar.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'enrollments'
       AND COLUMN_NAME  = 'last_access_at'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE enrollments ADD COLUMN last_access_at DATETIME NULL DEFAULT NULL AFTER enrolled_at',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- [E16-01] users.gender — sexo do aluno (obrigatorio no form do professor;
-- usado para escolher avatar default no F11/E17). DEFAULT 'male' cobre o
-- backfill silencioso de alunos legados, alinhado com a decisao do PO em
-- 2026-04-25 (so havia 1 aluno legado em prod, conforme `doc/15` F3).
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'users'
       AND COLUMN_NAME  = 'gender'
);
SET @sql := IF(@col_exists = 0,
    "ALTER TABLE users ADD COLUMN gender ENUM('male','female') NOT NULL DEFAULT 'male' AFTER name",
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- [E16-01] users.id_document — doc de identificacao do aluno (so digitos,
-- max 30 chars). Opcional; professor preenche depois manualmente quando
-- legado. Sem mascara visual — input free-form numerico, validacao server-side.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'users'
       AND COLUMN_NAME  = 'id_document'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN id_document VARCHAR(30) NULL DEFAULT NULL AFTER gender',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- [E16-04] user_logins — historico de conexoes bem-sucedidas. Coletado em
-- AuthController::completeLogin (apos password ok + sessao regenerada).
-- IP via REMOTE_ADDR ou X-Forwarded-For (primeira entrada se setado);
-- location preenchido por GeoIPClient (ip-api.com free tier 45req/min,
-- fallback NULL em falha). Retencao 180 dias via cron purge-old-logins.
-- Visivel APENAS pelo professor no detalhe do aluno. LGPD note em doc/14.
CREATE TABLE IF NOT EXISTS user_logins (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NOT NULL,
    tenant_id     BIGINT UNSIGNED NULL,
    ip            VARCHAR(45)     NOT NULL,
    location      VARCHAR(120)    NULL,
    user_agent    VARCHAR(255)    NULL,
    logged_in_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ul_user_recent (user_id, logged_in_at),
    KEY idx_ul_purge (logged_in_at),
    CONSTRAINT fk_ul_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [E17-04] tenants.avatar_style — estilo do avatar default dos alunos do
-- tenant. ENUM('arabe','ocidental'); 'arabe' default por causa do contexto
-- principal (EAU). Combina com users.gender (E16-01) pra compor o asset SVG
-- final em public/assets/avatars/{style}-{gender}.svg via helper
-- student_avatar_url.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'tenants'
       AND COLUMN_NAME  = 'avatar_style'
);
SET @sql := IF(@col_exists = 0,
    "ALTER TABLE tenants ADD COLUMN avatar_style ENUM('arabe','ocidental') NOT NULL DEFAULT 'arabe' AFTER active",
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- [E17-01] enrollments.access_starts_at + access_ends_at — periodo opcional
-- de acesso do aluno ao curso. NULL no inicio = imediato; NULL no fim =
-- ilimitado. Usado pelo gate /student/* (E17-03) que combina com blocked_at
-- (E17-02) pra renderizar o curso como "indisponivel".
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'enrollments'
       AND COLUMN_NAME  = 'access_starts_at'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE enrollments ADD COLUMN access_starts_at DATETIME NULL DEFAULT NULL AFTER last_access_at',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'enrollments'
       AND COLUMN_NAME  = 'access_ends_at'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE enrollments ADD COLUMN access_ends_at DATETIME NULL DEFAULT NULL AFTER access_starts_at',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- [E17-02] enrollments.blocked_at — bloqueio manual do acesso do aluno ao
-- curso. NULL = sem bloqueio; DATETIME = bloqueado naquele instante. Reversivel
-- (professor desbloqueia setando NULL). Bloquear preserva XP/progresso/historico
-- — diferente de remover, que e DELETE definitivo. Combina com access_starts/ends_at
-- (E17-01) no gate UI do aluno (E17-03). Aluno NAO ve este campo direto.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'enrollments'
       AND COLUMN_NAME  = 'blocked_at'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE enrollments ADD COLUMN blocked_at DATETIME NULL DEFAULT NULL AFTER access_ends_at',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- [E16-03] enrollments.status — status do aluno no curso, visivel APENAS pro
-- professor (active/absent/completed). E so indicativo: nao altera regras de
-- negocio (XP, progresso, ranking continuam normais). Manual sempre — sem
-- auto-set. Aluno NAO ve esse campo. Default 'active' aplica retroativamente
-- pra matriculas existentes via DEFAULT do schema.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'enrollments'
       AND COLUMN_NAME  = 'status'
);
SET @sql := IF(@col_exists = 0,
    "ALTER TABLE enrollments ADD COLUMN status ENUM('active','absent','completed') NOT NULL DEFAULT 'active' AFTER last_access_at",
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- [E19-01] courses.cc_mode + activity_mode + eval_after_activities — modos
-- de progressao por curso (F7). Default sequencial pra todos os cursos
-- existentes — professor pode trocar pra 'free' se preferir o comportamento
-- antigo (tudo nitido). Schema docs: doc/15-roadmap-pos-mvp.md F7 + ADRs em
-- doc/14 (sem ADR dedicado — decisao consolidada do PO em 2026-04-25).
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'courses'
       AND COLUMN_NAME  = 'cc_mode'
);
SET @sql := IF(@col_exists = 0,
    "ALTER TABLE courses ADD COLUMN cc_mode ENUM('sequential','free') NOT NULL DEFAULT 'sequential' AFTER archived_at",
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'courses'
       AND COLUMN_NAME  = 'activity_mode'
);
SET @sql := IF(@col_exists = 0,
    "ALTER TABLE courses ADD COLUMN activity_mode ENUM('sequential','free') NOT NULL DEFAULT 'sequential' AFTER cc_mode",
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'courses'
       AND COLUMN_NAME  = 'eval_after_activities'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE courses ADD COLUMN eval_after_activities TINYINT(1) NOT NULL DEFAULT 1 AFTER activity_mode',
    'DO 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
SET FOREIGN_KEY_CHECKS = 1;
SET SQL_MODE = @OLD_SQL_MODE;
