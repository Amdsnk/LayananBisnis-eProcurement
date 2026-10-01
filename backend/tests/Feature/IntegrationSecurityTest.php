<?php

namespace Tests\Feature;

use App\Domain\Oidc;
use App\Domain\SoapTransport;
use App\Models\User;
use Database\Seeders\SandboxSeeder;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IntegrationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SandboxSeeder::class);
    }

    private function asRole(string $role): static
    {
        $u = User::where('email', $role.'@sandbox.test')->firstOrFail();
        $this->actingAs($u)->withSession(['tenant_id' => DB::table('memberships')->where('user_id', $u->id)->value('tenant_id'), 'mfa_verified' => true]);

        return $this;
    }

    public function test_csv_import_preview_then_idempotent_commit(): void
    {
        $this->asRole('admin');
        $file = UploadedFile::fake()->createWithContent('items.csv', "code,name\nNEW-001,New item\nNEW-002,Second item\n");
        $r = $this->postJson('/api/v1/imports/items/preview', ['file' => $file])->assertOk()->assertJsonPath('invalid', 0);
        $id = $r->json('id');
        $a = $this->postJson('/api/v1/imports/'.$id.'/commit', [], ['Idempotency-Key' => 'commit-import-key'])->assertOk()->assertJsonPath('created', 2);
        $this->postJson('/api/v1/imports/'.$id.'/commit', [], ['Idempotency-Key' => 'commit-import-key'])->assertOk()->assertJsonPath('created', 2);
        $this->assertSame(2, DB::table('items')->whereIn('code', ['NEW-001', 'NEW-002'])->count());
    }

    public function test_import_formula_like_cell_is_rejected(): void
    {
        $this->asRole('admin');
        $file = UploadedFile::fake()->createWithContent('items.csv', "code,name\nBAD,=HYPERLINK(attack)\n");
        $r = $this->postJson('/api/v1/imports/items/preview', ['file' => $file])->assertOk()->assertJsonPath('invalid', 1);
        $this->postJson('/api/v1/imports/'.$r->json('id').'/commit', [], ['Idempotency-Key' => 'invalid-import-key'])->assertUnprocessable();
    }

    public function test_async_export_is_private_and_revoked_on_membership_change(): void
    {
        Storage::fake('local');
        $this->asRole('requester');
        $r = $this->postJson('/api/v1/exports', ['format' => 'csv', 'kind' => 'pr'], ['Idempotency-Key' => 'csv-export-key'])->assertOk();
        $id = $r->json('id');
        $this->get('/api/v1/exports/'.$id.'/download')->assertOk();
        DB::table('memberships')->where('user_id', auth()->id())->update(['scope' => '{"cost_center_id":[999]}']);
        $this->getJson('/api/v1/exports/'.$id.'/download')->assertForbidden();
    }

    public function test_webhook_authentication_and_out_of_order_deduplication(): void
    {
        $t = DB::table('tenants')->value('id');
        $secret = 'FixtureOnlySecret';
        $id = DB::table('integration_configs')->insertGetId(['tenant_id' => $t, 'name' => 'Fixture', 'protocol' => 'rest', 'endpoint' => 'https://example.com', 'enabled' => true, 'secret' => Crypt::encryptString($secret), 'mapping' => '{}']);
        $v = ['external_id' => 'ERP-EVENT-1', 'sequence' => 2, 'payload' => ['status' => 'paid']];
        $body = json_encode($v);
        $ts = (string) time();
        $h = ['X-Timestamp' => $ts, 'X-Signature' => hash_hmac('sha256', $ts.'.'.$body, $secret)];
        $this->postJson('/api/v1/webhooks/'.$id, $v, $h)->assertOk()->assertJsonPath('accepted', true);
        $this->postJson('/api/v1/webhooks/'.$id, $v, $h)->assertOk()->assertJsonPath('accepted', false);
        $v['sequence'] = 1;
        $h['X-Signature'] = hash_hmac('sha256', $ts.'.'.json_encode($v), $secret);
        $this->postJson('/api/v1/webhooks/'.$id, $v, $h)->assertOk()->assertJsonPath('accepted', false);
        $this->postJson('/api/v1/webhooks/'.$id, $v, ['X-Timestamp' => $ts, 'X-Signature' => 'fake'])->assertUnauthorized();
    }

    private function identityFixture(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $details = openssl_pkey_get_details($key);
        $enc = fn ($x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
        $jwks = ['keys' => [['kty' => 'RSA', 'kid' => 'fixture-key', 'alg' => 'RS256', 'use' => 'sig', 'n' => $enc($details['rsa']['n']), 'e' => $enc($details['rsa']['e'])]]];
        config(['oidc.issuer' => 'https://identity.example.test', 'oidc.client_id' => 'procurement-client']);
        $claims = ['iss' => 'https://identity.example.test', 'aud' => 'procurement-client', 'sub' => 'fixture-user', 'iat' => time(), 'exp' => time() + 120, 'nonce' => 'fixture-nonce'];

        return [JWT::encode($claims, $private, 'RS256', 'fixture-key'), $jwks];
    }

    public function test_oidc_verified_signature_audience_issuer_and_nonce(): void
    {
        [$token,$keys] = $this->identityFixture();
        $claims = Oidc::verify($token, $keys, 'fixture-nonce');
        $this->assertSame('fixture-user', $claims->sub);
    }

    public function test_oidc_nonce_mismatch_is_rejected(): void
    {
        [$token,$keys] = $this->identityFixture();
        $this->expectException(HttpException::class);
        Oidc::verify($token, $keys, 'wrong-nonce');
    }

    public function test_soap_contract_and_safe_transport(): void
    {
        config(['procurement.erp_allowed_hosts' => ['example.com']]);
        Http::fake(['https://example.com/erp' => Http::response(file_get_contents(resource_path('contracts/soap-response.xml')), 200, ['Content-Type' => 'text/xml'])]);
        $client = new class(resource_path('contracts/procurement.wsdl'), 'https://example.com/erp') extends SoapTransport
        {
            protected function resolveHost(string $host): string
            {
                return '93.184.216.34';
            }
        };
        $res = $client->__soapCall('SubmitPurchaseOrder', [['Number' => 'PO-001', 'Currency' => 'IDR', 'Total' => '12000.0000', 'IdempotencyKey' => 'soap-fixture-key']]);
        $this->assertSame('ERP-PO-0001', $res->ExternalId);
    }

    public function test_soap_xxe_response_is_rejected(): void
    {
        config(['procurement.erp_allowed_hosts' => ['example.com']]);
        Http::fake(['https://example.com/erp' => Http::response('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY attack SYSTEM "file:///etc/passwd">]><x>&attack;</x>')]);
        $client = new class(resource_path('contracts/procurement.wsdl'), 'https://example.com/erp') extends SoapTransport
        {
            protected function resolveHost(string $host): string
            {
                return '93.184.216.34';
            }
        };
        $this->expectException(HttpException::class);
        $client->__soapCall('SubmitPurchaseOrder', [['Number' => 'PO-1', 'Currency' => 'IDR', 'Total' => '1', 'IdempotencyKey' => 'fixture']]);
    }

    public function test_existing_mfa_flag_does_not_block_access(): void
    {
        $this->asRole('admin');
        $u = auth()->user();
        $u->mfa_enabled = true;
        $u->save();
        $this->withSession(['mfa_verified' => false])->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.mfa_enabled', false);
        $this->assertFalse(app('router')->getRoutes()->hasNamedRoute('mfa.verify'));
        foreach (app('router')->getRoutes() as $route) {
            $this->assertStringNotContainsString('mfa/', $route->uri());
        }
    }

    public function test_absolute_session_timeout_is_enforced(): void
    {
        $this->asRole('requester')->withSession(['signed_in_at' => time() - 9 * 3600]);
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
