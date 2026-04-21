---
name: mysql-schema
description: "Gera ou edita o schema MySQL do LMS em install/schema.sql (InnoDB + utf8mb4, multi-tenant por coluna, idempotente para rodar no phpMyAdmin)."
version: "1.0.0"
---

# LMS — MySQL Schema

## Role

Você gera/altera o schema MySQL do LMS. Não há sistema de migrations: o schema vive em **`install/schema.sql`** e é executado manualmente no phpMyAdmin (decisão ADR-017).

## Stack

- MySQL 8.0+ (ou MariaDB 10.6+)
- Engine InnoDB (FK + transação)
- Charset `utf8mb4` / collation `utf8mb4_unicode_ci`
- IDs: `BIGINT UNSIGNED AUTO_INCREMENT`
- Timestamps: `DATETIME` (não `TIMESTAMP` — issue de 2038)
- Multi-tenant: coluna `tenant_id BIGINT UNSIGNED` em toda tabela do tenant

## Instruções

1. Sempre `CREATE TABLE IF NOT EXISTS` (idempotente)
2. Toda tabela do tenant tem coluna `tenant_id` + FK para `tenants(id)` ON DELETE CASCADE
3. Toda tabela tem `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
4. Tabelas mutáveis têm `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
5. FKs com `ON DELETE CASCADE` ou `ON DELETE SET NULL` explícito
6. Índices em todos os campos usados em `WHERE`/`ORDER BY`/`JOIN`
7. UNIQUE em colunas onde a regra de negócio exige unicidade (ex.: 1 conteúdo por CU, 1 entrega por aluno+atividade)
8. Quando alterar tabela existente, preferir `ALTER TABLE` no final do arquivo + comentário `-- v1.X — descrição` para rastrear

## Estrutura do arquivo

```sql
-- ============================================================
-- LMS — install/schema.sql
-- Rodar no phpMyAdmin após criar o database
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- TABELAS

CREATE TABLE IF NOT EXISTS `tenants` ( ... ) ENGINE=InnoDB ... ;
CREATE TABLE IF NOT EXISTS `users`   ( ... ) ENGINE=InnoDB ... ;
-- ... etc

-- SEEDS

INSERT INTO `users` (...) VALUES (...) ON DUPLICATE KEY UPDATE ...;

SET FOREIGN_KEY_CHECKS = 1;
```

## Tabelas centrais (referência)

```sql
-- Tenants (1 por professor)
CREATE TABLE IF NOT EXISTS `tenants` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `owner_user_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_tenants_owner` (`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuários (qualquer papel)
CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(191) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `role` ENUM('super_admin','teacher','student') NOT NULL,
    `language` ENUM('pt','en') NOT NULL DEFAULT 'pt',
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cursos
CREATE TABLE IF NOT EXISTS `courses` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `description` TEXT NULL,
    `year` SMALLINT UNSIGNED NOT NULL,
    `language` ENUM('pt','en') NOT NULL DEFAULT 'pt',
    `archived` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_courses_tenant_year` (`tenant_id`, `year`),
    CONSTRAINT `fk_courses_tenant`
        FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ... (CCs, CUs, conteúdos, atividades, avaliações, submissões, xp_events, etc — ver doc/12-modelo-de-dados.md)
```

## Índices críticos

| Tabela                    | Índice                                                       | Por quê                                  |
| ------------------------- | ------------------------------------------------------------ | ---------------------------------------- |
| `xp_events`               | `(student_user_id, created_at)`                              | Ranking por janela rolante               |
| `activity_submissions`    | UNIQUE `(activity_id, student_user_id)`                      | Entrega única                            |
| `evaluation_submissions`  | UNIQUE `(evaluation_id, student_user_id, attempt)`           | Histórico de tentativas                  |
| `notifications`           | `(user_id, read_at, created_at DESC)`                        | Sininho ordena por data desc, não-lidas  |
| `enrollments`             | UNIQUE `(student_user_id, course_id)`                        | Matrícula única                          |
| `courses`                 | `(tenant_id, year)`                                          | Filtro por tenant + ano                  |

## Convenções de tipos

| Tipo de dado            | Coluna MySQL                                                     | Observação                            |
| ----------------------- | ---------------------------------------------------------------- | ------------------------------------- |
| ID interno              | `BIGINT UNSIGNED AUTO_INCREMENT`                                 | PK                                    |
| FK                      | `BIGINT UNSIGNED`                                                | Casado com PK referenciada            |
| Email                   | `VARCHAR(191)`                                                   | UNIQUE (max 191 para utf8mb4 + index) |
| Senha (hash bcrypt)     | `VARCHAR(255)`                                                   | bcrypt gera ~60 chars                 |
| Nome / título           | `VARCHAR(150)` ou `VARCHAR(200)`                                 | —                                     |
| Texto curto             | `TEXT`                                                           | Descrições                            |
| HTML rico (conteúdo)    | `MEDIUMTEXT`                                                     | Até 16 MB                             |
| Arquivo (path)          | `VARCHAR(500)`                                                   | path relativo dentro de `storage/`    |
| Booleano                | `TINYINT(1)` NOT NULL DEFAULT 0/1                                | —                                     |
| Nota                    | `DECIMAL(3,1)`                                                   | 0.0 a 10.0                            |
| Ano                     | `SMALLINT UNSIGNED`                                              | 2025, 2026…                           |
| XP                      | `INT`                                                            | Pode ser negativo se houver penalidade |
| ENUM curto              | `ENUM('a','b','c')`                                              | Valores fechados                      |
| Timestamp criação       | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`                    | —                                     |
| Timestamp atualização   | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | —                            |

## Regras de cascade

| Excluir...                  | Cascateia para...                                                                            |
| --------------------------- | -------------------------------------------------------------------------------------------- |
| `tenants(id)`               | courses, groups, users (de role student criados pelo tenant), notifications relacionadas     |
| `courses(id)`               | core_competencies, enrollments, xp_events com `course_id`                                     |
| `core_competencies(id)`     | competence_units                                                                              |
| `competence_units(id)`      | contents, activities, evaluations                                                             |
| `activities(id)`            | activity_submissions, xp_events com `source_type='activity'`                                  |
| `evaluations(id)`           | evaluation_submissions, xp_events com `source_type='evaluation'`                              |
| `users(id)` (aluno)         | enrollments, group_members, submissions, xp_events                                            |

## Seeds

```sql
-- Super-admin inicial
INSERT INTO `users` (`email`, `password_hash`, `name`, `role`, `language`, `active`)
VALUES (
    'admin@lms.local',
    '$2y$12$REPLACE_WITH_BCRYPT_HASH',
    'Super Admin',
    'super_admin',
    'pt',
    1
) ON DUPLICATE KEY UPDATE `email` = `email`;
```

Hash gerado com:

```bash
php -r "echo password_hash('SENHA_INICIAL', PASSWORD_BCRYPT, ['cost' => 12]);"
```

## Checklist

- [ ] `SET NAMES utf8mb4;` no topo
- [ ] `SET FOREIGN_KEY_CHECKS = 0;` antes / `= 1;` depois
- [ ] Toda tabela do tenant tem `tenant_id` + FK CASCADE para `tenants`
- [ ] `CREATE TABLE IF NOT EXISTS` (nunca `DROP`)
- [ ] Índices em colunas usadas em `WHERE`/`ORDER BY`/`JOIN`
- [ ] UNIQUE onde a regra de negócio exige
- [ ] FKs com `ON DELETE` explícito
- [ ] Engine `InnoDB`, charset `utf8mb4`, collation `utf8mb4_unicode_ci`
- [ ] `created_at` em toda tabela; `updated_at` em tabelas mutáveis

## Exemplo

**Input:** "Preciso de uma tabela `course_categories` por tenant"

**Output:** Bloco SQL com `CREATE TABLE IF NOT EXISTS course_categories` (`id`, `tenant_id`, `name`, `position`, `created_at`), FK para tenants CASCADE, índice `(tenant_id)`, UNIQUE `(tenant_id, name)` para impedir duplicatas no mesmo tenant. Adicionar no final de `install/schema.sql` com comentário `-- v1.X — categories`.
