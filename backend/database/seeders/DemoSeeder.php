<?php

namespace Database\Seeders;

use App\Domain\Access;
use App\Domain\Audit;
use App\Domain\BudgetLedger;
use App\Domain\Money;
use App\Domain\Workflow;
use App\Jobs\GenerateExport;
use App\Jobs\ScanAttachment;
use App\Models\Document;
use Illuminate\Database\Seeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/** One-time fixtures in a dedicated tenant; never deletes or resets existing accounts. */
class DemoSeeder extends Seeder
{
    public string $password = '';
    public bool $withFiles = true;
    public bool $allowProduction = false;
    public array $accounts = [];
    public bool $created = false;
    public int $tenant;
    private array $users = [];
    private array $master = [];
    private array $vendors = [];
    private int $budget;
    private int $sequence = 0;
    private array $docs = [];

    public function run(): void
    {
        if (app()->environment('production') && ! $this->allowProduction) {
            throw new \RuntimeException('Use procurement:demo --force for an explicit one-time demo setup.');
        }
        if (strlen($this->password) < 12) {
            throw new \RuntimeException('A demo password of at least 12 characters is required.');
        }
        $key = base64_decode(config('procurement.bid_key') ?? '', true);
        if (! $key || strlen($key) !== 32) {
            throw new \RuntimeException('A valid BID_ENCRYPTION_KEY is required for demo sealed bids.');
        }
        $cipher = new Encrypter($key, 'AES-256-CBC');
        DB::transaction(function () use ($cipher) {
            // Serialize installation without adding a persistent bootstrap to application startup.
            DB::select("SELECT pg_advisory_xact_lock(72631001)");
            $existing = DB::table('tenants')->where('slug', 'demo-feature-workspace')->first();
            if ($existing) {
                $settings = json_decode($existing->settings, true);
                if (($settings['demo_fixture_version'] ?? null) !== 1) {
                    throw new \RuntimeException('An unrelated tenant uses the demo slug; no data changed.');
                }
                $this->tenant = $existing->id;
                return; // Reruns are no-ops, including passwords and MFA flags.
            }
            foreach (array_merge(Access::ROLES, ['manager2', 'evaluator2', 'approver2']) as $role) {
                $email = 'demo.'.$role.'@example.com';
                if (DB::table('users')->where('email', $email)->exists()) {
                    throw new \RuntimeException('Demo email already exists: '.$email.'; no account was overwritten.');
                }
            }
            $this->tenant = DB::table('tenants')->insertGetId([
                'name' => 'LayananBisnis · Demo Lengkap', 'slug' => 'demo-feature-workspace',
                'settings' => json_encode(['demo_fixture_version' => 1, 'synthetic_data' => true]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $hash = Hash::make($this->password);
            foreach (array_merge(Access::ROLES, ['manager2', 'evaluator2', 'approver2']) as $role) {
                $email = 'demo.'.$role.'@example.com';
                $actualRole = preg_replace('/2$/', '', $role);
                $id = DB::table('users')->insertGetId([
                    'name' => 'Demo '.ucwords(str_replace('_', ' ', $role)), 'email' => $email,
                    'password' => $hash, 'active' => true, 'mfa_enabled' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->users[$role] = $id;
                $this->accounts[$email] = $actualRole;
                DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $id, 'role' => $actualRole, 'scope' => '{}']);
            }
            $masters = [
                'legal_entities' => [['DEMO-PT', 'PT LayananBisnis Demo'], ['DEMO-CV', 'CV Operasional Demo']],
                'departments' => [['IT', 'Teknologi Informasi'], ['OPS', 'Operasional'], ['FIN', 'Keuangan']],
                'cost_centers' => [['IT', 'Anggaran TI'], ['OPS', 'Anggaran Operasional']],
                'warehouses' => [['JKT', 'Gudang Jakarta'], ['BDG', 'Gudang Bandung']],
                'categories' => [['IT', 'Perangkat TI'], ['OFFICE', 'Perlengkapan Kantor'], ['SERVICE', 'Jasa']],
                'items' => [['LAPTOP', 'Laptop kerja'], ['ROUTER', 'Router jaringan'], ['CHAIR', 'Kursi kantor']],
                'currencies' => [['IDR', 'Rupiah'], ['USD', 'US Dollar']],
                'tax_codes' => [['DEMO-ZERO', 'Demo tanpa pajak'], ['DEMO-TAX', 'Contoh kode pajak']],
                'payment_terms' => [['N30', '30 hari'], ['N60', '60 hari']],
                'procurement_plans' => [['PLAN-IT', 'Pembaruan perangkat TI'], ['PLAN-OPS', 'Perawatan fasilitas']],
            ];
            foreach ($masters as $table => $rows) {
                foreach ($rows as $i => [$code, $name]) {
                    $this->master[$table][$i] = DB::table($table)->insertGetId([
                        'tenant_id' => $this->tenant, 'code' => $code, 'name' => $name,
                        'data' => json_encode(['demo' => true, 'year' => (int) now()->format('Y')]),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            foreach ([['TECH', 'PT Teknologi Demo', 'approved'], ['OFFICE', 'CV Kantor Demo', 'approved'], ['SERVICE', 'PT Jasa Demo', 'approved'], ['REVIEW', 'Pemasok Dalam Review', 'under_review'], ['DRAFT', 'Pemasok Draft', 'draft']] as [$code, $name, $status]) {
                $this->vendors[] = DB::table('vendors')->insertGetId([
                    'tenant_id' => $this->tenant, 'code' => 'DEMO-'.$code, 'name' => $name,
                    'email' => strtolower($code).'@vendor.example', 'status' => $status,
                    'created_by' => $this->users['procurement'], 'data' => json_encode(['city' => 'Jakarta', 'demo' => true]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach (['vendor_admin' => 0, 'vendor_staff' => 1] as $role => $vendor) {
                DB::table('memberships')->where('tenant_id', $this->tenant)->where('user_id', $this->users[$role])->update(['vendor_id' => $this->vendors[$vendor]]);
            }
            $this->budget = DB::table('budgets')->insertGetId([
                'tenant_id' => $this->tenant, 'legal_entity_id' => $this->master['legal_entities'][0],
                'cost_center_id' => $this->master['cost_centers'][0], 'name' => 'Anggaran Demo TI',
                'year' => (int) now()->format('Y'), 'allocated' => '2500000000',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('budgets')->insert([
                'tenant_id' => $this->tenant, 'legal_entity_id' => $this->master['legal_entities'][1],
                'cost_center_id' => $this->master['cost_centers'][1], 'name' => 'Anggaran Demo Operasional',
                'year' => (int) now()->format('Y'), 'allocated' => '500000000',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['pr', 'po', 'contract', 'invoice', 'tender'] as $kind) {
                DB::table('workflow_policies')->insert([
                    'tenant_id' => $this->tenant, 'kind' => $kind, 'name' => 'Demo approval '.$kind,
                    'version' => 1, 'steps' => json_encode([
                        ['approver_ids' => [$this->users['approver']], 'quorum' => 1],
                        ['approver_ids' => [$this->users['manager2']], 'quorum' => 1],
                    ]), 'conditions' => '{}', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('delegations')->insert([
                'tenant_id' => $this->tenant, 'from_user_id' => $this->users['approver'],
                'to_user_id' => $this->users['approver2'], 'starts_at' => now()->subDay(),
                'ends_at' => now()->addDays(30), 'reason' => 'Delegasi approval demo',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->document('pr', 'draft', 'Permintaan laptop baru · draft');
            $pending = $this->document('pr', 'draft', 'Permintaan router · menunggu approval', null, null, '6', '4500000');
            BudgetLedger::move($pending, 'reserve', $pending->budget_value, '0', '0', $this->users['requester']);
            Workflow::start($pending, $this->users['requester']);
            foreach (['returned', 'rejected'] as $status) {
                $d = $this->document('pr', $status, 'Permintaan perlengkapan · '.$status);
                $this->approvalSnapshot($d, $status);
            }

            // Complete purchase-to-pay chain with balanced budget ledger and linked line quantities.
            $pr = $this->approvedPr('Pengadaan 10 laptop', '10', '12500000');
            $tender = $this->tender($pr, 'awarded', $cipher);
            $contract = $this->document('contract', 'active', 'Kontrak penyediaan laptop', $tender, $this->vendors[0], '10', '12500000');
            $contract->data = array_merge($contract->data, ['starts_at' => now()->subDays(15)->toDateString(), 'ends_at' => now()->addYear()->toDateString(), 'sla' => 'Garansi demo 12 bulan', 'milestones' => [['name' => 'Pengiriman', 'percent' => 100]]]);
            $contract->save();
            $this->approvalSnapshot($contract, 'approved');
            $po = $this->document('po', 'received', 'PO laptop · sudah diterima', $contract, $this->vendors[0], '10', '12500000');
            $this->commit($pr, $po);
            $this->approvalSnapshot($po, 'approved');
            $receipt = $this->document('receipt', 'posted', 'Penerimaan 10 laptop', $po, $this->vendors[0], '10', '12500000');
            $this->document('receipt', 'draft', 'Contoh penerimaan draft', $po, $this->vendors[0], '1', '12500000');
            $invoice = $this->document('invoice', 'paid', 'Invoice laptop · sudah dibayar', $po, $this->vendors[0], '10', '12500000');
            $invoice->data = array_merge($invoice->data, ['payment_reference' => 'DEMO-PAY-001', 'paid_at' => now()->toISOString(), 'match_issues' => []]);
            $invoice->save();
            $this->approvalSnapshot($invoice, 'approved');
            BudgetLedger::move($invoice, 'pay', '0', '-'.$invoice->budget_value, $invoice->budget_value, $this->users['finance']);
            $credit = $this->document('credit', 'posted', 'Kredit satu laptop · demo', $invoice, $this->vendors[0], '1', '12500000');
            BudgetLedger::move($credit, 'credit', '0', '0', '-'.$credit->budget_value, $this->users['finance']);
            $this->document('credit', 'draft', 'Kredit draft untuk review', $invoice, $this->vendors[0], '1', '12500000');

            // Return chain has no invoice, so returned goods can be reconciled safely.
            $returnPr = $this->approvedPr('Pengadaan kursi kantor', '8', '500000');
            $returnPo = $this->document('po', 'partially_received', 'PO kursi · penerimaan parsial', $returnPr, $this->vendors[1], '8', '500000');
            $this->commit($returnPr, $returnPo);
            $returnReceipt = $this->document('receipt', 'posted', 'Penerimaan 4 kursi', $returnPo, $this->vendors[1], '4', '500000');
            $this->document('return', 'posted', 'Retur 1 kursi rusak', $returnReceipt, $this->vendors[1], '1', '500000');
            $this->document('return', 'draft', 'Retur draft untuk inspeksi', $returnReceipt, $this->vendors[1], '1', '500000');
            $mismatch = $this->document('invoice', 'mismatch', 'Invoice kursi · selisih kuantitas', $returnPo, $this->vendors[1], '5', '500000');
            $mismatch->data = array_merge($mismatch->data, ['match_issues' => ['Jumlah tagihan melebihi penerimaan bersih 3 kursi.']]);
            $mismatch->save();
            $this->document('invoice', 'draft', 'Invoice kursi draft', $returnPo, $this->vendors[1], '3', '500000');

            foreach (['draft', 'published', 'technical_open', 'commercial_open', 'pending_approval'] as $status) {
                $eventPr = $this->approvedPr('Sourcing jaringan · '.$status, '4', '1500000');
                $this->tender($eventPr, $status, $cipher);
            }
            foreach (['draft', 'approved', 'issued', 'acknowledged', 'cancelled'] as $status) {
                $p = $this->approvedPr('Perlengkapan kantor · PO '.$status, '3', '750000');
                $order = $this->document('po', $status, 'PO untuk mencoba '.$status, $p, $this->vendors[1], '3', '750000');
                if (in_array($status, ['approved', 'issued', 'acknowledged'])) {
                    $this->commit($p, $order);
                    $this->approvalSnapshot($order, 'approved');
                }
                if ($status === 'issued') {
                    $this->document('receipt', 'draft', 'Penerimaan siap diposting', $order, $this->vendors[1], '3', '750000');
                    $this->document('invoice', 'draft', 'Invoice siap dicocokkan', $order, $this->vendors[1], '3', '750000');
                }
            }
            $invoicePr = $this->approvedPr('Router untuk proses keuangan', '4', '2500000');
            $invoicePo = $this->document('po', 'received', 'PO router · diterima penuh', $invoicePr, $this->vendors[0], '4', '2500000');
            $this->commit($invoicePr, $invoicePo);
            $this->document('receipt', 'posted', 'Penerimaan router', $invoicePo, $this->vendors[0], '4', '2500000');
            foreach (['matched', 'pending_approval', 'approved'] as $status) {
                $d = $this->document('invoice', $status === 'pending_approval' ? 'matched' : $status, 'Invoice router · '.$status, $invoicePo, $this->vendors[0], '1', '2500000');
                if ($status === 'pending_approval') {
                    Workflow::start($d, $this->users['finance']);
                } elseif ($status === 'approved') {
                    $this->approvalSnapshot($d, 'approved');
                }
            }
            $this->document('contract', 'draft', 'Kontrak draft untuk legal', $tender, $this->vendors[0], '10', '12500000');
            $revision = $this->docs[0];
            DB::table('document_revisions')->insert([
                'tenant_id' => $this->tenant, 'document_id' => $revision->id, 'version' => 1,
                'snapshot' => json_encode($revision->load('lines')->toArray()), 'actor_id' => $this->users['requester'],
                'reason' => 'Contoh riwayat revisi demo', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $revision->version = 2;
            $revision->save();
            DB::table('vendor_changes')->insert([
                'tenant_id' => $this->tenant, 'vendor_id' => $this->vendors[0], 'created_by' => $this->users['vendor_admin'],
                'data' => json_encode(['city' => 'Bandung', 'comment' => 'Contoh pengajuan perubahan profil']),
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($this->users as $role => $uid) {
                foreach ([null, now()->subDay()] as $read) {
                    DB::table('notifications')->insert([
                        'tenant_id' => $this->tenant, 'user_id' => $uid,
                        'title' => 'Demo: workspace '.$role.' siap dicoba', 'data' => json_encode(['demo' => true]),
                        'read_at' => $read, 'created_at' => now(),
                    ]);
                }
            }
            foreach (['rest', 'soap'] as $protocol) {
                DB::table('integration_configs')->insert([
                    'tenant_id' => $this->tenant, 'name' => 'Demo ERP '.strtoupper($protocol), 'protocol' => $protocol,
                    'endpoint' => 'https://erp-demo.invalid/'.$protocol, 'enabled' => false,
                    'mapping' => json_encode(['reference' => 'document_number']), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('integration_events')->insert([
                'tenant_id' => $this->tenant, 'external_id' => 'DEMO-ERP-001', 'sequence' => 1,
                'payload' => json_encode(['demo' => true, 'status' => 'received']), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['delivered', 'dead'] as $status) {
                DB::table('outbox')->insert([
                    'tenant_id' => $this->tenant, 'type' => 'erp.demo.snapshot', 'payload' => json_encode(['demo' => true, 'reference' => $po->number]),
                    'status' => $status, 'attempts' => $status === 'dead' ? 5 : 1,
                    'last_error' => $status === 'dead' ? 'Demo snapshot: ERP endpoint belum dikonfigurasi.' : null,
                    'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $rows = [['row' => 2, 'values' => ['code' => 'DEMO-IMPORT-001', 'name' => 'Barang dari impor demo'], 'errors' => [], 'existing' => false]];
            DB::table('import_previews')->insert([
                'tenant_id' => $this->tenant, 'user_id' => $this->users['admin'], 'resource' => 'items',
                'rows' => json_encode($rows), 'hash' => hash('sha256', json_encode($rows)), 'expires_at' => now()->addDay(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::write($this->tenant, $this->users['admin'], 'demo.installed', 'tenant', $this->tenant,
                ['fixture_version' => 1, 'synthetic_data' => true, 'documents' => count($this->docs)]);
            $this->created = true;
        });
        if ($this->created && $this->withFiles) {
            $this->files();
        }
    }

    private function document(string $kind, string $status, string $title, ?Document $source = null, ?int $vendor = null, string $qty = '2', string $price = '1000000'): Document
    {
        $role = match ($kind) { 'pr' => 'requester', 'tender', 'po' => 'procurement', 'contract' => 'legal', 'receipt', 'return' => 'receiver', default => 'finance' };
        $calc = Money::line(['quantity' => $qty, 'unit_price' => $price]);
        $data = ['demo' => true, 'justification' => 'Data fiktif untuk mencoba fitur aplikasi.'];
        if ($kind === 'invoice') {
            $data += ['invoice_number' => 'DEMO-INV-'.($this->sequence + 1), 'normalized_number' => 'DEMO-INV-'.($this->sequence + 1), 'invoice_date' => now()->subDays(7)->toDateString(), 'due_date' => now()->addDays(23)->toDateString(), 'tolerance_percent' => 0];
        }
        $d = Document::create([
            'tenant_id' => $this->tenant, 'kind' => $kind, 'status' => $status, 'version' => 1,
            'number' => 'DEMO-'.strtoupper($kind).'-'.str_pad((string) ++$this->sequence, 3, '0', STR_PAD_LEFT),
            'title' => $title, 'created_by' => $this->users[$role], 'legal_entity_id' => $this->master['legal_entities'][0],
            'cost_center_id' => $this->master['cost_centers'][0], 'budget_id' => $this->budget,
            'vendor_id' => $vendor, 'source_id' => $source?->id, 'total' => $calc['total'], 'budget_value' => $calc['total'],
            'needed_at' => now()->addDays(21), 'data' => $data,
        ]);
        $d->lines()->create(array_merge($calc, [
            'tenant_id' => $this->tenant, 'description' => $title, 'uom' => 'unit', 'quantity' => $qty,
            'unit_price' => $price, 'source_line_id' => $source?->lines()->first()->id,
        ]));
        Audit::write($this->tenant, $d->created_by, 'demo.document_snapshot', $kind, $d->id, ['status' => $status, 'synthetic' => true]);
        $this->docs[] = $d;
        return $d;
    }

    private function approvedPr(string $title, string $qty, string $price): Document
    {
        $pr = $this->document('pr', 'approved', $title, null, null, $qty, $price);
        BudgetLedger::move($pr, 'reserve', $pr->budget_value, '0', '0', $this->users['requester']);
        $this->approvalSnapshot($pr, 'approved');
        return $pr;
    }

    private function commit(Document $pr, Document $po): void
    {
        BudgetLedger::move($pr, 'convert_to_po', '-'.$po->budget_value, '0', '0', $this->users['approver']);
        BudgetLedger::move($po, 'commit', '0', $po->budget_value, '0', $this->users['approver']);
    }

    private function approvalSnapshot(Document $d, string $decision): void
    {
        DB::table('approval_steps')->insert([
            'tenant_id' => $this->tenant, 'document_id' => $d->id, 'round' => $d->version, 'position' => 1,
            'approver_id' => $this->users['approver'], 'status' => $decision, 'acted_by' => $this->users['approver'],
            'acted_at' => now()->subDay(), 'reason' => 'Snapshot approval fiktif untuk demo', 'snapshot' => json_encode(['quorum' => 1, 'demo' => true]),
        ]);
    }

    private function tender(Document $pr, string $status, Encrypter $cipher): Document
    {
        $sl = $pr->lines()->first();
        $d = $this->document('tender', $status === 'pending_approval' ? 'commercial_open' : $status,
            'Tender · '.$status, $pr, null, $sl->quantity, $sl->unit_price);
        $future = in_array($status, ['draft', 'published']);
        $d->deadline = $future ? now()->addDays(14) : now()->subDays(2);
        $d->opened_at = $future ? null : now()->subDay();
        $d->data = array_merge($d->data, [
            'method' => 'rfq', 'envelopes' => 2, 'opening_quorum' => 2,
            'opening_user_ids' => [$this->users['procurement'], $this->users['manager']],
            'evaluator_ids' => [$this->users['evaluator'], $this->users['evaluator2']],
            'criteria' => [['name' => 'Kualitas teknis', 'weight' => 60, 'phase' => 'technical'], ['name' => 'Harga dan waktu', 'weight' => 40, 'phase' => 'commercial']],
            'awarded_vendor_ids' => in_array($status, ['awarded', 'pending_approval']) ? [$this->vendors[0]] : [],
            'award_reason' => 'Contoh keputusan tender demo', 'award_proposed_by' => $this->users['manager'],
        ]);
        $d->save();
        if ($status === 'draft') { return $d; }
        $line = $d->lines()->first();
        foreach (array_slice($this->vendors, 0, 3) as $i => $vid) {
            DB::table('tender_invitations')->insert(['tenant_id' => $this->tenant, 'document_id' => $d->id, 'vendor_id' => $vid]);
            $technical = ['summary' => 'Spesifikasi dan garansi pemasok demo '.($i + 1)];
            $commercial = ['currency' => 'IDR', 'lead_days' => 7 + $i * 3, 'payment_terms' => 'N30', 'lines' => [['source_line_id' => $line->id, 'quantity' => $line->quantity, 'unit_price' => $line->unit_price]]];
            DB::table('bids')->insert([
                'tenant_id' => $this->tenant, 'document_id' => $d->id, 'vendor_id' => $vid, 'revision' => 1,
                'technical_cipher' => $cipher->encryptString(json_encode($technical)), 'commercial_cipher' => $cipher->encryptString(json_encode($commercial)),
                'hash' => hash('sha256', json_encode(['technical' => $technical, 'commercial' => $commercial])), 'submitted_at' => now()->subDays($future ? 0 : 3),
            ]);
            if (! $future) {
                $phases = $status === 'technical_open' ? ['technical'] : ['technical', 'commercial'];
                foreach ($phases as $phase) {
                    foreach (['evaluator', 'evaluator2'] as $role) {
                        $score = 94 - $i * 6;
                        DB::table('evaluations')->insert([
                            'tenant_id' => $this->tenant, 'document_id' => $d->id, 'vendor_id' => $vid,
                            'evaluator_id' => $this->users[$role], 'phase' => $phase,
                            'scores' => json_encode([$score, $score]), 'score' => $score,
                            'comment' => 'Penilaian fiktif untuk demo perbandingan pemasok', 'created_at' => now(),
                        ]);
                    }
                }
            }
        }
        if (! $future) {
            foreach ($status === 'technical_open' ? ['technical'] : ['technical', 'commercial'] as $phase) {
                foreach (['procurement', 'manager'] as $role) {
                    DB::table('opening_votes')->insert(['document_id' => $d->id, 'user_id' => $this->users[$role], 'envelope' => $phase, 'created_at' => now()->subDay()]);
                }
            }
        }
        if ($status === 'pending_approval') { Workflow::start($d, $this->users['manager']); }
        if ($status === 'awarded') { $this->approvalSnapshot($d, 'approved'); }
        return $d;
    }

    private function files(): void
    {
        $text = "LayananBisnis demo specification\nSynthetic content for attachment download and malware scanning.\n";
        $key = 'demo/'.$this->tenant.'/specification.txt';
        if (! Storage::put($key, $text)) { throw new \RuntimeException('Demo object storage write failed.'); }
        $id = DB::table('attachments')->insertGetId([
            'tenant_id' => $this->tenant, 'document_id' => $this->docs[0]->id, 'uploaded_by' => $this->users['requester'],
            'name' => 'demo-specification.txt', 'key' => $key, 'mime' => 'text/plain', 'size' => strlen($text),
            'hash' => hash('sha256', $text), 'status' => 'quarantined', 'created_at' => now(), 'updated_at' => now(),
        ]);
        ScanAttachment::dispatch($id); // Real scanning, never mark a file clean without a scanner result.
        $member = DB::table('memberships')->where('tenant_id', $this->tenant)->where('user_id', $this->users['admin'])->first();
        foreach (['csv', 'xlsx'] as $format) {
            $export = DB::table('exports')->insertGetId([
                'tenant_id' => $this->tenant, 'user_id' => $this->users['admin'], 'format' => $format,
                'filters' => json_encode(['kind' => null, 'membership' => hash('sha256', json_encode($member))]),
                'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            GenerateExport::dispatch($export);
        }
    }
}
