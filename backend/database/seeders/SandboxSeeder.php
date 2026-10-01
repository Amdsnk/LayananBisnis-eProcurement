<?php

namespace Database\Seeders;

use App\Domain\Money;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SandboxSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('Sandbox forbidden in production');
        }
        if (DB::table('tenants')->where('slug', 'sandbox')->exists()) {
            return;
        }
        DB::transaction(function () {
            $t = DB::table('tenants')->insertGetId(['name' => 'Nusantara Operations · Sandbox', 'slug' => 'sandbox', 'created_at' => now(), 'updated_at' => now()]);
            $ids = [];
            foreach (['admin', 'requester', 'approver', 'procurement', 'manager', 'evaluator', 'legal', 'receiver', 'finance', 'auditor', 'vendor_admin', 'vendor_staff'] as $i => $role) {
                $u = User::create(['name' => ucwords(str_replace('_', ' ', $role)).' Sandbox', 'email' => $role.'@sandbox.test', 'password' => 'SandboxOnly!2026']);
                $ids[$role] = $u->id;
                DB::table('memberships')->insert(['tenant_id' => $t, 'user_id' => $u->id, 'role' => $role, 'scope' => '{}']);
            }
            $master = [];
            foreach (['legal_entities' => ['NUS', 'PT Nusantara Operations'], 'cost_centers' => ['OPS', 'Operations & Facilities'], 'departments' => ['OPS', 'Operations'], 'warehouses' => ['JKT', 'Jakarta Receiving'], 'categories' => ['IT', 'Technology & Equipment'], 'items' => ['LAP', 'Business laptop'], 'currencies' => ['IDR', 'Indonesian Rupiah'], 'payment_terms' => ['N30', 'Net 30 days'], 'tax_codes' => ['CONFIG', 'Configured tax policy']] as $table => [$code,$name]) {
                $master[$table] = DB::table($table)->insertGetId(['tenant_id' => $t, 'code' => $code, 'name' => $name, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            }
            $vendors = [];
            foreach ([['V-001', 'PT Sumber Teknologi', 'sales@sumber.example'], ['V-002', 'CV Mitra Persada', 'sales@mitra.example'], ['V-003', 'PT Prima Logistik', 'sales@prima.example']] as $v) {
                $vendors[] = DB::table('vendors')->insertGetId(['tenant_id' => $t, 'code' => $v[0], 'name' => $v[1], 'email' => $v[2], 'status' => 'approved', 'created_by' => $ids['procurement'], 'data' => json_encode(['city' => 'Jakarta', 'category' => 'Technology']), 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('memberships')->where('user_id', $ids['vendor_admin'])->update(['vendor_id' => $vendors[0]]);
            DB::table('memberships')->where('user_id', $ids['vendor_staff'])->update(['vendor_id' => $vendors[1]]);
            $b = DB::table('budgets')->insertGetId(['tenant_id' => $t, 'legal_entity_id' => $master['legal_entities'], 'cost_center_id' => $master['cost_centers'], 'name' => 'Operations Budget 2026', 'year' => 2026, 'currency' => 'IDR', 'allocated' => '2500000000', 'created_at' => now(), 'updated_at' => now()]);
            foreach (['pr', 'po', 'contract', 'invoice', 'tender'] as $kind) {
                DB::table('workflow_policies')->insert(['tenant_id' => $t, 'name' => 'Default '.$kind.' approval', 'kind' => $kind, 'version' => 1, 'steps' => json_encode([['approver_ids' => [$ids['approver']], 'quorum' => 1]]), 'conditions' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ([['Workstation refresh for operations', '12500000', '12'], ['Warehouse safety equipment', '185000', '40'], ['Office network upgrade', '4500000', '6']] as $i => [$title,$price,$qty]) {
                $calc = Money::line(['quantity' => $qty, 'unit_price' => $price]);
                $d = Document::create(['tenant_id' => $t, 'kind' => 'pr', 'number' => 'PR-2026-00'.($i + 1), 'title' => $title, 'created_by' => $ids['requester'], 'legal_entity_id' => $master['legal_entities'], 'cost_center_id' => $master['cost_centers'], 'budget_id' => $b, 'total' => $calc['total'], 'budget_value' => $calc['total'], 'data' => ['justification' => 'Sandbox procurement request; submit to start a real approval workflow.'], 'needed_at' => now()->addDays(21), 'status' => 'draft']);
                $d->lines()->create(array_merge($calc, ['tenant_id' => $t, 'description' => $title, 'uom' => 'unit', 'quantity' => $qty, 'unit_price' => $price]));
            }
        });
    }
}
