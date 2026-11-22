-- ============================================================================
-- Diagnóstico de órfãos "enxertados" (SÓ LEITURA — não apaga nada)
--
-- O script limpa-orfaos-arvore.sql só pega filho cujo pai sumiu. Não pega o
-- caso que causou o bug: o pai foi apagado, o MariaDB reaproveitou o id, e o
-- filho antigo agora "pertence" ao registro novo (CUs 267/268 nas CCs 72/73
-- do curso 19). Sinal do enxerto: o filho foi criado ANTES do pai atual — o
-- sistema não move CU/atividade/lição entre pais, então isso não acontece
-- legitimamente.
--
-- Também lista XP "enxertado": evento criado antes da atividade/avaliação/
-- lição/CU de mesmo id. Ele bloqueia (UK aluno+fonte) o XP do aluno no item
-- novo.
--
-- Como rodar: banco do LMS → aba SQL → cole a consulta inteira → Executar.
-- Mande o resultado; os enxertos são apagados pela tela (que agora apaga a
-- árvore inteira) ou por um script dirigido aos ids listados.
-- ============================================================================

SELECT 'cc enxertada em curso' AS tipo, x.id, x.name AS nome, x.created_at AS criado, p.id AS pai, p.created_at AS pai_criado
  FROM core_competencies x JOIN courses p ON p.id = x.course_id
 WHERE x.created_at < p.created_at
UNION ALL
SELECT 'cu enxertada em cc', x.id, x.name, x.created_at, p.id, p.created_at
  FROM competence_units x JOIN core_competencies p ON p.id = x.core_competency_id
 WHERE x.created_at < p.created_at
UNION ALL
SELECT 'capa enxertada em cu', x.id, LEFT(x.html, 60), x.created_at, p.id, p.created_at
  FROM contents x JOIN competence_units p ON p.id = x.competence_unit_id
 WHERE x.created_at < p.created_at
UNION ALL
SELECT 'atividade enxertada em cu', x.id, x.title, x.created_at, p.id, p.created_at
  FROM activities x JOIN competence_units p ON p.id = x.competence_unit_id
 WHERE x.created_at < p.created_at
UNION ALL
SELECT 'avaliacao enxertada em cu', x.id, x.title, x.created_at, p.id, p.created_at
  FROM evaluations x JOIN competence_units p ON p.id = x.competence_unit_id
 WHERE x.created_at < p.created_at
UNION ALL
SELECT 'licao enxertada em cu', x.id, x.title, x.created_at, p.id, p.created_at
  FROM lessons x JOIN competence_units p ON p.id = x.competence_unit_id
 WHERE x.created_at < p.created_at
UNION ALL
SELECT 'entrega enxertada em atividade', x.id, CONCAT('aluno ', x.student_user_id), x.created_at, p.id, p.created_at
  FROM activity_submissions x JOIN activities p ON p.id = x.activity_id
 WHERE x.created_at < p.created_at
UNION ALL
SELECT CONCAT('xp enxertado (', x.source_type, ')'), x.id, CONCAT('aluno ', x.student_user_id), x.created_at, x.source_id, s.criado
  FROM xp_events x
  JOIN (SELECT 'activity' AS t, id, created_at AS criado FROM activities
        UNION ALL SELECT 'evaluation', id, created_at FROM evaluations
        UNION ALL SELECT 'lesson', id, created_at FROM lessons
        UNION ALL SELECT 'cu_manual', id, created_at FROM competence_units) s
    ON s.t = x.source_type AND s.id = x.source_id
 WHERE x.created_at < s.criado
ORDER BY tipo, pai;
