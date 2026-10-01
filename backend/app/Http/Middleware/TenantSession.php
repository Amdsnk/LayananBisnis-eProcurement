<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantSession
{
    public function handle(Request $r, Closure $next)
    {
        abort_unless($r->user() && $r->user()->active, 401, 'Please sign in');
        $signed = $r->session()->get('signed_in_at', time());
        $r->session()->put('signed_in_at', $signed);
        if (time() - $signed > config('procurement.session_absolute_minutes') * 60) {
            $r->session()->invalidate();
            abort(401, 'Session expired');
        }
        $m = DB::table('memberships')->where('user_id', $r->user()->id)->where('tenant_id', $r->session()->get('tenant_id'))->first();
        abort_unless($m, 403, 'Tenant membership required');
        $r->attributes->set('membership', $m);
        if (str_starts_with($m->role, 'vendor')) {
            abort_unless(DB::table('vendors')->where('tenant_id', $m->tenant_id)->where('id', $m->vendor_id)->where('status', 'approved')->exists(), 403, 'Vendor is not active');
        }

        return $next($r);
    }
}
