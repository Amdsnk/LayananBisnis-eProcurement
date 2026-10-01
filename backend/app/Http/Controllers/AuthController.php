<?php

namespace App\Http\Controllers;

use App\Domain\Access;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function csrf(Request $r)
    {
        return ['token' => csrf_token()];
    }

    public function login(Request $r)
    {
        $v = $r->validate(['email' => 'required|email', 'password' => 'required|string', 'code' => 'nullable|string']);
        if (! Auth::attempt(['email' => $v['email'], 'password' => $v['password'], 'active' => true])) {
            abort(422, 'Email or password incorrect');
        }$r->session()->regenerate();
        $u = $r->user();
        $r->session()->put('signed_in_at', time());
        $m = DB::table('memberships')->where('user_id', $u->id)->first();
        abort_unless($m, 403, 'No membership');
        $r->session()->put('tenant_id', $m->tenant_id);

        return ['mfa_required' => false, 'user' => ['name' => $u->name, 'email' => $u->email]];
    }

    public function me(Request $r)
    {
        $m = Access::membership($r);
        $tenant = DB::table('tenants')->find($m->tenant_id);

        return ['user' => ['id' => $r->user()->id, 'name' => $r->user()->name, 'email' => $r->user()->email, 'mfa_enabled' => false], 'membership' => $m, 'tenant' => $tenant, 'permissions' => Access::RIGHTS[$m->role] ?? [], 'memberships' => DB::table('memberships')->join('tenants', 'tenants.id', '=', 'memberships.tenant_id')->where('user_id', $r->user()->id)->select('tenant_id', 'tenants.name', 'role')->get(), 'production' => app()->environment('production')];
    }

    public function switch(Request $r)
    {
        $v = $r->validate(['tenant_id' => 'required|integer']);
        abort_unless(DB::table('memberships')->where('user_id', $r->user()->id)->where('tenant_id', $v['tenant_id'])->exists(), 403);
        $r->session()->put('tenant_id', $v['tenant_id']);
        $r->session()->regenerate();

        return ['ok' => true];
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return ['ok' => true];
    }

}
