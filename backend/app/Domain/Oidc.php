<?php

namespace App\Domain;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class Oidc
{
    public static function http(string $url)
    {
        $host = parse_url($url, PHP_URL_HOST);
        abort_unless(parse_url($url, PHP_URL_SCHEME) === 'https' && in_array($host, config('oidc.allowed_hosts'), true), 503, 'Identity provider host not allowlisted');
        $ip = gethostbyname($host);
        abort_unless(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE), 503, 'Invalid identity provider address');

        return Http::timeout(15)->connectTimeout(5)->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$ip]]]);
    }

    public static function metadata(): array
    {
        $issuer = rtrim(config('oidc.issuer') ?? '', '/');
        abort_unless($issuer && config('oidc.client_id') && config('oidc.redirect_uri'), 503, 'OIDC not configured');

        return Cache::remember('oidc-metadata-'.hash('sha256', $issuer), 300, function () use ($issuer) {
            $res = self::http($issuer.'/.well-known/openid-configuration')->get($issuer.'/.well-known/openid-configuration')->throw()->json();
            abort_unless(is_array($res) && ($res['issuer'] ?? '') === $issuer, 503, 'Issuer metadata mismatch');
            foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
                abort_unless(isset($res[$key]), 503, 'Missing OIDC metadata');
                self::http($res[$key]);
            }

            return $res;
        });
    }

    public static function verify(string $jwt, array $jwks, string $nonce): object
    {
        $header = json_decode(base64_decode(strtr(explode('.', $jwt)[0] ?? '', '-_', '+/')), true);
        abort_unless(($header['alg'] ?? null) === 'RS256', 401, 'Unsupported ID token algorithm');
        try {
            $claims = JWT::decode($jwt, JWK::parseKeySet($jwks, 'RS256'));
        } catch (\Throwable $e) {
            abort(401, 'Invalid ID token');
        }
        $aud = (array) ($claims->aud ?? []);
        abort_unless(in_array(config('oidc.client_id'), $aud, true), 401, 'Audience mismatch');
        if (count($aud) > 1) {
            abort_unless(($claims->azp ?? null) === config('oidc.client_id'), 401, 'Authorized party mismatch');
        }
        abort_unless(($claims->iss ?? null) === rtrim(config('oidc.issuer'), '/'), 401, 'Issuer mismatch');
        abort_unless(isset($claims->nonce) && hash_equals($nonce, $claims->nonce), 401, 'Nonce mismatch');
        abort_unless(! empty($claims->sub) && isset($claims->exp) && $claims->exp > time() && isset($claims->iat) && $claims->iat <= time() + 60, 401, 'Invalid token timing/subject');

        return $claims;
    }
}
