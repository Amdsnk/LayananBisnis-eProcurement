<?php

namespace App\Domain;

use Illuminate\Support\Facades\DB;

class Idempotency
{
    public static function run($r, callable $fn)
    {
        $key = $r->header('Idempotency-Key');
        abort_unless(is_string($key) && strlen($key) >= 8 && strlen($key) <= 120, 422, 'Idempotency-Key required (8–120 characters)');

        return DB::transaction(function () use ($r, $fn, $key) {
            $tenant = Access::tenant($r);
            $actor = $r->user()->id;
            $hash = hash('sha256', $r->method().' '.$r->path().' '.$r->getContent());
            // Advisory lock is transaction scoped and safe across API replicas.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$tenant.':'.$actor.':'.$key]);
            $old = DB::table('idempotency_records')->where('tenant_id', $tenant)->where('actor_id', $actor)->where('key', $key)->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Idempotency key reused with different payload');

                return json_decode($old->response, true);
            }
            $response = $fn();
            DB::table('idempotency_records')->insert(['tenant_id' => $tenant, 'actor_id' => $actor, 'key' => $key, 'request_hash' => $hash, 'response' => json_encode($response), 'created_at' => now(), 'updated_at' => now()]);

            return $response;
        }, 3);
    }
}
