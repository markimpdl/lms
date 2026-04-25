<?php
declare(strict_types=1);

/**
 * Cliente de geo-IP via ip-api.com (E16-04).
 *
 * Free tier: 45 req/min. Sem retry no MVP — se rate-limit, retorna NULL
 * silenciosamente. Timeout curto (3s connect + 3s total) pra não travar
 * o login. Fallback NULL em qualquer cenário de erro.
 *
 * Spec: `doc/15-roadmap-pos-mvp.md` F10. Sem chave (free tier não exige).
 */
final class GeoIPClient
{
    private const BASE_URL = 'http://ip-api.com/json/';

    /**
     * Resolve IP em "Cidade, País". Retorna null em qualquer falha (rate
     * limit, timeout, DNS, JSON inválido, IP local/privado, etc.).
     */
    public static function lookup(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        // IPs privados/locais — ip-api retorna fail. Pular pra economizar quota.
        if (filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false) {
            return null;
        }

        $url = self::BASE_URL . rawurlencode($ip) . '?fields=status,country,city';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return null;
        }

        try {
            $data = json_decode((string) $response, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
            return null;
        }

        $city    = trim((string) ($data['city']    ?? ''));
        $country = trim((string) ($data['country'] ?? ''));
        if ($city !== '' && $country !== '') {
            return $city . ', ' . $country;
        }
        if ($country !== '') {
            return $country;
        }
        return null;
    }
}
