<?php

namespace App\Domain;

use Illuminate\Support\Facades\Http;

class SoapTransport extends \SoapClient
{
    public function __construct(string $wsdl, private string $endpoint, private ?string $token = null)
    {
        abort_unless(is_file($wsdl) && filesize($wsdl) < 1024 * 1024, 503, 'Verified local WSDL required');
        $xml = file_get_contents($wsdl);
        abort_if(stripos($xml, '<!DOCTYPE') !== false || stripos($xml, 'schemaLocation=') !== false, 503, 'External WSDL/schema imports forbidden');
        parent::__construct($wsdl, ['exceptions' => true, 'cache_wsdl' => WSDL_CACHE_NONE, 'trace' => false, 'location' => $endpoint, 'soap_version' => SOAP_1_1, 'connection_timeout' => 10, 'features' => SOAP_SINGLE_ELEMENT_ARRAYS]);
    }

    protected function resolveHost(string $host): string
    {
        return gethostbyname($host);
    }

    public function __doRequest(string $request, string $location, string $action, int $version, bool $oneWay = false): ?string
    {
        $host = parse_url($this->endpoint, PHP_URL_HOST);
        abort_unless(parse_url($this->endpoint, PHP_URL_SCHEME) === 'https' && in_array($host, config('procurement.erp_allowed_hosts'), true), 503, 'SOAP destination not allowlisted');
        $ip = $this->resolveHost($host);
        abort_unless(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE), 503, 'Invalid SOAP destination');
        $res = Http::timeout(20)->connectTimeout(5)->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$ip]]])->withHeaders(['Content-Type' => 'text/xml; charset=utf-8', 'SOAPAction' => '"'.$action.'"'])->when($this->token, fn ($http) => $http->withToken($this->token))->withBody($request, 'text/xml')->post($this->endpoint)->throw();
        $body = $res->body();
        abort_if(strlen($body) > 2 * 1024 * 1024 || stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false, 502, 'Unsafe or oversized SOAP response');

        return $oneWay ? null : $body;
    }
}
