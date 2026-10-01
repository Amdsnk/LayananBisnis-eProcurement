<?php

namespace App\Domain;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class ErpConnector
{
    public function send(object $config, array $payload, string $key): array
    {
        if ($config->protocol === 'sandbox') {
            if (app()->environment('production')) {
                throw new \RuntimeException('Sandbox connector forbidden');
            }

            return ['sandbox' => true, 'external_id' => 'sandbox-'.$key];
        }
        $url = $config->endpoint;
        $host = parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! in_array($host, config('procurement.erp_allowed_hosts'), true)) {
            throw new \RuntimeException('ERP host not allowlisted');
        }
        // Resolve once and pin public IP with CURLOPT_RESOLVE to prevent DNS rebinding.
        $ip = gethostbyname($host);
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new \RuntimeException('ERP destination must be public allowlisted address');
        }
        $secret = $config->secret ? Crypt::decryptString($config->secret) : null;
        if ($config->protocol === 'rest') {
            $res = Http::timeout(20)->connectTimeout(5)->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$ip]]])->withToken($secret ?? '')->withHeaders(['Idempotency-Key' => $key])->post($url, $payload);
            $res->throw();
            $json = $res->json();
            if (! is_array($json) || ! isset($json['external_id'])) {
                throw new \RuntimeException('ERP response missing external_id');
            }

            return $json;
        }
        if ($config->protocol === 'soap') {
            throw new \RuntimeException('SOAP contract must be configured and verified; generic production posting disabled');
        }
        throw new \RuntimeException('Unsupported ERP protocol');
    }
}
