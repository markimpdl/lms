<?php
declare(strict_types=1);

/**
 * Re-hospeda na CU atual as imagens que apontam pra anexo de OUTRA CU.
 *
 * **O bug que isto resolve.** O `<img>` de conteúdo/lição aponta pra
 * `/teacher/cu/{cu}/attachment/{aid}/view`, mas o segmento `{cu}` da URL é
 * decorativo: quem autoriza é o anexo. Pro aluno, `findForStudent` exige
 * matrícula no curso **dono do anexo**. Então HTML colado de outra unidade
 * (o picker de imagem só oferece anexos da própria CU — a URL de fora sempre
 * vem de copiar/colar) carrega pro professor, que autoriza por tenant, e
 * quebra pro aluno que não estuda o curso de origem. Sintoma clássico: as
 * imagens aparecem pro professor e pro aluno antigo, e só as coladas somem
 * pro aluno novo.
 *
 * **A correção é no dado, não na autorização.** Afrouxar a rota do aluno pra
 * aceitar anexo de curso em que ele não está matriculado abriria leitura
 * cross-curso. Em vez disso, no save do professor cada imagem estranha é
 * copiada pra CU que a exibe e o `src` reescrito pro anexo novo. A partir daí
 * a unidade é autossuficiente: quem vê a lição vê as imagens.
 *
 * Só copia anexo do MESMO tenant (`ContentAttachment::findForTenant`). URL de
 * anexo de outro tenant, ou de id que não existe mais, fica intocada — não há
 * nada que o professor possa legitimamente re-hospedar ali, e reescrever pra
 * um id inventado só trocaria 404 por 404.
 */
final class ContentImageRehost
{
    /**
     * URL de anexo servida por rota autenticada, nas quatro formas que
     * aparecem no HTML salvo: `/teacher/...` do editor ou `/student/...`
     * (colado de uma tela de aluno), com `/view` (o `<img>`, inline) ou sem
     * (o `<a>` de download de PDF/zip). Os dois sofrem do mesmo problema, e o
     * grupo 2 preserva qual era — reescrever download como `/view` trocaria
     * baixar por abrir no navegador.
     *
     * Captura o id do anexo; o `cu` da URL é ignorado de propósito, ele não é
     * fonte de verdade.
     *
     * O lookahead exige que a URL termine ali (aspas, espaço, fim). Sem ele,
     * `/attachment/10/delete` casaria como `/attachment/10` e a reescrita
     * comeria o `/delete`.
     */
    private const string URL_RE =
        '#/(?:teacher|student)/cu/\d+/attachment/(\d+)(/view)?(?=["\'\s?\#&<>]|$)#';

    /**
     * Classifica as URLs de anexo que aparecem no HTML, SEM tocar em nada.
     *
     * Separada do `apply()` porque o diagnóstico é útil por si: o script de
     * reparo retroativo lista o que vai mexer antes de mexer, e as duas vias
     * precisam classificar com o mesmo critério.
     *
     * @return array{own:list<int>, foreign:array<int,array<string,mixed>>, unknown:list<int>}
     *         own:     anexos que já são desta CU (nada a fazer)
     *         foreign: aid => registro do anexo, de outra CU do mesmo tenant
     *         unknown: aids que não existem ou são de outro tenant
     */
    public static function scan(string $html, int $cuId, int $tenantId): array
    {
        $out = ['own' => [], 'foreign' => [], 'unknown' => []];
        if ($html === '' || !preg_match_all(self::URL_RE, $html, $m)) {
            return $out;
        }

        foreach (array_unique(array_map('intval', $m[1])) as $aid) {
            $att = ContentAttachment::findForTenant($aid, $tenantId);
            if ($att === null) {
                $out['unknown'][] = $aid;
            } elseif ((int) $att['competence_unit_id'] === $cuId) {
                $out['own'][] = $aid;
            } else {
                $out['foreign'][$aid] = $att;
            }
        }
        return $out;
    }

    /**
     * Reescreve `$html` pra que toda imagem aponte pra anexo da própria
     * `$cuId`, copiando o que vier de fora.
     *
     * @return array{html:string, rehosted:int, skipped:int, blocked:int}
     *         rehosted: anexos trazidos pra esta CU (URLs reescritas)
     *         blocked:  não couberam — a CU está no teto de anexos
     *         skipped:  URLs deixadas como estavam (anexo apagado, de outro
     *                   tenant, ou arquivo físico ausente)
     */
    public static function apply(string $html, int $cuId, int $tenantId): array
    {
        $scan    = self::scan($html, $cuId, $tenantId);
        $skipped = count($scan['unknown']);
        $blocked = 0;

        /** @var array<int,int> $map aid antigo => aid que a URL passa a citar */
        $map = [];

        // Anexo que já é desta CU só tem o segmento `cu` da URL normalizado
        // (pode ter vindo errado da colagem) — nada é copiado.
        foreach ($scan['own'] as $aid) {
            $map[$aid] = $aid;
        }

        $rehosted = 0;
        foreach ($scan['foreign'] as $aid => $att) {
            $newAid = AttachmentStorage::copyInto($att, $cuId, $tenantId);

            // Teto de anexos da CU tem aviso próprio: mandar o professor
            // "reenviar pelo editor" seria mandá-lo bater na mesma trave, que
            // o upload manual também checa.
            if ($newAid === 'limit') {
                $blocked++;
                continue;
            }
            if (!is_int($newAid)) {
                $skipped++;
                continue;
            }
            $map[$aid] = $newAid;
            $rehosted++;
        }

        if ($map === []) {
            return ['html' => $html, 'rehosted' => 0, 'skipped' => $skipped, 'blocked' => $blocked];
        }

        // Uma passada só, com o mapa fechado: substituição sequencial poderia
        // reescrever uma URL recém-criada se o id novo coincidisse com um id
        // antigo ainda na fila.
        $out = preg_replace_callback(
            self::URL_RE,
            static function (array $hit) use ($map, $cuId): string {
                $aid = (int) $hit[1];
                if (!isset($map[$aid])) {
                    return $hit[0];
                }
                return self::url($cuId, $map[$aid], $hit[2] ?? '');
            },
            $html
        );

        return [
            'html'     => $out ?? $html,
            'rehosted' => $rehosted,
            'skipped'  => $skipped,
            'blocked'  => $blocked,
        ];
    }

    /**
     * Feedback do resultado pro professor. Mora aqui pra que os tres saves
     * (licao nova, licao editada, conteudo da CU) digam a mesma coisa.
     *
     * @param array{html:string, rehosted:int, skipped:int, blocked:int} $result
     */
    public static function flashResult(array $result): void
    {
        if ($result['rehosted'] > 0) {
            flash('info', __t('content.images.rehosted', ['count' => (string) $result['rehosted']]));
        }
        if ($result['blocked'] > 0) {
            flash('warning', __t('content.images.rehost_limit', [
                'count' => (string) $result['blocked'],
                'max'   => (string) AttachmentStorage::MAX_ATTACHMENTS_PER_CU,
            ]));
        }
        if ($result['skipped'] > 0) {
            flash('warning', __t('content.images.rehost_failed', ['count' => (string) $result['skipped']]));
        }
    }

    /** `$suffix` é `/view` (inline) ou vazio (download) — o que a URL já era. */
    private static function url(int $cuId, int $aid, string $suffix = '/view'): string
    {
        return '/teacher/cu/' . $cuId . '/attachment/' . $aid . $suffix;
    }
}
