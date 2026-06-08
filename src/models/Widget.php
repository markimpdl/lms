<?php
declare(strict_types=1);

/**
 * Widget interativo (E35 / F26 — ADR-037).
 *
 * Mini-app (HTML+CSS+JS+imgs num zip) cadastrada pelo professor, inserida no
 * conteúdo da CU via placeholder [[widget:ID]]. Pertence ao tenant de quem
 * cadastrou. Num curso compartilhado, o picker oferece os widgets de todos os
 * professores com acesso ao curso (`listAvailableForCourse`). Edição/remoção da
 * definição são exclusivas do dono (por tenant). Os arquivos vivem fora do
 * document root (storage/widgets/...) — ver WidgetStorage.
 */
final class Widget
{
    public const RENDER_MODES = ['inline', 'window'];

    /**
     * Cria a definição do widget. Retorna o id novo.
     */
    public static function create(
        int $tenantId,
        int $createdBy,
        string $name,
        string $slug,
        string $renderMode,
        string $entryFile,
        string $storagePath,
        ?string $icon,
        ?int $widthHint,
        ?int $heightHint
    ): int {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO widgets
                 (tenant_id, created_by, name, slug, render_mode, entry_file, storage_path, icon, width_hint, height_hint)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $tenantId, $createdBy, $name, $slug,
            in_array($renderMode, self::RENDER_MODES, true) ? $renderMode : 'inline',
            $entryFile !== '' ? $entryFile : 'index.html',
            $storagePath, $icon, $widthHint, $heightHint,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** Widget por id, sem filtro de tenant (uso interno: render/serving). */
    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM widgets WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Widget por id restrito ao tenant (uso: gestão pelo dono). */
    public static function findForTenant(int $id, int $tenantId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM widgets WHERE id = ? AND tenant_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Biblioteca do tenant (gestão). */
    public static function listForTenant(int $tenantId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM widgets WHERE tenant_id = ? ORDER BY name ASC, id ASC'
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll();
    }

    /**
     * Widgets disponíveis pra inserir no conteúdo de um curso (E35-05). União
     * dos widgets de TODOS os professores com acesso ao curso: o tenant do dono
     * + os tenants dos colaboradores (ADR-037, "biblioteca compartilhada no
     * curso"). Só ativos.
     */
    public static function listAvailableForCourse(int $courseId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM widgets
              WHERE active = 1
                AND tenant_id IN (
                    SELECT tenant_id FROM courses WHERE id = ?
                    UNION
                    SELECT t.id FROM course_collaborators cc
                      JOIN tenants t ON t.owner_user_id = cc.user_id
                     WHERE cc.course_id = ?
                )
              ORDER BY name ASC, id ASC'
        );
        $stmt->execute([$courseId, $courseId]);
        return $stmt->fetchAll();
    }

    /** Atualiza metadados (sem novo upload). Retorna true se algo mudou/existe. */
    public static function updateMeta(
        int $id,
        int $tenantId,
        string $name,
        string $renderMode,
        ?string $icon,
        ?int $widthHint,
        ?int $heightHint
    ): bool {
        $stmt = Database::pdo()->prepare(
            'UPDATE widgets
                SET name = ?, render_mode = ?, icon = ?, width_hint = ?, height_hint = ?
              WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute([
            $name,
            in_array($renderMode, self::RENDER_MODES, true) ? $renderMode : 'inline',
            $icon, $widthHint, $heightHint, $id, $tenantId,
        ]);
        return $stmt->rowCount() >= 0;
    }

    /** Atualiza os campos de storage após re-upload. */
    public static function updateStorage(int $id, int $tenantId, string $slug, string $storagePath, string $entryFile): void
    {
        Database::pdo()->prepare(
            'UPDATE widgets SET slug = ?, storage_path = ?, entry_file = ? WHERE id = ? AND tenant_id = ?'
        )->execute([$slug, $storagePath, $entryFile !== '' ? $entryFile : 'index.html', $id, $tenantId]);
    }

    /** Remove a definição (só o dono). Retorna o registro removido ou null. */
    public static function delete(int $id, int $tenantId): ?array
    {
        $w = self::findForTenant($id, $tenantId);
        if ($w === null) {
            return null;
        }
        Database::pdo()->prepare('DELETE FROM widgets WHERE id = ? AND tenant_id = ?')
            ->execute([$id, $tenantId]);
        return $w;
    }

    /**
     * Pode o usuário atual carregar este widget no serving? (E35-04)
     *
     * - Professor: é da própria biblioteca (mesmo tenant) OU o widget pertence a
     *   alguém que compartilha um curso com ele (em qualquer direção) — ou seja,
     *   está disponível em algum curso que o professor acessa.
     * - Aluno: está matriculado em algum curso cujo conteúdo referencia o
     *   [[widget:ID]] (gate por matrícula + uso real).
     */
    public static function userCanAccess(array $widget, array $user): bool
    {
        if ((int) ($widget['active'] ?? 0) !== 1) {
            return false;
        }
        $pdo       = Database::pdo();
        $widgetId  = (int) $widget['id'];
        $wTenant   = (int) $widget['tenant_id'];
        $role      = (string) ($user['role'] ?? '');
        $userId    = (int) ($user['id'] ?? 0);

        if ($role === 'teacher') {
            $myTenant = current_tenant_id();
            if ($myTenant !== null && $myTenant === $wTenant) {
                return true; // própria biblioteca
            }
            // Widget do dono de um curso em que sou colaborador.
            $st = $pdo->prepare(
                'SELECT 1 FROM course_collaborators cc
                   JOIN courses c ON c.id = cc.course_id
                  WHERE cc.user_id = ? AND c.tenant_id = ? LIMIT 1'
            );
            $st->execute([$userId, $wTenant]);
            if ($st->fetchColumn() !== false) {
                return true;
            }
            // Widget de um colaborador de um curso meu.
            if ($myTenant !== null) {
                $st = $pdo->prepare(
                    'SELECT 1 FROM courses c
                       JOIN course_collaborators cc ON cc.course_id = c.id
                       JOIN tenants t ON t.owner_user_id = cc.user_id
                      WHERE c.tenant_id = ? AND t.id = ? LIMIT 1'
                );
                $st->execute([$myTenant, $wTenant]);
                if ($st->fetchColumn() !== false) {
                    return true;
                }
            }
            return false;
        }

        if ($role === 'student') {
            $st = $pdo->prepare(
                'SELECT 1
                   FROM enrollments e
                   JOIN courses c            ON c.id  = e.course_id
                   JOIN core_competencies cc ON cc.course_id = c.id
                   JOIN competence_units cu  ON cu.core_competency_id = cc.id
                   JOIN contents ct          ON ct.competence_unit_id = cu.id
                  WHERE e.student_user_id = ?
                    AND ct.published = 1
                    AND ct.html LIKE ?
                  LIMIT 1'
            );
            $st->execute([$userId, '%[[widget:' . $widgetId . ']]%']);
            return $st->fetchColumn() !== false;
        }

        return false;
    }

    /**
     * Gera um slug estável a partir do nome + sufixo aleatório (evita colisão
     * na pasta de storage e no UK tenant+slug).
     */
    public static function makeSlug(string $name): string
    {
        $base = strtolower(trim($name));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'widget';
        }
        $base = substr($base, 0, 60);
        return $base . '-' . bin2hex(random_bytes(4));
    }
}
