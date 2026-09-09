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
     * URL de anexo servida por rota autenticada, nas duas formas que aparecem
     * no HTML salvo (`/teacher/...` do editor; `/student/...` se o professor
     * colou de uma tela de aluno). Captura o id do anexo — o `cu` da URL é
     * ignorado de propósito, ele não é fonte de verdade.
     */
    private const string URL_RE = '#/(?:teacher|student)/cu/\d+/attachment/(\d+)/view#';

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
     * @return array{html:string, rehosted:int, skipped:int}
     *         rehosted: anexos copiados pra esta CU (URLs reescritas)
     *         skipped:  URLs deixadas como estavam (outro tenant, id morto,
     *                   arquivo ausente ou teto de anexos da CU atingido)
     */
    public static function apply(string $html, int $cuId, int $tenantId): array
    {
        $scan    = self::scan($html, $cuId, $tenantId);
        $skipped = count($scan['unknown']);

        /** @var array<int,string> $map aid antigo => URL nova */
        $map = [];

        // Anexo que já é desta CU só tem o segmento `cu` da URL normalizado
        // (pode ter vindo errado da colagem) — nada é copiado.
        foreach ($scan['own'] as $aid) {
            $map[$aid] = self::url($cuId, $aid);
        }

        $rehosted = 0;
        foreach ($scan['foreign'] as $aid => $att) {
            $newAid = AttachmentStorage::copyInto($att, $cuId, $tenantId);
            if ($newAid === null) {
                $skipped++;
                continue;
            }
            $map[$aid] = self::url($cuId, $newAid);
            $rehosted++;
        }

        if ($map === []) {
            return ['html' => $html, 'rehosted' => 0, 'skipped' => $skipped];
        }

        // Uma passada só, com o mapa fechado: substituição sequencial poderia
        // reescrever uma URL recém-criada se o id novo coincidisse com um id
        // antigo ainda na fila.
        $out = preg_replace_callback(
            self::URL_RE,
            static fn (array $hit): string => $map[(int) $hit[1]] ?? $hit[0],
            $html
        );

        return [
            'html'     => $out ?? $html,
            'rehosted' => $rehosted,
            'skipped'  => $skipped,
        ];
    }

    /**
     * Feedback do resultado pro professor. Mora aqui pra que os tres saves
     * (licao nova, licao editada, conteudo da CU) digam a mesma coisa.
     *
     * @param array{html:string, rehosted:int, skipped:int} $result
     */
    public static function flashResult(array $result): void
    {
        if ($result['rehosted'] > 0) {
            flash('info', __t('content.images.rehosted', ['count' => (string) $result['rehosted']]));
        }
        if ($result['skipped'] > 0) {
            flash('warning', __t('content.images.rehost_failed', ['count' => (string) $result['skipped']]));
        }
    }

    private static function url(int $cuId, int $aid): string
    {
        return '/teacher/cu/' . $cuId . '/attachment/' . $aid . '/view';
    }
}
