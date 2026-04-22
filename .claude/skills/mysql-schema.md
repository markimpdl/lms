---
name: mysql-schema
description: "Gera ou edita o schema MySQL do LMS em install/schema.sql (InnoDB + utf8mb4, multi-tenant por coluna, idempotente para rodar no phpMyAdmin)."
version: "1.1.0"
---

# LMS — MySQL Schema

## Role

Você gera/altera o schema MySQL do LMS. Não há sistema de migrations: o schema vive em **`install/schema.sql`** e é executado manualmente no phpMyAdmin (ADR-017). **Toda alteração em `install/schema.sql` passa por esta skill** — o CLAUDE.md estipula essa obrigatoriedade.

## Stack

- MySQL 8.0+ (hospedagem Hostinger cPanel)
- Engine `InnoDB` (FK + transação)
- Charset `utf8mb4` / collation `utf8mb4_unicode_ci`
- IDs: `BIGINT UNSIGNED AUTO_INCREMENT`
- Timestamps: **`DATETIME`** (não `TIMESTAMP` — evita o teto de 2038-01-19 e ambiguidade de timezone)
- Multi-tenant: coluna `tenant_id BIGINT UNSIGNED` em toda tabela do tenant

## Convenções obrigatórias

1. Sempre `CREATE TABLE IF NOT EXISTS` (idempotente).
2. **Timestamps:** `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` em toda tabela. Tabelas mutáveis ganham também `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`.
3. Toda tabela que pertence a um tenant carrega `tenant_id BIGINT UNSIGNED` + FK para `tenants(id)` `ON DELETE CASCADE`.
4. FKs com `ON DELETE` explícito (`CASCADE`, `SET NULL` ou `RESTRICT`).
5. Índices em toda coluna usada em `WHERE`/`ORDER BY`/`JOIN`.
6. `UNIQUE` onde a regra de negócio exige (ex.: 1 conteúdo por CU, 1 entrega por aluno+atividade).
7. `CHECK` constraints para invariantes de dados (ex.: `grade BETWEEN 0 AND 10`, ano válido, role ↔ tenant_id — ver ADR-026).
8. Nomes de constraints/índices com prefixo: `fk_*`, `uk_*`, `idx_*`, `chk_*`. Limite de 64 chars (enforcado pelo MySQL).
9. **Backticks só em palavras reservadas** (ex.: `` `groups` ``). Em identificadores comuns, sem backticks (reduz ruído visual).
10. FK circular (tenants ↔ users): `SET FOREIGN_KEY_CHECKS = 0;` no topo do arquivo e `= 1;` antes dos seeds. Mantém todas as FKs declaradas dentro do `CREATE TABLE`.
11. Seeds com `INSERT IGNORE` (preferido) ou `ON DUPLICATE KEY UPDATE`. Nunca `INSERT` sem proteção contra re-execução.
12. **NÃO existe tabela `audit_log`** (ADR-030 removeu o Epic E12 inteiro).

## Estrutura do arquivo

```sql
-- ============================================================
-- LMS — install/schema.sql
-- Rodar no phpMyAdmin após criar o database
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE';

-- TABELAS (na ordem de dependência; FK circulares são OK com FK_CHECKS=0)

CREATE TABLE IF NOT EXISTS tenants   ( ... ) ENGINE=InnoDB ... ;
CREATE TABLE IF NOT EXISTS users     ( ... ) ENGINE=InnoDB ... ;
-- ... etc

-- SEEDS

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('public_registration', 'off');

SET FOREIGN_KEY_CHECKS = 1;
SET SQL_MODE = @OLD_SQL_MODE;
```

## Padrões específicos do LMS

### `users` — ADR-026 (aluno exclusivo do tenant)

```sql
CREATE TABLE IF NOT EXISTS users (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id         BIGINT UNSIGNED NULL,
    email             VARCHAR(191) NOT NULL,
    password_hash     VARCHAR(255) NOT NULL,
    name              VARCHAR(150) NOT NULL,
    role              ENUM('super_admin','teacher','student') NOT NULL,
    language          ENUM('pt','en') NOT NULL DEFAULT 'pt',
    active            TINYINT(1) NOT NULL DEFAULT 1,
    email_tenant_key  VARCHAR(220) GENERATED ALWAYS AS (CONCAT(email, ':', COALESCE(tenant_id, 0))) STORED,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
```

Pontos-chave: `tenant_id` é `NULL` para professor/super-admin e **NOT NULL** para aluno (enforçado pelo `CHECK`). Unicidade via coluna gerada `email_tenant_key` garante (a) email único entre professores/super-admins globalmente, (b) único entre alunos do mesmo tenant, (c) livre entre alunos de tenants distintos.

### `tenants` — FK circular para `users`

```sql
CREATE TABLE IF NOT EXISTS tenants (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id   BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(150) NOT NULL,
    active          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tenants_owner (owner_user_id),
    CONSTRAINT fk_tenants_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`RESTRICT` porque ADR-024 garante que professor nunca é excluído enquanto for dono de um tenant.

## Índices críticos (referência)

| Tabela                    | Índice                                                      | Por quê                                  |
| ------------------------- | ----------------------------------------------------------- | ---------------------------------------- |
| `users`                   | UNIQUE `email_tenant_key` (gerado)                          | ADR-026 — unicidade por papel            |
| `xp_events`               | `(student_user_id, created_at)`                             | Ranking por janela rolante               |
| `activity_submissions`    | UNIQUE `(activity_id, student_user_id)`                     | Entrega única por atividade              |
| `evaluation_submissions`  | UNIQUE `(evaluation_id, student_user_id, attempt)`          | Histórico de tentativas                  |
| `contents`                | UNIQUE `(competence_unit_id)`                               | 1 página por CU                          |
| `evaluations`             | UNIQUE `(competence_unit_id)`                               | ADR-007 — 1 avaliação por CU             |
| `notifications`           | `(user_id, read_at, created_at)`                            | Sininho filtra não-lidas por data        |
| `enrollments`             | UNIQUE `(student_user_id, course_id)`                       | Matrícula única                          |
| `courses`                 | `(tenant_id, year)`                                         | Filtro por tenant + ano                  |
| `login_attempts`          | `(email, created_at)` e `(ip_address, created_at)`          | Rate limit de login                      |

## Convenções de tipos

| Tipo de dado            | Coluna MySQL                                                            | Observação                              |
| ----------------------- | ----------------------------------------------------------------------- | --------------------------------------- |
| ID interno              | `BIGINT UNSIGNED AUTO_INCREMENT`                                        | PK                                      |
| FK                      | `BIGINT UNSIGNED`                                                       | Casado com PK referenciada              |
| Email                   | `VARCHAR(191)`                                                          | 191 para utf8mb4 + index                |
| Senha (hash bcrypt)     | `VARCHAR(255)`                                                          | bcrypt gera ~60 chars                   |
| Nome / título           | `VARCHAR(150)` ou `VARCHAR(200)`                                        | —                                       |
| Texto curto             | `TEXT`                                                                  | Descrições                              |
| HTML rico (conteúdo)    | `MEDIUMTEXT`                                                            | Até 16 MB                               |
| Arquivo (path relativo) | `VARCHAR(500)`                                                          | Dentro de `storage/`                    |
| Booleano                | `TINYINT(1) NOT NULL DEFAULT 0/1`                                       | —                                       |
| Nota                    | `DECIMAL(3,1)`                                                          | 0.0 a 10.0 (ADR-015)                    |
| Ano                     | `SMALLINT UNSIGNED` + CHECK 1900–2100                                   | ADR-023                                 |
| XP                      | `INT`                                                                   | Pode ser negativo se houver penalidade  |
| ENUM curto              | `ENUM('a','b','c')`                                                     | Valores fechados                        |
| IP (v4 ou v6)           | `VARCHAR(45)`                                                           | 45 cobre IPv6 full                      |
| Timestamp de criação    | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`                           | —                                       |
| Timestamp de update     | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | —                                     |

## Regras de cascade

| Excluir...                  | Cascateia para...                                                                            |
| --------------------------- | -------------------------------------------------------------------------------------------- |
| `tenants(id)`               | `users` (com tenant_id), `courses`, `groups`, `xp_events` via tenant_id                      |
| `users(id)` (aluno)         | `enrollments`, `group_members`, `activity_submissions`, `evaluation_submissions`, `xp_events` |
| `users(id)` (professor)     | **RESTRICT** — bloqueado enquanto for `owner` de algum tenant (ADR-024)                      |
| `courses(id)`               | `core_competencies`; `xp_events.course_id` vira NULL (SET NULL)                              |
| `core_competencies(id)`     | `competence_units`                                                                           |
| `competence_units(id)`      | `contents`, `content_attachments` (via contents), `activities`, `evaluations`                |
| `activities(id)`            | `activity_submissions`; `xp_events` com `source_type='activity'` tratado na aplicação         |
| `evaluations(id)`           | `evaluation_submissions`; `xp_events` com `source_type='evaluation'` tratado na aplicação    |

`xp_events` tem `source_type` + `source_id` polimórficos: não há FK direta para `activities`/`evaluations`; o cascade é responsabilidade do service que deleta a atividade/avaliação (ADR-020).

## Seeds

Use **`INSERT IGNORE`** preferencialmente. Para seeds que precisam atualizar um valor existente, `ON DUPLICATE KEY UPDATE` é aceitável.

```sql
-- settings globais
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('public_registration', 'off');

-- Super-admin (hash '!' é placeholder; seed-admin.php gera a senha real)
INSERT IGNORE INTO users (email, password_hash, name, role, language, active, tenant_id) VALUES
    ('admin@lms.local', '!', 'Super Admin', 'super_admin', 'pt', 1, NULL);
```

A senha real do super-admin é gerada e impressa pelo script `install/seed-admin.php` (roda via CLI após o phpMyAdmin).

## Checklist para nova tabela

- [ ] `CREATE TABLE IF NOT EXISTS`
- [ ] `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`
- [ ] `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- [ ] `tenant_id BIGINT UNSIGNED NOT NULL` + FK CASCADE para `tenants` (se pertence a tenant)
- [ ] `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- [ ] `updated_at` se for mutável
- [ ] Índices em WHERE/ORDER BY/JOIN
- [ ] UNIQUE onde regra exige
- [ ] CHECK para invariantes simples
- [ ] FK com `ON DELETE` explícito
- [ ] Nomes `fk_*`/`uk_*`/`idx_*`/`chk_*` dentro de 64 chars
- [ ] Se for palavra reservada no MySQL, envolver em backticks
- [ ] Inserida na ordem correta do arquivo (respeita dependências quando `FK_CHECKS=1`)

## Exemplo de fluxo

**Input:** "Preciso rastrear tentativas de login para rate limit (E1-04)."

**Output:** tabela `login_attempts` com `email`, `ip_address VARCHAR(45)` (IPv6-safe), `success TINYINT(1)`, `created_at DATETIME`. Sem FK para `users` (queremos registrar tentativas mesmo com email inexistente). Índices `(email, created_at)` e `(ip_address, created_at)` para consultas de "últimos N minutos". Sem `tenant_id` (login ocorre antes de saber o tenant).

Ver `install/schema.sql` para a implementação final.
