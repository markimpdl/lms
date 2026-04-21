---
name: php-mysql-model
description: "Gera classes de Model PHP 8 para o LMS com PDO, prepared statements, isolamento por tenant_id (professor) ou enrollment (aluno), e assinaturas tipadas."
version: "1.0.0"
---

# LMS — Model Generator

## Role

Você gera classes de Model PHP para o LMS. Cada Model encapsula 1 tabela, usa PDO via `MySQL::pdo()` (singleton) e **nunca** concatena strings em SQL.

## Regras absolutas

1. Arquivo em `src/models/<Entity>.php` — 1 classe por tabela
2. Sem estado no Model — instância é descartável
3. Prepared statements em 100% das queries
4. `FETCH_ASSOC` para retornos
5. **Toda query do professor recebe `int $tenantId`** e filtra por `tenant_id = :tid`
6. **Toda query do aluno recebe `int $studentId`** e respeita o pertencimento (curso onde tem matrícula, grupo onde é membro, etc.)
7. Métodos retornam `array`, `?array`, `bool`, `int` — nunca `mixed`
8. `declare(strict_types=1);` no topo
9. Nunca expor `Throwable::getMessage()` — deixar a exceção propagar para o controller
10. Classe `final` (sem herança)

## Contrato padrão (entidade do tenant)

| Método                                              | Retorno  | Função                                    |
| --------------------------------------------------- | -------- | ----------------------------------------- |
| `listForTenant(int $tenantId, array $filters=[])`   | `array`  | Listagem principal                        |
| `findById(int $tenantId, int $id)`                  | `?array` | Busca 1 registro filtrado pelo tenant     |
| `create(int $tenantId, array $data)`                | `int`    | INSERT, retorna o ID novo                 |
| `update(int $tenantId, int $id, array $data)`       | `bool`   | UPDATE filtrado por tenant + id           |
| `delete(int $tenantId, int $id)`                    | `bool`   | DELETE ou soft-delete                     |

## Contrato (entidade visível ao aluno)

| Método                                                       | Retorno   | Função                                                         |
| ------------------------------------------------------------ | --------- | -------------------------------------------------------------- |
| `listForStudent(int $studentId, array $filters=[])`          | `array`   | Listagem respeitando matrículas ativas                         |
| `findForStudent(int $studentId, int $id)`                    | `?array`  | Busca 1 registro só se aluno tem acesso                        |
| `submitForStudent(int $studentId, int $entityId, array $d)`  | `int`     | Submissão (entrega de atividade/avaliação) com validação       |

## Template — entidade de tenant (ex.: Course)

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/MySQL.php';

final class Course
{
    public function listForTenant(int $tenantId, array $filters = []): array
    {
        $sql = 'SELECT id, name, description, year, language, archived, created_at
                FROM courses
                WHERE tenant_id = :tid';
        $params = [':tid' => $tenantId];

        if (!empty($filters['only_active'])) {
            $sql .= ' AND archived = 0';
        }
        if (!empty($filters['year'])) {
            $sql .= ' AND year = :year';
            $params[':year'] = $filters['year'];
        }

        $sql .= ' ORDER BY year DESC, name ASC';

        $stmt = MySQL::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $tenantId, int $id): ?array
    {
        $stmt = MySQL::pdo()->prepare(
            'SELECT id, name, description, year, language, archived, created_at
             FROM courses
             WHERE tenant_id = :tid AND id = :id
             LIMIT 1'
        );
        $stmt->execute([':tid' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(int $tenantId, array $data): int
    {
        $stmt = MySQL::pdo()->prepare(
            'INSERT INTO courses
                (tenant_id, name, description, year, language, archived, created_at)
             VALUES
                (:tid, :name, :desc, :year, :lang, 0, NOW())'
        );
        $stmt->execute([
            ':tid'  => $tenantId,
            ':name' => $data['name'],
            ':desc' => $data['description'] ?? null,
            ':year' => (int) $data['year'],
            ':lang' => $data['language'] ?? 'pt',
        ]);
        return (int) MySQL::pdo()->lastInsertId();
    }

    public function update(int $tenantId, int $id, array $data): bool
    {
        $stmt = MySQL::pdo()->prepare(
            'UPDATE courses
                SET name = :name,
                    description = :desc,
                    year = :year,
                    language = :lang
              WHERE tenant_id = :tid AND id = :id'
        );
        return $stmt->execute([
            ':tid'  => $tenantId,
            ':id'   => $id,
            ':name' => $data['name'],
            ':desc' => $data['description'] ?? null,
            ':year' => (int) $data['year'],
            ':lang' => $data['language'] ?? 'pt',
        ]);
    }

    public function delete(int $tenantId, int $id): bool
    {
        // Soft delete via archived (preserva FKs de submissões antigas)
        $stmt = MySQL::pdo()->prepare(
            'UPDATE courses SET archived = 1 WHERE tenant_id = :tid AND id = :id'
        );
        return $stmt->execute([':tid' => $tenantId, ':id' => $id]);
    }
}
```

## Template — entidade visível ao aluno (ex.: ContentForStudent via JOIN)

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/MySQL.php';

final class StudentContent
{
    public function listForStudent(int $studentId, int $courseId): array
    {
        // Garante que o aluno tem matrícula ativa no curso antes de retornar nada
        $stmt = MySQL::pdo()->prepare(
            'SELECT cu.id AS unit_id, cu.name AS unit_name,
                    cc.id AS cc_id, cc.name AS cc_name,
                    c.id AS course_id, c.name AS course_name
             FROM enrollments e
             INNER JOIN courses c           ON c.id  = e.course_id   AND c.archived = 0
             INNER JOIN core_competencies cc ON cc.course_id = c.id
             INNER JOIN competence_units cu  ON cu.core_competency_id = cc.id
             WHERE e.student_user_id = :sid
               AND e.course_id = :cid
             ORDER BY cc.position ASC, cu.position ASC'
        );
        $stmt->execute([':sid' => $studentId, ':cid' => $courseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findUnit(int $studentId, int $unitId): ?array
    {
        $stmt = MySQL::pdo()->prepare(
            'SELECT cu.id, cu.name, cu.position,
                    cc.id AS cc_id, cc.name AS cc_name,
                    c.id  AS course_id, c.name AS course_name
             FROM competence_units cu
             INNER JOIN core_competencies cc ON cc.id = cu.core_competency_id
             INNER JOIN courses c            ON c.id  = cc.course_id
             INNER JOIN enrollments e        ON e.course_id = c.id AND e.student_user_id = :sid
             WHERE cu.id = :uid
             LIMIT 1'
        );
        $stmt->execute([':sid' => $studentId, ':uid' => $unitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
```

## Variações comuns

### XP event (escrita pelo serviço, leitura por ranking)

```php
public function awardActivityXp(int $studentId, int $tenantId, int $courseId, int $activityId, int $value): void
{
    $stmt = MySQL::pdo()->prepare(
        'INSERT INTO xp_events
            (student_user_id, tenant_id, course_id, source_type, source_id, value, created_at)
         VALUES
            (:sid, :tid, :cid, "activity", :aid, :val, NOW())'
    );
    $stmt->execute([
        ':sid' => $studentId, ':tid' => $tenantId, ':cid' => $courseId,
        ':aid' => $activityId, ':val' => $value,
    ]);
}
```

### Reenvio de avaliação (UPDATE com is_current)

```php
public function newAttempt(int $studentId, int $evaluationId, array $data): int
{
    $pdo = MySQL::pdo();
    $pdo->beginTransaction();

    try {
        // Tira current da tentativa anterior
        $pdo->prepare(
            'UPDATE evaluation_submissions
                SET is_current = 0
              WHERE evaluation_id = :eid AND student_user_id = :sid'
        )->execute([':eid' => $evaluationId, ':sid' => $studentId]);

        // Insere nova tentativa como current
        $pdo->prepare(
            'INSERT INTO evaluation_submissions
                (evaluation_id, student_user_id, attempt, filename, stored_path,
                 is_current, created_at)
             SELECT :eid, :sid, COALESCE(MAX(attempt), 0) + 1, :file, :path, 1, NOW()
             FROM evaluation_submissions
             WHERE evaluation_id = :eid AND student_user_id = :sid'
        )->execute([
            ':eid' => $evaluationId, ':sid' => $studentId,
            ':file' => $data['filename'], ':path' => $data['stored_path'],
        ]);

        $newId = (int) $pdo->lastInsertId();
        $pdo->commit();
        return $newId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
```

## Checklist

- [ ] `declare(strict_types=1);` no topo
- [ ] Classe `final`
- [ ] Métodos tipados (parâmetros + retorno)
- [ ] Professor: `WHERE tenant_id = :tid` em 100% das queries
- [ ] Aluno: validação de matrícula via JOIN com `enrollments`
- [ ] Prepared statements — zero concatenação
- [ ] `FETCH_ASSOC` nos retornos
- [ ] Soft delete (`archived = 1`) quando há FKs dependentes
- [ ] Transação MySQL em operações com múltiplos inserts
- [ ] Sem `Throwable::getMessage()` exposto

## Exemplo

**Input:** "Preciso do Model para a tabela `groups`"

**Output:** `src/models/Group.php` com `listForTenant`, `findById`, `create`, `update`, `delete` + métodos `addMember(int $tenantId, int $groupId, int $studentId)` e `removeMember(...)`, todos respeitando `tenant_id`.
