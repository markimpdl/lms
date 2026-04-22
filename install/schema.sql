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
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id    BIGINT UNSIGNED NOT NULL,
    name         VARCHAR(150) NOT NULL,
    description  TEXT NULL,
    year         SMALLINT UNSIGNED NOT NULL,
    language     ENUM('pt','en') NOT NULL DEFAULT 'pt',
    archived     TINYINT(1) NOT NULL DEFAULT 0,
    archived_at  DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
    type                  ENUM('quiz','pesquisa','formulario','projeto','codigo') NOT NULL,
    xp_value              INT UNSIGNED NOT NULL DEFAULT 0,
    submission_open       TINYINT(1) NOT NULL DEFAULT 1,
    allow_online_code_run TINYINT(1) NOT NULL DEFAULT 0,
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
-- 11. evaluations — 1 por CU (ADR-007)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS evaluations (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    competence_unit_id  BIGINT UNSIGNED NOT NULL,
    title               VARCHAR(200) NOT NULL,
    pdf_path            VARCHAR(500) NULL,
    xp_value            INT UNSIGNED NOT NULL DEFAULT 0,
    submission_open     TINYINT(1) NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_evaluations_cu (competence_unit_id),
    CONSTRAINT fk_evaluations_cu FOREIGN KEY (competence_unit_id) REFERENCES competence_units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 12. evaluation_submissions — múltiplas tentativas (attempt)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS evaluation_submissions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
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
    CONSTRAINT fk_es_evaluation FOREIGN KEY (evaluation_id) REFERENCES evaluations(id) ON DELETE CASCADE,
    CONSTRAINT fk_es_student    FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_es_grade     CHECK (grade IS NULL OR (grade >= 0.0 AND grade <= 10.0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 13. enrollments
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS enrollments (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_user_id BIGINT UNSIGNED NOT NULL,
    course_id       BIGINT UNSIGNED NOT NULL,
    enrolled_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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

-- ----------------------------------------------------------------------------
SET FOREIGN_KEY_CHECKS = 1;
SET SQL_MODE = @OLD_SQL_MODE;
