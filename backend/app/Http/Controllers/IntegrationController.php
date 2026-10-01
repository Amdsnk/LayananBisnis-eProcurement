<?php

namespace App\Http\Controllers;

use App\Domain\Access;
use App\Domain\Audit;
use App\Domain\Idempotency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class IntegrationController extends Controller
{
    public function toggle(Request $r, int $id)
    {
        return Idempotency::run($r, function () use ($r, $id) {
            $m = Access::membership($r);
            Access::require($m, 'integration.manage');
            $c = DB::table('integration_configs')->where('tenant_id', $m->tenant_id)->find($id);
            abort_unless($c, 404);
            abort_if(app()->environment('production') && $c->protocol === 'sandbox', 422);
            abort_if($c->protocol === 'soap', 422, 'SOAP contract has not been verified');
            DB::table('integration_configs')->where('id', $id)->update(['enabled' => ! $c->enabled, 'updated_at' => now()]);
            Audit::write($m->tenant_id, $r->user()->id, 'integration.toggled', 'integration', $id);

            return ['enabled' => ! $c->enabled];
        });
    }

    public function replay(Request $r, int $id)
    {
        return Idempotency::run($r, function () use ($r, $id) {
            $m = Access::membership($r);
            Access::require($m, 'integration.manage');
            $e = DB::table('outbox')->where('tenant_id', $m->tenant_id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($e, 404);
            abort_unless($e->status === 'dead', 409);
            DB::table('outbox')->where('id', $id)->update(['status' => 'pending', 'attempts' => 0, 'available_at' => now()]);
            Audit::write($m->tenant_id, $r->user()->id, 'outbox.replayed', 'outbox', $id);

            return ['ok' => true];
        });
    }

    public function webhook(Request $r, int $id)
    {
        abort_if(strlen($r->getContent()) > 1024 * 1024, 413, 'Webhook maximum size is 1 MB');
        $c = DB::table('integration_configs')->where('id', $id)->where('enabled', true)->first();
        abort_unless($c && $c->secret, 401);
        $ts = $r->header('X-Timestamp');
        abort_unless(ctype_digit((string) $ts) && abs(time() - (int) $ts) <= 300, 401);
        $sig = hash_hmac('sha256', $ts.'.'.$r->getContent(), Crypt::decryptString($c->secret));
        abort_unless(hash_equals($sig, (string) $r->header('X-Signature')), 401);
        $v = $r->validate(['external_id' => 'required|string|max:100', 'sequence' => 'required|integer|min:1', 'payload' => 'required|array']);

        return DB::transaction(function () use ($c, $v) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$c->tenant_id.':webhook:'.$v['external_id']]);
            $old = DB::table('integration_events')->where('tenant_id', $c->tenant_id)->where('external_id', $v['external_id'])->first();
            if ($old && $old->sequence >= $v['sequence']) {
                return ['accepted' => false, 'reason' => 'duplicate_or_stale'];
            }DB::table('integration_events')->updateOrInsert(['tenant_id' => $c->tenant_id, 'external_id' => $v['external_id']], ['sequence' => $v['sequence'], 'payload' => json_encode($v['payload']), 'created_at' => now(), 'updated_at' => now()]);

            return ['accepted' => true, 'mapped_to_business_record' => false];
        });
    }
}
