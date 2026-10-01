<?php

namespace App\Domain;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Audit
{
    public static function write(int $tenant, ?int $actor, string $action, string $type, int|string $id, array $data = []): void
    {
        // A tenant lock serializes chain heads, including background events.
        DB::table('tenants')->where('id', $tenant)->lockForUpdate()->first();
        $previous = DB::table('audit_events')->where('tenant_id', $tenant)->orderByDesc('id')->value('hash');
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $correlation = request()->attributes->get('correlation_id') ?? (string) Str::uuid();
        $at = now()->toISOString();
        $hash = hash('sha256', json_encode([$previous, $tenant, $actor, $action, $type, (string) $id, $payload, $at, $correlation]));
        DB::table('audit_events')->insert(['tenant_id' => $tenant, 'actor_id' => $actor, 'action' => $action, 'object_type' => $type, 'object_id' => (string) $id, 'data' => $payload, 'correlation_id' => $correlation, 'previous_hash' => $previous, 'hash' => $hash, 'created_at' => $at]);
    }
}
