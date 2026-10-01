<?php

namespace App\Http\Controllers;

use App\Domain\Access;
use App\Domain\Audit;
use App\Domain\Idempotency;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    const MASTER = ['legal_entities', 'departments', 'cost_centers', 'warehouses', 'categories', 'items', 'tax_codes', 'currencies', 'payment_terms', 'procurement_plans'];

    public function lookups(Request $r)
    {
        $t = Access::tenant($r);
        $m = Access::membership($r);
        $out = [];
        $scope = json_decode($m->scope ?? '{}', true);
        foreach (self::MASTER as $table) {
            $q = DB::table($table)->where('tenant_id', $t)->where('active', true);
            $f = $table === 'legal_entities' ? 'legal_entity_id' : ($table === 'cost_centers' ? 'cost_center_id' : null);
            if ($f && ! empty($scope[$f])) {
                $q->whereIn('id', $scope[$f]);
            }$out[$table] = $q->get();
        }$out['vendors'] = DB::table('vendors')->where('tenant_id', $t)->when(str_starts_with($m->role, 'vendor'), fn ($q) => $q->where('id', $m->vendor_id))->select('id', 'code', 'name', 'status')->get();
        $q = DB::table('budgets')->where('tenant_id', $t);
        foreach (['legal_entity_id', 'cost_center_id'] as $f) {
            if (! empty($scope[$f])) {
                $q->whereIn($f, $scope[$f]);
            }
        }$out['budgets'] = str_starts_with($m->role, 'vendor') ? [] : $q->get();
        $out['users'] = str_starts_with($m->role, 'vendor') ? [] : DB::table('memberships')->join('users', 'users.id', '=', 'memberships.user_id')->where('tenant_id', $t)->select('users.id', 'users.name', 'memberships.role')->get();

        return $out;
    }

    public function list(Request $r, string $table)
    {
        $m = Access::membership($r);
        $t = $m->tenant_id;
        if (in_array($table, self::MASTER)) {
            Access::require($m, 'master');

            return DB::table($table)->where('tenant_id', $t)->orderBy('id')->get();
        }
        if ($table === 'vendors') {
            abort_unless(Access::can($m, 'vendor.manage') || $m->role === 'auditor' || str_starts_with($m->role, 'vendor'), 403);

            return DB::table('vendors')->where('tenant_id', $t)->when(str_starts_with($m->role, 'vendor'), fn ($q) => $q->where('id', $m->vendor_id))->get();
        }
        if ($table === 'budgets') {
            abort_unless(Access::can($m, 'budget.manage') || Access::can($m, 'budget.read') || $m->role === 'auditor', 403);

            return DB::table('budgets')->where('tenant_id', $t)->get();
        }
        if ($table === 'users') {
            Access::require($m, 'users');

            return DB::table('memberships')->join('users', 'users.id', '=', 'memberships.user_id')->where('tenant_id', $t)->select('users.id', 'users.name', 'users.email', 'users.active', 'users.mfa_enabled', 'memberships.role', 'memberships.vendor_id', 'memberships.scope')->get();
        }
        if ($table === 'policies') {
            Access::require($m, 'policy');

            return DB::table('workflow_policies')->where('tenant_id', $t)->get();
        }
        if ($table === 'audit') {
            Access::require($m, 'audit.read');

            return DB::table('audit_events')->where('tenant_id', $t)->orderByDesc('id')->paginate(50);
        }
        if ($table === 'integrations') {
            Access::require($m, 'integration.manage');

            return ['connectors' => DB::table('integration_configs')->where('tenant_id', $t)->select('id', 'name', 'protocol', 'endpoint', 'enabled', 'mapping')->get(), 'outbox' => DB::table('outbox')->where('tenant_id', $t)->orderByDesc('id')->limit(50)->get()];
        }
        if ($table === 'delegations') {
            Access::require($m, 'policy');

            return DB::table('delegations')->where('tenant_id', $t)->get();
        }abort(404);
    }

    public function save(Request $r, string $table)
    {
        return Idempotency::run($r, function () use ($r, $table) {
            $m = Access::membership($r);
            $t = $m->tenant_id;
            $id = null;
            if (in_array($table, self::MASTER)) {
                Access::require($m, 'master');
                $v = $r->validate(['code' => 'required|string|max:40', 'name' => 'required|string|max:180', 'data' => 'nullable|array']);
                $id = DB::table($table)->insertGetId(['tenant_id' => $t, 'code' => $v['code'], 'name' => $v['name'], 'data' => json_encode($v['data'] ?? []), 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($table === 'vendors') {
                Access::require($m, 'vendor.manage');
                $v = $r->validate(['code' => 'required|string|max:40', 'name' => 'required|string|max:180', 'email' => 'required|email', 'tax_id' => 'nullable|string|max:50', 'data' => 'nullable|array']);
                abort_if(DB::table('vendors')->where('tenant_id', $t)->where('email', $v['email'])->exists(), 409, 'Vendor email already registered');
                $id = DB::table('vendors')->insertGetId(['tenant_id' => $t, 'code' => $v['code'], 'name' => $v['name'], 'email' => $v['email'], 'tax_id' => $v['tax_id'] ?? null, 'data' => json_encode($v['data'] ?? []), 'created_by' => $r->user()->id, 'status' => 'under_review', 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($table === 'budgets') {
                Access::require($m, 'budget.manage');
                $v = $r->validate(['name' => 'required|string|max:180', 'legal_entity_id' => 'required|integer', 'cost_center_id' => 'required|integer', 'year' => 'required|integer|between:2020,2100', 'currency' => 'required|string|size:3', 'allocated' => 'required|numeric|gt:0']);
                foreach (['legal_entity_id' => 'legal_entities', 'cost_center_id' => 'cost_centers'] as $f => $tab) {
                    abort_unless(DB::table($tab)->where('tenant_id', $t)->where('id', $v[$f])->exists(), 422, 'Invalid scope');
                }$id = DB::table('budgets')->insertGetId(array_merge($v, ['tenant_id' => $t, 'created_at' => now(), 'updated_at' => now()]));
            } elseif ($table === 'users') {
                Access::require($m, 'users');
                $v = $r->validate(['name' => 'required|string|max:100', 'email' => 'required|email|unique:users,email', 'password' => 'required|string|min:14|max:120', 'role' => 'required|in:'.implode(',', Access::ROLES), 'vendor_id' => 'nullable|integer', 'scope' => 'nullable|array']);
                if (str_starts_with($v['role'], 'vendor')) {
                    abort_unless(DB::table('vendors')->where('tenant_id', $t)->where('id', $v['vendor_id'] ?? 0)->exists(), 422, 'Vendor membership requires valid vendor');
                }foreach (['legal_entity_id' => 'legal_entities', 'cost_center_id' => 'cost_centers'] as $f => $tab) {
                    foreach ($v['scope'][$f] ?? [] as $sid) {
                        abort_unless(DB::table($tab)->where('tenant_id', $t)->where('id', $sid)->exists(), 422, 'Invalid user scope');
                    }
                }$u = User::create(['name' => $v['name'], 'email' => $v['email'], 'password' => $v['password']]);
                $id = $u->id;
                DB::table('memberships')->insert(['tenant_id' => $t, 'user_id' => $id, 'role' => $v['role'], 'vendor_id' => $v['vendor_id'] ?? null, 'scope' => json_encode($v['scope'] ?? [])]);
            } elseif ($table === 'policies') {
                Access::require($m, 'policy');
                $v = $r->validate(['name' => 'required|string|max:100', 'kind' => 'required|in:pr,po,contract,invoice,tender', 'steps' => 'required|array|min:1', 'steps.*.approver_ids' => 'required|array|min:1', 'steps.*.approver_ids.*' => 'integer', 'steps.*.quorum' => 'required|integer|min:1', 'conditions' => 'nullable|array']);
                DB::table('tenants')->where('id', $t)->lockForUpdate()->first();
                foreach ($v['steps'] as $step) {
                    abort_if($step['quorum'] > count(array_unique($step['approver_ids'])), 422, 'Invalid quorum');
                    foreach ($step['approver_ids'] as $uid) {
                        $member = DB::table('memberships')->where('tenant_id', $t)->where('user_id', $uid)->first();
                        abort_unless($member && Access::can($member, 'approve'), 422, 'Invalid approver');
                    }
                }$ver = (int) DB::table('workflow_policies')->where('tenant_id', $t)->where('kind', $v['kind'])->max('version') + 1;
                DB::table('workflow_policies')->where('tenant_id', $t)->where('kind', $v['kind'])->update(['active' => false]);
                $id = DB::table('workflow_policies')->insertGetId(['tenant_id' => $t, 'name' => $v['name'], 'kind' => $v['kind'], 'version' => $ver, 'steps' => json_encode($v['steps']), 'conditions' => json_encode($v['conditions'] ?? []), 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($table === 'delegations') {
                Access::require($m, 'policy');
                $v = $r->validate(['from_user_id' => 'required|integer', 'to_user_id' => 'required|integer|different:from_user_id', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'reason' => 'required|string|min:5']);
                foreach (['from_user_id', 'to_user_id'] as $f) {
                    $member = DB::table('memberships')->where('tenant_id', $t)->where('user_id', $v[$f])->first();
                    abort_unless($member && Access::can($member, 'approve'), 422, 'Delegates must be tenant approvers');
                }$id = DB::table('delegations')->insertGetId(array_merge($v, ['tenant_id' => $t, 'created_at' => now(), 'updated_at' => now()]));
            } elseif ($table === 'integrations') {
                Access::require($m, 'integration.manage');
                $v = $r->validate(['name' => 'required|string|max:100', 'protocol' => 'required|in:rest,soap,sandbox', 'endpoint' => 'nullable|url|max:500', 'secret' => 'nullable|string|max:5000', 'mapping' => 'nullable|array']);
                abort_if(app()->environment('production') && $v['protocol'] === 'sandbox', 422, 'Sandbox connector forbidden in production');
                $id = DB::table('integration_configs')->insertGetId(['tenant_id' => $t, 'name' => $v['name'], 'protocol' => $v['protocol'], 'endpoint' => $v['endpoint'] ?? null, 'secret' => isset($v['secret']) ? Crypt::encryptString($v['secret']) : null, 'enabled' => false, 'mapping' => json_encode($v['mapping'] ?? []), 'created_at' => now(), 'updated_at' => now()]);
            } else {
                abort(404);
            }
            Audit::write($t, $r->user()->id, 'record.created', $table, $id);

            return ['id' => $id, 'ok' => true];
        });
    }

    public function vendorAction(Request $r, int $id)
    {
        return Idempotency::run($r, function () use ($r, $id) {
            $m = Access::membership($r);
            Access::require($m, 'vendor.manage');
            $v = $r->validate(['action' => 'required|in:approve,reject,suspend', 'reason' => 'required|string|min:5']);
            $vendor = DB::table('vendors')->where('tenant_id', $m->tenant_id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($vendor, 404);
            abort_if($vendor->created_by === $r->user()->id, 403, 'Vendor maker cannot approve/reject own onboarding');
            $next = ['approve' => 'approved', 'reject' => 'rejected', 'suspend' => 'suspended'][$v['action']];
            abort_unless(($v['action'] === 'suspend' && $vendor->status === 'approved') || ($v['action'] !== 'suspend' && in_array($vendor->status, ['under_review', 'rejected', 'suspended'])), 409, 'Invalid vendor transition');
            DB::table('vendors')->where('id', $id)->update(['status' => $next, 'version' => $vendor->version + 1, 'updated_at' => now()]);
            Audit::write($m->tenant_id, $r->user()->id, 'vendor.'.$v['action'], 'vendor', $id, ['reason' => $v['reason']]);

            return ['status' => $next];
        });
    }

    public function deactivate(Request $r, string $table, int $id)
    {
        return Idempotency::run($r, function () use ($r, $table, $id) {
            $m = Access::membership($r);
            if (in_array($table, self::MASTER)) {
                Access::require($m, 'master');
                DB::table($table)->where('tenant_id', $m->tenant_id)->where('id', $id)->update(['active' => false, 'updated_at' => now()]);
            } elseif ($table === 'users') {
                Access::require($m, 'users');
                abort_if($id === $r->user()->id, 422, 'Cannot deactivate own account');
                abort_unless(DB::table('memberships')->where('tenant_id', $m->tenant_id)->where('user_id', $id)->exists(), 404);
                DB::table('users')->where('id', $id)->update(['active' => false]);
                DB::table('sessions')->where('user_id', $id)->delete();
            } else {
                abort(404);
            }Audit::write($m->tenant_id, $r->user()->id, 'record.deactivated', $table, $id);

            return ['ok' => true];
        });
    }

    public function changeBank(Request $r, int $id)
    {
        return Idempotency::run($r, function () use ($r, $id) {
            $m = Access::membership($r);
            abort_unless(Access::can($m, 'vendor.change') || Access::can($m, 'vendor.manage'), 403);
            abort_unless(DB::table('vendors')->where('tenant_id', $m->tenant_id)->where('id', $id)->when(str_starts_with($m->role, 'vendor'), fn ($q) => $q->where('id', $m->vendor_id))->exists(), 404);
            $v = $r->validate(['bank_name' => 'required|string|max:100', 'account_number' => 'required|string|max:50', 'account_holder' => 'required|string|max:150']);
            $cid = DB::table('vendor_changes')->insertGetId(['tenant_id' => $m->tenant_id, 'vendor_id' => $id, 'created_by' => $r->user()->id, 'data' => json_encode($v), 'created_at' => now(), 'updated_at' => now()]);
            Audit::write($m->tenant_id, $r->user()->id, 'vendor.bank_change_requested', 'vendor', $id, ['change_id' => $cid]);

            return ['id' => $cid];
        });
    }

    public function reviewBank(Request $r, int $id)
    {
        return Idempotency::run($r, function () use ($r, $id) {
            $m = Access::membership($r);
            Access::require($m, 'vendor.manage');
            $v = $r->validate(['approve' => 'required|boolean', 'reason' => 'required|string|min:5']);
            $c = DB::table('vendor_changes')->where('tenant_id', $m->tenant_id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($c, 404);
            abort_if($c->created_by === $r->user()->id, 403, 'Self review prohibited');
            abort_unless($c->status === 'pending', 409);
            if ($v['approve']) {
                $vendor = DB::table('vendors')->where('id', $c->vendor_id)->lockForUpdate()->first();
                $data = json_decode($vendor->data, true);
                $data['bank'] = json_decode($c->data, true);
                DB::table('vendors')->where('id', $vendor->id)->update(['data' => json_encode($data), 'version' => $vendor->version + 1, 'updated_at' => now()]);
            }DB::table('vendor_changes')->where('id', $id)->update(['status' => $v['approve'] ? 'approved' : 'rejected', 'reviewed_by' => $r->user()->id, 'updated_at' => now()]);
            Audit::write($m->tenant_id, $r->user()->id, 'vendor.bank_change_reviewed', 'change', $id, ['approved' => $v['approve'], 'reason' => $v['reason']]);

            return ['ok' => true];
        });
    }
}
