<?php
declare(strict_types=1);

/**
 * Unidade em RASCUNHO: o professor escreveu o conteudo da CU e ainda nao
 * publicou. Pro aluno a unidade inteira fica fora do ar — nao lista
 * atividades/avaliacao/anexos, os handlers de conteudo barram a URL direta, e
 * a progressao do curso **pula** a unidade em vez de parar nela.
 *
 * **Por que a definicao mora num lugar so.** O predicado tem tres condicoes e
 * eh consultado de duas formas diferentes (uma CU por vez na tela e nos gates;
 * o conjunto inteiro do curso no calculo de progresso). Escrito duas vezes ele
 * diverge — foi assim que a primeira versao disto ficou com `=== 1` num lado e
 * `!== 2` no outro, abrindo bypass. `sqlIsDraft()` eh a fonte unica: as duas
 * consultas daqui a usam, e ninguem mais monta o predicado a mao.
 *
 * As duas condicoes, AMBAS necessarias:
 *
 *  1. Existe linha em `contents` com `published = 0`.
 *  2. **`html` nao vazio.** Sem esta segunda condicao o gate fecharia unidade
 *     viva: `Content::ensureForCu()` cria linha com `html = ''` e
 *     `published = 0` sempre que o professor sobe um ANEXO. Uma CU de
 *     atividades + PDF, sem texto nenhum, ganharia a linha e sumiria pro aluno
 *     junto com os proprios anexos que a criaram. Rascunho eh conteudo escrito
 *     e retido, nao linha-fantasma de upload.
 *
 * **Vale pros dois formatos de curso.** A primeira versao disto exigia
 * `structure_version <> 2`, com o argumento de que em V2 a capa eh opcional e
 * cada licao tem a propria flag. O PO corrigiu a premissa: conteudo em
 * rascunho significa unidade que ele ainda nao quer no ar, e o formato do
 * curso nao muda isso. A CU sem capa nenhuma continua livre — quem nao tem
 * linha em `contents` nao satisfaz a condicao 1.
 *
 * Consequencia assumida: em V2, licao JA PUBLICADA de uma unidade cuja capa
 * esteja em rascunho fica inacessivel enquanto a capa nao for publicada. Eh o
 * comportamento pedido — a unidade inteira sai do ar.
 */
final class UnitDraftGate
{
    /**
     * Molde da expressao booleana SQL — `%s` eh o alias da tabela `contents`.
     * Use `sqlIsDraft()`, nunca isto direto.
     */
    private const string SQL_TEMPLATE =
        "(COALESCE(%1\$s.published, 1) = 0"
        . " AND %2\$s <> '')";

    /**
     * "O html tem conteudo de verdade?", em SQL, com a MESMA nocao de vazio
     * que o PHP `trim()`.
     *
     * `TRIM()` do MySQL corta SO espaco. `trim()` do PHP corta tambem
     * \t \n \r \0 \x0B — e a diferenca importa: um `contents.html` valendo
     * apenas "\n" faz o professor ver "sem conteudo" (badge e botao "Criar")
     * enquanto o SQL o classificaria como rascunho e sumiria a unidade pra
     * todos os alunos, sem causa visivel em lugar nenhum. Exatamente a
     * divergencia que esta classe existe pra impedir.
     *
     * Remover os caracteres em todo o texto, e nao so nas pontas, da a mesma
     * resposta pra a unica pergunta feita aqui ("sobra alguma coisa?").
     * `CHAR(n)` em vez de escapes pra nao depender de NO_BACKSLASH_ESCAPES.
     */
    private static function sqlHtmlNotBlank(string $ct): string
    {
        $expr = "COALESCE({$ct}.html, '')";
        foreach ([9, 10, 13, 0, 11] as $code) {
            $expr = "REPLACE({$expr}, CHAR({$code}), '')";
        }
        return "TRIM({$expr})";
    }

    /**
     * Expressao booleana SQL — 1 quando a CU esta em rascunho.
     *
     * Espera no escopo da query um LEFT JOIN em `contents` com o alias passado
     * em `$contentsAlias` — subquery aninhada precisa de alias proprio pra nao
     * colidir com o de fora. Nao depende mais de `courses`: o predicado valia
     * so pra V1 e passou a valer pros dois formatos, entao `structure_version`
     * saiu da conta.
     *
     * Os COALESCE existem por causa do LEFT JOIN: sem eles, CU sem linha em
     * `contents` produz NULL, e `NOT NULL` eh NULL — o WHERE descartaria
     * justamente as unidades que devem passar.
     */
    public static function sqlIsDraft(string $contentsAlias = 'ct'): string
    {
        return sprintf(self::SQL_TEMPLATE, $contentsAlias, self::sqlHtmlNotBlank($contentsAlias));
    }

    /** @var array<int, bool> cache por CU, por request — o predicado nao muda no meio de um */
    private static array $cache = [];

    /** @var array<int, array<int, true>> cache por curso — ver `draftCuIdsInCourse()` */
    private static array $byCourse = [];

    /**
     * Esta CU esta em rascunho?
     *
     * CU inexistente devolve false: quem chama ja tratou o 404 antes, e
     * inventar "rascunho" aqui esconderia um erro de rota atras de um
     * redirect silencioso.
     */
    public static function isDraft(int $cuId): bool
    {
        if (isset(self::$cache[$cuId])) {
            return self::$cache[$cuId];
        }

        $stmt = Database::pdo()->prepare(
            'SELECT ' . self::sqlIsDraft() . ' AS is_draft
               FROM competence_units cu
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
               JOIN courses c            ON c.id  = cc.course_id
               LEFT JOIN contents ct     ON ct.competence_unit_id = cu.id
              WHERE cu.id = ?
              LIMIT 1'
        );
        $stmt->execute([$cuId]);
        $raw = $stmt->fetchColumn();

        return self::$cache[$cuId] = ($raw !== false && (int) $raw === 1);
    }

    /**
     * Ids das CUs em rascunho de um curso, numa query so.
     *
     * Serve quem percorre o curso inteiro (progressao e % de conclusao) e nao
     * pode pagar uma consulta por unidade. Alimenta o cache de `isDraft()` de
     * quebra — a mesma request costuma perguntar pelas duas vias.
     *
     * Memoizado por curso: a pagina do curso chama isto uma vez pros contadores
     * e `course_progression_state()` chama de novo pro mapa de status, no mesmo
     * request. Sem o memo eram duas vezes a mesma query de 4 tabelas.
     *
     * @return array<int, true> mapa cu_id => true, pronto pra `isset()`
     */
    public static function draftCuIdsInCourse(int $courseId): array
    {
        if (isset(self::$byCourse[$courseId])) {
            return self::$byCourse[$courseId];
        }

        $stmt = Database::pdo()->prepare(
            'SELECT cu.id AS cu_id, ' . self::sqlIsDraft() . ' AS is_draft
               FROM competence_units cu
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
               JOIN courses c            ON c.id  = cc.course_id
               LEFT JOIN contents ct     ON ct.competence_unit_id = cu.id
              WHERE cc.course_id = ?'
        );
        $stmt->execute([$courseId]);

        $draft = [];
        foreach ($stmt->fetchAll() as $row) {
            $cuId = (int) $row['cu_id'];
            $isDraft = (int) $row['is_draft'] === 1;
            self::$cache[$cuId] = $isDraft;
            if ($isDraft) {
                $draft[$cuId] = true;
            }
        }
        return self::$byCourse[$courseId] = $draft;
    }
}
