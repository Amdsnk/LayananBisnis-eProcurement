<?php

namespace App\Domain;

use App\Models\Document;
use Illuminate\Support\Facades\DB;

class Access
{
    const ROLES = ['admin', 'requester', 'approver', 'procurement', 'manager', 'evaluator', 'legal', 'receiver', 'finance', 'auditor', 'vendor_admin', 'vendor_staff'];

    const RIGHTS = [
        'admin' => ['master', 'users', 'policy', 'vendor.manage', 'budget.manage', 'integration.manage'],
        'requester' => ['pr.create', 'pr.edit', 'pr.submit', 'plan.create'],
        'approver' => ['approve'],
        'procurement' => ['pr.read', 'tender.create', 'tender.edit', 'tender.publish', 'tender.open', 'tender.award', 'po.create', 'po.edit', 'po.submit', 'po.issue', 'vendor.manage', 'plan.create'],
        'manager' => ['approve', 'pr.read', 'tender.create', 'tender.edit', 'tender.publish', 'tender.open', 'tender.award', 'po.create', 'po.edit', 'po.submit', 'po.issue', 'po.cancel', 'contract.read', 'vendor.manage', 'budget.manage'],
        'evaluator' => ['tender.evaluate'],
        'legal' => ['contract.create', 'contract.edit', 'contract.submit', 'contract.activate'],
        'receiver' => ['receipt.create', 'receipt.edit', 'receipt.post', 'return.create', 'return.edit', 'return.post', 'po.read'],
        'finance' => ['invoice.create', 'invoice.edit', 'invoice.match', 'invoice.submit', 'invoice.pay', 'credit.create', 'credit.edit', 'credit.post', 'budget.read', 'po.read'],
        'auditor' => ['audit.read', 'all.read'],
        'vendor_admin' => ['bid.submit', 'po.acknowledge', 'invoice.create', 'invoice.edit', 'invoice.submit', 'vendor.change'],
        'vendor_staff' => ['bid.submit', 'po.acknowledge', 'invoice.create', 'invoice.edit', 'invoice.submit']];

    public static function membership($request): object
    {
        return $request->attributes->get('membership');
    }

    public static function tenant($request): int
    {
        return (int) self::membership($request)->tenant_id;
    }

    public static function can(object $m, string $right): bool
    {
        return in_array($right, self::RIGHTS[$m->role] ?? [], true);
    }

    public static function require(object $m, string $right): void
    {
        abort_unless(self::can($m, $right), 403, 'Permission denied');
    }

    public static function visible($q, object $m, int $uid)
    {
        $q->where('tenant_id', $m->tenant_id);
        $scope = json_decode($m->scope ?? '{}', true);
        foreach (['legal_entity_id', 'cost_center_id'] as $field) {
            if (! empty($scope[$field])) {
                $q->whereIn($field, $scope[$field]);
            }
        }
        if (str_starts_with($m->role, 'vendor')) {
            $q->where(function ($s) use ($m, $uid) {
                $s->where(function ($a) use ($m, $uid) {
                    $a->where('vendor_id', $m->vendor_id)->whereIn('kind', ['po', 'invoice', 'credit', 'contract'])->where(function ($x) use ($uid) {
                        $x->whereNotIn('status', ['draft', 'pending_approval'])->orWhere('created_by', $uid);
                    });
                })->orWhere(function ($a) use ($m) {
                    $a->where('kind', 'tender')->whereIn('status', ['published', 'technical_open', 'commercial_open', 'awarded', 'closed'])->whereIn('id', DB::table('tender_invitations')->where('tenant_id', $m->tenant_id)->where('vendor_id', $m->vendor_id)->select('document_id'));
                });
            });
        } elseif (in_array($m->role, ['requester', 'approver'])) {
            $q->where(function ($s) use ($uid, $m) {
                $s->where('created_by', $uid)->orWhereIn('id', DB::table('approval_steps')->where('tenant_id', $m->tenant_id)->where('approver_id', $uid)->select('document_id'));
                $delegators = DB::table('delegations')->where('tenant_id', $m->tenant_id)->where('to_user_id', $uid)->where('starts_at', '<=', now())->where('ends_at', '>', now())->pluck('from_user_id');
                $s->orWhereIn('id', DB::table('approval_steps')->where('tenant_id', $m->tenant_id)->whereIn('approver_id', $delegators)->select('document_id'));
            });
        } elseif ($m->role === 'evaluator') {
            $q->where('kind', 'tender')->whereRaw("data->'evaluator_ids' @> ?::jsonb", [json_encode([$uid])]);
        }

        return $q;
    }

    public static function document($request, int $id, bool $lock = false): Document
    {
        $q = self::visible(Document::query(), self::membership($request), $request->user()->id);
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->findOrFail($id);
    }
}
