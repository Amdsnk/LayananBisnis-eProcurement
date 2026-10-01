<?php

namespace Tests\Feature;

use App\Domain\Money;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\SandboxSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcurementTest extends TestCase
{
    use RefreshDatabase;

    private int $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SandboxSeeder::class);
        $this->tenant = DB::table('tenants')->value('id');
    }

    private function asRole(string $role): static
    {
        $this->actingAs(User::where('email', $role.'@sandbox.test')->firstOrFail())->withSession(['tenant_id' => $this->tenant, 'mfa_verified' => true]);

        return $this;
    }

    private function postAction(int $id, string $action, array $data = [])
    {
        return $this->postJson('/api/v1/documents/'.$id.'/actions/'.$action, ['version' => Document::findOrFail($id)->version, 'reason' => 'Reviewed and accepted for test', ...$data], ['Idempotency-Key' => bin2hex(random_bytes(12))]);
    }

    private function payload(string $kind, ?Document $source = null): array
    {
        $b = DB::table('budgets')->first();

        return ['kind' => $kind, 'title' => 'Test '.$kind, 'legal_entity_id' => $b->legal_entity_id, 'cost_center_id' => $b->cost_center_id, 'budget_id' => $b->id, 'vendor_id' => DB::table('vendors')->value('id'), 'source_id' => $source?->id, 'currency' => 'IDR', 'exchange_rate' => '1', 'data' => ['justification' => 'Business test case', 'invoice_number' => 'INV-TEST-001'], 'lines' => [['description' => 'Business laptop', 'uom' => 'unit', 'quantity' => '2', 'unit_price' => '12500000', 'discount_percent' => '0', 'tax_percent' => '0', 'source_line_id' => $source?->lines->first()->id]]];
    }

    private function create(string $role, string $kind, ?Document $source = null): Document
    {
        $res = $this->asRole($role)->postJson('/api/v1/documents', $this->payload($kind, $source), ['Idempotency-Key' => bin2hex(random_bytes(12))]);
        $res->assertOk();

        return Document::findOrFail($res->json('id'))->load('lines');
    }

    private function issuedPo(): Document
    {
        $pr = $this->create('requester', 'pr');
        $this->asRole('requester')->postAction($pr->id, 'submit')->assertOk();
        $this->asRole('approver')->postAction($pr->id, 'approve')->assertOk();
        $po = $this->create('procurement', 'po', $pr->fresh());
        $this->asRole('procurement')->postAction($po->id, 'submit')->assertOk();
        $this->asRole('approver')->postAction($po->id, 'approve')->assertOk();
        $this->asRole('procurement')->postAction($po->id, 'issue')->assertOk();

        return $po->fresh()->load('lines');
    }

    public function test_decimal_calculation_and_rounding(): void
    {
        $l = Money::line(['quantity' => '3', 'unit_price' => '0.3333', 'discount_percent' => '10', 'tax_percent' => '11']);
        $this->assertSame('0.8999', $l['net']);
        $this->assertSame('0.0990', $l['tax']);
        $this->assertSame('0.9989', $l['total']);
        $this->assertSame('1.2346', Money::round('1.23455'));
    }

    public function test_session_authentication_required(): void
    {
        $this->getJson('/api/v1/documents')->assertUnauthorized();
    }

    public function test_foreign_tenant_document_not_accessible(): void
    {
        $pr = $this->create('requester', 'pr');
        $t = DB::table('tenants')->insertGetId(['name' => 'Other', 'slug' => 'other']);
        $u = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $t, 'user_id' => $u->id, 'role' => 'auditor', 'scope' => '{}']);
        $this->actingAs($u->fresh())->withSession(['tenant_id' => $t, 'mfa_verified' => true]);
        $this->getJson('/api/v1/documents/'.$pr->id)->assertNotFound();
    }

    public function test_scope_is_checked_at_create(): void
    {
        $this->asRole('requester');
        DB::table('memberships')->where('tenant_id', $this->tenant)->where('user_id', auth()->id())->update(['scope' => json_encode(['cost_center_id' => [999]])]);
        $this->postJson('/api/v1/documents', $this->payload('pr'), ['Idempotency-Key' => 'scope-test-key'])->assertForbidden();
    }

    public function test_self_approval_is_rejected_even_with_approver_role(): void
    {
        $pr = $this->create('requester', 'pr');
        $this->asRole('requester')->postAction($pr->id, 'submit')->assertOk();
        DB::table('memberships')->where('user_id', $pr->created_by)->update(['role' => 'approver']);
        $this->asRole('requester')->postAction($pr->id, 'approve')->assertForbidden();
    }

    public function test_idempotency_returns_same_document_and_rejects_changed_payload(): void
    {
        $v = $this->payload('pr');
        $this->asRole('requester');
        $a = $this->postJson('/api/v1/documents', $v, ['Idempotency-Key' => 'same-request-key'])->assertOk();
        $b = $this->postJson('/api/v1/documents', $v, ['Idempotency-Key' => 'same-request-key'])->assertOk();
        $this->assertSame($a->json('id'), $b->json('id'));
        $v['title'] = 'Changed payload';
        $this->postJson('/api/v1/documents', $v, ['Idempotency-Key' => 'same-request-key'])->assertConflict();
    }

    public function test_budget_reservation_released_on_return(): void
    {
        $pr = $this->create('requester', 'pr');
        $this->asRole('requester')->postAction($pr->id, 'submit')->assertOk();
        $this->assertSame('25000000.0000', DB::table('budgets')->value('reserved'));
        $this->asRole('approver')->postAction($pr->id, 'return')->assertOk();
        $this->assertSame('0.0000', DB::table('budgets')->value('reserved'));
    }

    public function test_budget_overspend_is_atomic(): void
    {
        DB::table('budgets')->update(['allocated' => '100']);
        $pr = $this->create('requester', 'pr');
        $this->asRole('requester')->postAction($pr->id, 'submit')->assertConflict();
        $this->assertSame('draft', $pr->fresh()->status);
        $this->assertSame('0.0000', DB::table('budgets')->value('reserved'));
    }

    public function test_optimistic_lock_rejects_stale_draft_edit(): void
    {
        $pr = $this->create('requester', 'pr');
        $v = $this->payload('pr');
        $v['version'] = 999;
        $this->asRole('requester')->putJson('/api/v1/documents/'.$pr->id, $v, ['Idempotency-Key' => 'stale-edit-key'])->assertConflict();
    }

    public function test_receiver_cannot_create_purchase_order(): void
    {
        $this->asRole('receiver')->postJson('/api/v1/documents', $this->payload('po'), ['Idempotency-Key' => 'receiver-po-key'])->assertForbidden();
    }

    public function test_po_converts_reservation_without_double_counting(): void
    {
        $this->issuedPo();
        $b = DB::table('budgets')->first();
        $this->assertSame('0.0000', $b->reserved);
        $this->assertSame('25000000.0000', $b->committed);
        $this->assertSame('0.0000', $b->actual);
    }

    public function test_over_receipt_is_rejected(): void
    {
        $po = $this->issuedPo();
        $receipt = $this->create('receiver', 'receipt', $po);
        $this->asRole('receiver')->postAction($receipt->id, 'post')->assertOk();
        $this->assertSame('received', $po->fresh()->status);
        $v = $this->payload('receipt', $po);
        $this->asRole('receiver')->postJson('/api/v1/documents', $v, ['Idempotency-Key' => 'second-receipt-key'])->assertOk();
        $second = Document::where('kind', 'receipt')->latest('id')->first();
        $this->postAction($second->id, 'post')->assertConflict();
    }

    public function test_invoice_requires_received_quantity(): void
    {
        $po = $this->issuedPo();
        $invoice = $this->create('finance', 'invoice', $po);
        $this->asRole('finance')->postAction($invoice->id, 'match')->assertOk()->assertJsonPath('status', 'mismatch');
    }

    public function test_duplicate_invoice_number_is_rejected(): void
    {
        $po = $this->issuedPo();
        $this->create('finance', 'invoice', $po);
        $this->asRole('finance')->postJson('/api/v1/documents', $this->payload('invoice', $po), ['Idempotency-Key' => 'duplicate-invoice-key'])->assertConflict();
    }

    public function test_full_direct_procurement_to_payment(): void
    {
        $po = $this->issuedPo();
        $receipt = $this->create('receiver', 'receipt', $po);
        $this->asRole('receiver')->postAction($receipt->id, 'post')->assertOk();
        $invoice = $this->create('finance', 'invoice', $po->fresh()->load('lines'));
        $this->asRole('finance')->postAction($invoice->id, 'match')->assertOk()->assertJsonPath('status', 'matched');
        $this->postAction($invoice->id, 'submit')->assertOk();
        $this->asRole('approver')->postAction($invoice->id, 'approve')->assertOk();
        $this->asRole('finance')->postAction($invoice->id, 'pay', ['payment_reference' => 'ERP-PAID-0001'])->assertForbidden();
        $u = User::factory()->create(['email' => 'finance2@test.local']);
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $u->id, 'role' => 'finance', 'scope' => '{}']);
        $this->actingAs($u->fresh())->withSession(['tenant_id' => $this->tenant, 'mfa_verified' => true]);
        $this->postAction($invoice->id, 'pay', ['payment_reference' => 'ERP-PAID-0001'])->assertOk();
        $b = DB::table('budgets')->first();
        $this->assertSame('25000000.0000', $b->actual);
        $this->assertSame('0.0000', $b->committed);
    }

    public function test_vendor_cannot_see_other_vendor_po(): void
    {
        $po = $this->issuedPo();
        $this->asRole('vendor_staff')->getJson('/api/v1/documents/'.$po->id)->assertNotFound();
        $this->asRole('vendor_admin')->getJson('/api/v1/documents/'.$po->id)->assertOk();
    }

    public function test_audit_update_is_rejected_by_database(): void
    {
        $this->create('requester', 'pr');
        $this->expectException(QueryException::class);
        DB::table('audit_events')->update(['action' => 'tamper']);
    }

    public function test_production_sandbox_command_is_blocked(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('procurement:sandbox')->assertExitCode(1);
    }

    public function test_dashboard_uses_scoped_database_counts(): void
    {
        $this->asRole('vendor_admin')->getJson('/api/v1/dashboard')->assertOk()->assertJsonCount(0, 'budgets')->assertJsonCount(0, 'recent');
    }

    private function tender(): Document
    {
        $pr = $this->create('requester', 'pr');
        $this->asRole('requester')->postAction($pr->id, 'submit')->assertOk();
        $this->asRole('approver')->postAction($pr->id, 'approve')->assertOk();
        $v = $this->payload('tender', $pr->fresh()->load('lines'));
        $v['deadline'] = now()->addDays(2)->toISOString();
        $users = DB::table('memberships')->where('tenant_id', $this->tenant)->pluck('user_id', 'role');
        $v['data'] = ['method' => 'rfq', 'envelopes' => 2, 'opening_quorum' => 2, 'opening_user_ids' => [$users['procurement'], $users['manager']], 'evaluator_ids' => [$users['evaluator']], 'criteria' => [['name' => 'Technical', 'weight' => 60, 'phase' => 'technical'], ['name' => 'Commercial', 'weight' => 40, 'phase' => 'commercial']]];
        $res = $this->asRole('procurement')->postJson('/api/v1/documents', $v, ['Idempotency-Key' => bin2hex(random_bytes(12))])->assertOk();
        $d = Document::findOrFail($res->json('id'))->load('lines');
        $vids = DB::table('vendors')->where('tenant_id', $this->tenant)->limit(2)->pluck('id')->all();
        $this->postJson('/api/v1/tenders/'.$d->id.'/publish', ['vendor_ids' => $vids], ['Idempotency-Key' => bin2hex(random_bytes(12))])->assertOk();

        return $d->fresh()->load('lines');
    }

    private function submitBid(Document $d, string $role = 'vendor_admin')
    {
        return $this->asRole($role)->postJson('/api/v1/tenders/'.$d->id.'/bids', ['technical' => ['summary' => 'Compliant laptop with warranty'], 'commercial' => ['lines' => [['source_line_id' => $d->lines->first()->id, 'quantity' => '2', 'unit_price' => '12000000']], 'currency' => 'IDR', 'lead_days' => 7, 'payment_terms' => 'Net 30']], ['Idempotency-Key' => bin2hex(random_bytes(12))]);
    }

    private function openEnvelope(Document $d, string $envelope, string $role)
    {
        return $this->asRole($role)->postJson('/api/v1/tenders/'.$d->id.'/open', ['envelope' => $envelope], ['Idempotency-Key' => bin2hex(random_bytes(12))]);
    }

    public function test_sealed_bid_cannot_be_read_before_opening(): void
    {
        $d = $this->tender();
        $this->submitBid($d)->assertOk();
        $b = DB::table('bids')->first();
        $this->assertStringNotContainsString('12000000', $b->commercial_cipher);
        $this->asRole('procurement')->getJson('/api/v1/tenders/'.$d->id.'/bids')->assertOk()->assertJsonPath('0.commercial', null)->assertJsonPath('0.technical', null);
        $this->asRole('vendor_admin')->getJson('/api/v1/tenders/'.$d->id.'/bids')->assertOk()->assertJsonPath('0.commercial.lines.0.unit_price', '12000000');
        $this->asRole('vendor_staff')->getJson('/api/v1/tenders/'.$d->id.'/bids')->assertJsonCount(0);
    }

    public function test_deadline_is_enforced_without_scheduler(): void
    {
        $d = $this->tender();
        Document::where('id', $d->id)->update(['deadline' => now()->subSecond()]);
        $this->submitBid($d)->assertConflict();
    }

    public function test_opening_requires_deadline_and_panel_quorum(): void
    {
        $d = $this->tender();
        $this->submitBid($d)->assertOk();
        $this->openEnvelope($d, 'technical', 'procurement')->assertConflict();
        Document::where('id', $d->id)->update(['deadline' => now()->subSecond()]);
        $this->openEnvelope($d, 'technical', 'procurement')->assertOk()->assertJsonPath('status', 'published');
        $this->openEnvelope($d, 'technical', 'manager')->assertOk()->assertJsonPath('status', 'technical_open');
        $this->asRole('evaluator')->getJson('/api/v1/tenders/'.$d->id.'/bids')->assertJsonPath('0.commercial', null)->assertJsonPath('0.technical.summary', 'Compliant laptop with warranty');
    }

    public function test_commercial_opening_waits_for_technical_evaluation(): void
    {
        $d = $this->tender();
        $this->submitBid($d)->assertOk();
        Document::where('id', $d->id)->update(['deadline' => now()->subSecond()]);
        $this->openEnvelope($d, 'technical', 'procurement')->assertOk();
        $this->openEnvelope($d, 'technical', 'manager')->assertOk();
        $this->openEnvelope($d, 'commercial', 'manager')->assertConflict();
        $vid = DB::table('bids')->where('document_id', $d->id)->value('vendor_id');
        $this->asRole('evaluator')->postJson('/api/v1/tenders/'.$d->id.'/evaluate', ['vendor_id' => $vid, 'scores' => [90, 0], 'comment' => 'Technical documents meet specifications', 'conflict_declared' => false], ['Idempotency-Key' => 'technical-evaluation-key'])->assertOk();
        $this->openEnvelope($d, 'commercial', 'procurement')->assertOk();
        $this->openEnvelope($d, 'commercial', 'manager')->assertOk()->assertJsonPath('status', 'commercial_open');
    }

    public function test_vendor_submission_revision_supersedes_previous_bid(): void
    {
        $d = $this->tender();
        $this->submitBid($d)->assertOk()->assertJsonPath('revision', 1);
        $this->submitBid($d)->assertOk()->assertJsonPath('revision', 2);
        $this->assertSame(1, DB::table('bids')->where('status', 'submitted')->count());
        $this->assertSame(1, DB::table('bids')->where('status', 'superseded')->count());
    }

    public function test_partial_receipt_and_invoice_allocation(): void
    {
        $po = $this->issuedPo();
        $v = $this->payload('receipt', $po);
        $v['lines'][0]['quantity'] = '1';
        $r = $this->asRole('receiver')->postJson('/api/v1/documents', $v, ['Idempotency-Key' => 'partial-receipt-key'])->assertOk();
        $this->postAction($r->json('id'), 'post')->assertOk();
        $this->assertSame('partially_received', $po->fresh()->status);
        $v = $this->payload('invoice', $po->fresh()->load('lines'));
        $v['lines'][0]['quantity'] = '1';
        $invoice = $this->asRole('finance')->postJson('/api/v1/documents', $v, ['Idempotency-Key' => 'partial-invoice-key'])->assertOk();
        $this->postAction($invoice->json('id'), 'match')->assertOk()->assertJsonPath('status', 'matched');
        $v['data']['invoice_number'] = 'INV-OTHER';
        $second = $this->postJson('/api/v1/documents', $v, ['Idempotency-Key' => 'over-invoice-key'])->assertOk();
        $this->postAction($second->json('id'), 'match')->assertOk()->assertJsonPath('status', 'mismatch');
    }

    public function test_duplicate_po_cannot_consume_same_source_twice(): void
    {
        $po = $this->issuedPo();
        $pr = Document::findOrFail($po->source_id)->load('lines');
        $second = $this->create('procurement', 'po', $pr);
        $this->asRole('procurement')->postAction($second->id, 'submit')->assertOk();
        $this->asRole('approver')->postAction($second->id, 'approve')->assertConflict();
        $this->assertSame('pending_approval', $second->fresh()->status);
    }

    public function test_suspended_vendor_loses_access_immediately(): void
    {
        $this->asRole('vendor_admin');
        $vid = DB::table('memberships')->where('user_id', auth()->id())->value('vendor_id');
        DB::table('vendors')->where('id', $vid)->update(['status' => 'suspended']);
        $this->getJson('/api/v1/documents')->assertForbidden();
    }

    public function test_vendor_onboarding_maker_checker(): void
    {
        $this->asRole('procurement');
        $r = $this->postJson('/api/v1/admin/vendors', ['code' => 'V-NEW', 'name' => 'New supplier', 'email' => 'new@supplier.test'], ['Idempotency-Key' => 'new-vendor-key'])->assertOk();
        $id = $r->json('id');
        $this->postJson('/api/v1/vendors/'.$id.'/review', ['action' => 'approve', 'reason' => 'Verified qualification'], ['Idempotency-Key' => 'self-vendor-review'])->assertForbidden();
        $this->asRole('manager')->postJson('/api/v1/vendors/'.$id.'/review', ['action' => 'approve', 'reason' => 'Verified qualification'], ['Idempotency-Key' => 'manager-vendor-review'])->assertOk()->assertJsonPath('status', 'approved');
    }

    public function test_attachments_remain_unavailable_when_scanner_missing(): void
    {
        $pr = $this->create('requester', 'pr');
        $id = DB::table('attachments')->insertGetId(['tenant_id' => $this->tenant, 'document_id' => $pr->id, 'uploaded_by' => $pr->created_by, 'name' => 'safe.pdf', 'key' => 'none', 'mime' => 'application/pdf', 'size' => 1, 'hash' => str_repeat('0', 64), 'status' => 'quarantined', 'created_at' => now(), 'updated_at' => now()]);
        $this->asRole('requester')->getJson('/api/v1/attachments/'.$id.'/download')->assertForbidden();
        $this->postAction($pr->id, 'submit')->assertConflict();
    }
}
