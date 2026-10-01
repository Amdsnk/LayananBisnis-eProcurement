<?php

namespace App\Http\Controllers;

use App\Domain\Oidc;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OidcController extends Controller
{
    public function config()
    {
        return ['oidc_enabled' => (bool) (config('oidc.issuer') && config('oidc.client_id'))];
    }

    public function start(Request $r)
    {
        $meta = Oidc::metadata();
        $state = bin2hex(random_bytes(24));
        $nonce = bin2hex(random_bytes(24));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $r->session()->put('oidc', ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'created_at' => time()]);
        $query = http_build_query(['client_id' => config('oidc.client_id'), 'response_type' => 'code', 'scope' => 'openid email profile', 'redirect_uri' => config('oidc.redirect_uri'), 'state' => $state, 'nonce' => $nonce, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256']);

        return redirect()->away($meta['authorization_endpoint'].'?'.$query);
    }

    public function callback(Request $r)
    {
        $flow = $r->session()->pull('oidc');
        abort_unless($flow && time() - $flow['created_at'] <= 300 && hash_equals($flow['state'], (string) $r->query('state')), 401, 'Invalid or expired OIDC state');
        $code = $r->validate(['code' => 'required|string|max:2048'])['code'];
        $meta = Oidc::metadata();
        $tokens = Oidc::http($meta['token_endpoint'])->asForm()->post($meta['token_endpoint'], ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => config('oidc.client_id'), 'client_secret' => config('oidc.client_secret'), 'redirect_uri' => config('oidc.redirect_uri'), 'code_verifier' => $flow['verifier']])->throw()->json();
        $keys = Oidc::http($meta['jwks_uri'])->get($meta['jwks_uri'])->throw()->json();
        $claims = Oidc::verify($tokens['id_token'] ?? '', $keys, $flow['nonce']);
        $link = DB::table('identity_links')->where('issuer', $claims->iss)->where('subject', $claims->sub)->first();
        $u = $link ? User::find($link->user_id) : null;
        if (! $u) {
            abort_unless(($claims->email_verified ?? false) === true && isset($claims->email), 401, 'Verified identity email required');
            $u = User::where('email', $claims->email)->where('active', true)->first();
            abort_unless($u, 403, 'Identity is not provisioned in this application');
            DB::table('identity_links')->insert(['issuer' => $claims->iss, 'subject' => $claims->sub, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        abort_unless($u->active, 403);
        $m = DB::table('memberships')->where('user_id', $u->id)->first();
        abort_unless($m, 403);
        Auth::login($u);
        $r->session()->regenerate();
        $r->session()->put('tenant_id', $m->tenant_id);
        $r->session()->put('signed_in_at', time());
        return redirect('/');
    }
}
