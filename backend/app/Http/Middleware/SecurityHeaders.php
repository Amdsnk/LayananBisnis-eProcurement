<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Str;

class SecurityHeaders
{
    public function handle($r, Closure $next)
    {
        $r->attributes->set('correlation_id', (string) Str::uuid());
        $res = $next($r);
        foreach (['X-Request-ID' => $r->attributes->get('correlation_id'), 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'same-origin', 'X-Frame-Options' => 'DENY', 'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()', 'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'self'", 'Cache-Control' => 'no-store'] as $k => $v) {
            $res->headers->set($k, $v);
        }if ($r->isSecure()) {
            $res->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $res;
    }
}
