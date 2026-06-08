<?php
declare(strict_types=1);

/**
 * Auditoria de conteúdo por curso (E33 / F24 — ADR-035).
 *
 * Registra SÓ ações de estrutura/conteúdo (create/update/delete de Core
 * Competence, Competence Unit, conteúdo, atividade, avaliação). Com autoria
 * compartilhada (F23/ADR-033), é o rastro de "quem mexeu no quê". É um vínculo
 * por curso (cross-tenant por design, como `course_collaborators`): NÃO tem
 * `tenant_id` próprio; o acesso de leitura é gateado por acesso ao curso (dono
 * + colaboradores). Append-only; sem backfill.
 *
 * O `entity_label` é um snapshot do nome no momento da ação, pra que entidades
 * já excluídas continuem legíveis ("Excluiu a CU 'Variáveis'").
 */
final class CourseAuditLog
{
    private const PER_PAGE = 30;

    /** Ações registradas. */
    public const ACTIONS = ['create', 'update', 'delete'];

    /** Tipos de entidade registrados (escopo "só conteúdo" — ADR-035). */
    public const ENTITY_TYPES = [
        'core_competency',
        'competence_unit',
        'content',
        'activity',
        'evaluation',
    ];

    /**
     * Grava um registro de auditoria. Append-only e best-effort: nunca deve
     * quebrar a ação principal — o caller (helper `course_audit`) envolve em
     * try/catch. Valida os enums pra não inserir lixo silenciosamente.
     */
    public static function record(
        int $courseId,
        int $actorUserId,
        string $action,
        string $entityType,
        ?int $entityId,
        string $entityLabel
    ): void {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException("ação de auditoria inválida: {$action}");
        }
        if (!in_array($entityType, self::ENTITY_TYPES, true)) {
            throw new InvalidArgumentException("tipo de entidade inválido: {$entityType}");
        }

        // Trunca o rótulo ao tamanho da coluna (snapshot legível, sem estourar).
        $label = mb_substr(trim($entityLabel) !== '' ? $entityLabel : '—', 0, 255);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO course_audit_log
                 (course_id, actor_user_id, action, entity_type, entity_id, entity_label)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bindValue(1, $courseId,     PDO::PARAM_INT);
        $stmt->bindValue(2, $actorUserId,  PDO::PARAM_INT);
        $stmt->bindValue(3, $action,       PDO::PARAM_STR);
        $stmt->bindValue(4, $entityType,   PDO::PARAM_STR);
        $stmt->bindValue(5, $entityId,     $entityId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(6, $label,        PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Lista os registros de auditoria de um curso, mais recentes primeiro,
     * paginado. O acesso ao curso deve ser validado ANTES pela página (este
     * model não filtra por tenant — é cross-tenant por design).
     *
     * @return array{
     *   rows: list<array<string,mixed>>,
     *   total: int,
     *   page: int,
     *   total_pages: int,
     *   per_page: int,
     * }
     */
    public static function listForCourse(int $courseId, int $page): array
    {
        $pdo = Database::pdo();

        $stmtTotal = $pdo->prepare(
            'SELECT COUNT(*) FROM course_audit_log WHERE course_id = ?'
        );
        $stmtTotal->execute([$courseId]);
        $total = (int) $stmtTotal->fetchColumn();

        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page       = max(1, min($page, $totalPages));
        $offset     = ($page - 1) * self::PER_PAGE;

        $sql = <<<SQL
            SELECT cal.id, cal.action, cal.entity_type, cal.entity_id,
                   cal.entity_label, cal.created_at,
                   cal.actor_user_id, u.name AS actor_name
              FROM course_audit_log cal
              JOIN users u ON u.id = cal.actor_user_id
             WHERE cal.course_id = ?
             ORDER BY cal.created_at DESC, cal.id DESC
             LIMIT ? OFFSET ?
            SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $courseId,      PDO::PARAM_INT);
        $stmt->bindValue(2, self::PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset,        PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows'        => $stmt->fetchAll(),
            'total'       => $total,
            'page'        => $page,
            'total_pages' => $totalPages,
            'per_page'    => self::PER_PAGE,
        ];
    }
}
