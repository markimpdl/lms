-- ============================================================================
-- Limpeza de órfãos da árvore de conteúdo (rodar uma vez no phpMyAdmin)
--
-- Contexto: o banco de produção não tem as FKs de courses → core_competencies
-- → competence_units → contents/activities/evaluations. Todo delete antigo de
-- curso, CC ou CU deixou os filhos para trás. Em 2026-11-22 a contagem era:
-- 3 CCs sem curso, 23 CUs sem CC, 25 capas e 88 atividades sem CU. O código
-- novo (ContentTreeDeleter) para de criar órfãos; este script apaga os antigos.
--
-- Como rodar:
--   1. Selecione o banco do LMS na barra da esquerda e abra a aba SQL.
--   2. Cole o script INTEIRO como está (termina em ROLLBACK = ensaio).
--      O phpMyAdmin mostra "N linhas afetadas" em cada DELETE. Nada é gravado.
--   3. Confira os números. Se fizerem sentido, troque o ROLLBACK final por
--      COMMIT e rode de novo.
--
-- A ordem é de cima para baixo: cada passo apaga o que ficou sem pai, inclusive
-- o que perdeu o pai no passo anterior. Rodar duas vezes não faz mal.
--
-- NÃO mexe em xp_events: o XP já ganho pelos alunos continua contando no
-- ranking, como acontecia antes.
--
-- Arquivos físicos (entregas, anexos) dos órfãos continuam em storage/uploads;
-- só as linhas do banco saem.
-- ============================================================================

START TRANSACTION;

-- 1. CC sem curso
DELETE x FROM core_competencies x
  LEFT JOIN courses p ON p.id = x.course_id
 WHERE p.id IS NULL;

-- 2. CU sem CC
DELETE x FROM competence_units x
  LEFT JOIN core_competencies p ON p.id = x.core_competency_id
 WHERE p.id IS NULL;

-- 3. Tudo que pendura direto na CU
DELETE x FROM contents x
  LEFT JOIN competence_units p ON p.id = x.competence_unit_id WHERE p.id IS NULL;
DELETE x FROM activities x
  LEFT JOIN competence_units p ON p.id = x.competence_unit_id WHERE p.id IS NULL;
DELETE x FROM evaluations x
  LEFT JOIN competence_units p ON p.id = x.competence_unit_id WHERE p.id IS NULL;
DELETE x FROM lessons x
  LEFT JOIN competence_units p ON p.id = x.competence_unit_id WHERE p.id IS NULL;
DELETE x FROM learning_outcomes x
  LEFT JOIN competence_units p ON p.id = x.cu_id WHERE p.id IS NULL;
DELETE x FROM cu_manual_completions x
  LEFT JOIN competence_units p ON p.id = x.cu_id WHERE p.id IS NULL;
DELETE x FROM student_cu_unlocks x
  LEFT JOIN competence_units p ON p.id = x.cu_id WHERE p.id IS NULL;

-- 4. Anexos da capa
DELETE x FROM content_attachments x
  LEFT JOIN contents p ON p.id = x.content_id WHERE p.id IS NULL;

-- 5. Entregas e conclusões
DELETE x FROM activity_submissions x
  LEFT JOIN activities p ON p.id = x.activity_id WHERE p.id IS NULL;
DELETE x FROM evaluation_submissions x
  LEFT JOIN evaluations p ON p.id = x.evaluation_id WHERE p.id IS NULL;
DELETE x FROM lesson_completions x
  LEFT JOIN lessons p ON p.id = x.lesson_id WHERE p.id IS NULL;

-- 6. Arquivos de entrega e notas por LO
DELETE x FROM activity_submission_files x
  LEFT JOIN activity_submissions p ON p.id = x.submission_id WHERE p.id IS NULL;
DELETE x FROM evaluation_submission_files x
  LEFT JOIN evaluation_submissions p ON p.id = x.submission_id WHERE p.id IS NULL;
DELETE x FROM evaluation_submission_lo_grades x
  LEFT JOIN evaluation_submissions s ON s.id = x.submission_id
  LEFT JOIN learning_outcomes lo     ON lo.id = x.lo_id
 WHERE s.id IS NULL OR lo.id IS NULL;

-- 7. Quiz polimórfico, perguntas e alternativas
DELETE q FROM quizzes q
  LEFT JOIN activities  a ON q.owner_type = 'activity'   AND a.id = q.owner_id
  LEFT JOIN evaluations e ON q.owner_type = 'evaluation' AND e.id = q.owner_id
 WHERE a.id IS NULL AND e.id IS NULL;
DELETE x FROM quiz_questions x
  LEFT JOIN quizzes p ON p.id = x.quiz_id WHERE p.id IS NULL;
DELETE x FROM quiz_options x
  LEFT JOIN quiz_questions p ON p.id = x.question_id WHERE p.id IS NULL;

-- 8. Restos de curso apagado
DELETE x FROM enrollments x
  LEFT JOIN courses p ON p.id = x.course_id WHERE p.id IS NULL;
DELETE x FROM course_collaborators x
  LEFT JOIN courses p ON p.id = x.course_id WHERE p.id IS NULL;
DELETE x FROM course_audit_log x
  LEFT JOIN courses p ON p.id = x.course_id WHERE p.id IS NULL;

-- Ensaio: troque por COMMIT depois de conferir os números.
ROLLBACK;
