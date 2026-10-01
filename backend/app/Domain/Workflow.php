<?php

namespace App\Domain;

use App\Models\Document;
use Illuminate\Support\Facades\DB;

class Workflow
{
    public static function start(Document $d, int $actor): void
    {
        $policies = DB::table('workflow_policies')->where('tenant_id', $d->tenant_id)->where('kind', $d->kind)->where('active', true)->orderByDesc('version')->get();
        $p = null;
        foreach ($policies as $candidate) {
            $c = json_decode($candidate->conditions, true);
            if (isset($c['min_total']) && bccomp($d->total, (string) $c['min_total'], 4) < 0) {
                continue;
            }if (isset($c['max_total']) && bccomp($d->total, (string) $c['max_total'], 4) > 0) {
                continue;
            }if (isset($c['legal_entity_id']) && (int) $c['legal_entity_id'] !== $d->legal_entity_id) {
                continue;
            }if (isset($c['cost_center_id']) && (int) $c['cost_center_id'] !== $d->cost_center_id) {
                continue;
            }$p = $candidate;
            break;
        }
        abort_unless($p, 422, 'Configure an applicable approval policy first');
        $steps = json_decode($p->steps, true);
        abort_unless(count($steps) > 0, 422, 'Approval policy needs steps');
        foreach ($steps as $i => $step) {
            $users = array_values(array_unique($step['approver_ids'] ?? []));
            $quorum = $step['quorum'] ?? count($users);
            abort_unless($quorum > 0 && $quorum <= count($users), 422, 'Invalid approval quorum');
            foreach ($users as $uid) {
                abort_if((int) $uid === $d->created_by, 422, 'Policy includes document maker; self approval prohibited');
                $m = DB::table('memberships')->join('users', 'users.id', '=', 'memberships.user_id')->where('tenant_id', $d->tenant_id)->where('user_id', $uid)->where('active', true)->first();
                abort_unless($m && Access::can($m, 'approve'), 422, 'Invalid or inactive approver');
                $scope = json_decode($m->scope ?? '{}', true);
                foreach (['legal_entity_id', 'cost_center_id'] as $field) {
                    abort_if(! empty($scope[$field]) && ! in_array($d->$field, $scope[$field]), 422, 'Approver is outside document scope');
                }
                DB::table('approval_steps')->insert(['tenant_id' => $d->tenant_id, 'document_id' => $d->id, 'round' => $d->version, 'position' => $i + 1, 'approver_id' => $uid, 'status' => 'pending', 'snapshot' => json_encode(['policy_id' => $p->id, 'policy_version' => $p->version, 'quorum' => $quorum, 'step' => $step])]);
            }
        }
        $d->status = 'pending_approval';
        $d->save();
    }

    public static function decide(Document $d, int $actor, string $decision, string $reason): void
    {
        abort_unless($d->status === 'pending_approval', 409, 'Document is not awaiting approval');
        abort_if($d->created_by === $actor || ($d->data['award_proposed_by'] ?? null) === $actor, 403, 'Self approval prohibited');
        $steps = DB::table('approval_steps')->where('document_id', $d->id)->where('round', $d->version)->orderBy('position')->lockForUpdate()->get();
        $current = $steps->where('status', 'pending')->min('position');
        abort_unless($current, 409, 'No pending step');
        $delegators = DB::table('delegations')->where('tenant_id', $d->tenant_id)->where('to_user_id', $actor)->where('starts_at', '<=', now())->where('ends_at', '>', now())->pluck('from_user_id')->all();
        $step = $steps->first(fn ($s) => $s->position === $current && $s->status === 'pending' && ($s->approver_id === $actor || in_array($s->approver_id, $delegators, true)));
        abort_unless($step, 403, 'You are not assigned to current approval step');
        DB::table('approval_steps')->where('id', $step->id)->update(['status' => $decision, 'reason' => $reason, 'acted_by' => $actor, 'acted_at' => now()]);
        if ($decision !== 'approved') {
            DB::table('approval_steps')->where('document_id', $d->id)->where('round', $d->version)->where('status', 'pending')->update(['status' => 'skipped']);
            if ($d->kind === 'pr') {
                BudgetLedger::move($d, $decision, '-'.$d->budget_value, '0', '0', $actor);
            }
            $d->status = $decision;
            $d->save();

            return;
        }
        $same = DB::table('approval_steps')->where('document_id', $d->id)->where('round', $d->version)->where('position', $current)->get();
        $quorum = json_decode($step->snapshot, true)['quorum'];
        if ($same->where('status', 'approved')->count() >= $quorum) {
            DB::table('approval_steps')->where('document_id', $d->id)->where('round', $d->version)->where('position', $current)->where('status', 'pending')->update(['status' => 'skipped']);
        }
        if (! DB::table('approval_steps')->where('document_id', $d->id)->where('round', $d->version)->where('status', 'pending')->exists()) {
            if ($d->kind === 'po') {
                ProcurementLimits::purchaseOrder($d);
                $pr = Procurement::rootPr($d);
                $credit = '0';
                if ($pr) {
                    $reserved = DB::table('budget_ledger')->where('document_id', $pr->id)->sum('reserved_delta');
                    $credit = bccomp((string) $reserved, $d->budget_value, 4) >= 0 ? $d->budget_value : (string) $reserved;
                }
                BudgetLedger::move($d, 'commit', '-'.$credit, $d->budget_value, '0', $actor);
                if ($pr && bccomp($credit, '0', 4) > 0) {
                    DB::table('budget_ledger')->where('document_id', $d->id)->where('action', 'commit')->orderByDesc('id')->limit(1)->update(['reserved_delta' => '0']);
                    DB::table('budget_ledger')->insert(['tenant_id' => $d->tenant_id, 'budget_id' => $d->budget_id, 'document_id' => $pr->id, 'action' => 'convert_to_po', 'reserved_delta' => '-'.$credit, 'committed_delta' => '0', 'actual_delta' => '0', 'actor_id' => $actor, 'created_at' => now()]);
                }
            }
            $d->status = $d->kind === 'tender' ? 'awarded' : 'approved';
            $d->save();
        }
    }
}
