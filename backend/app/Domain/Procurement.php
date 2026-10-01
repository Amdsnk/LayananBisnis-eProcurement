<?php

namespace App\Domain;

use App\Models\Document;
use App\Models\DocumentLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Procurement
{
    const KINDS = ['pr', 'tender', 'contract', 'po', 'receipt', 'return', 'invoice', 'credit'];

    public static function rootPr(Document $d): ?Document
    {
        $seen = [];
        while ($d->source_id) {
            if (in_array($d->id, $seen)) {
                abort(409, 'Source cycle');
            }$seen[] = $d->id;
            $d = Document::where('tenant_id', $d->tenant_id)->findOrFail($d->source_id);
            if ($d->kind === 'pr') {
                return $d;
            }
        }

        return $d->kind === 'pr' ? $d : null;
    }

    public static function validate($r): array
    {
        return $r->validate(['kind' => 'required|in:'.implode(',', self::KINDS), 'title' => 'required|string|max:180', 'legal_entity_id' => 'required|integer', 'cost_center_id' => 'required|integer', 'budget_id' => 'nullable|integer', 'vendor_id' => 'nullable|integer', 'source_id' => 'nullable|integer', 'currency' => 'required|string|size:3', 'exchange_rate' => 'required|numeric|gt:0|max:1000000000', 'needed_at' => 'nullable|date', 'deadline' => 'nullable|date', 'lines' => 'required|array|min:1|max:200', 'lines.*.description' => 'required|string|max:500', 'lines.*.quantity' => 'required|numeric|gt:0|max:1000000000', 'lines.*.unit_price' => 'required|numeric|min:0|max:1000000000', 'lines.*.uom' => 'required|string|max:20', 'lines.*.discount_percent' => 'nullable|numeric|between:0,100', 'lines.*.tax_percent' => 'nullable|numeric|between:0,100', 'lines.*.source_line_id' => 'nullable|integer', 'data' => 'nullable|array']);
    }

    public static function write($r, ?Document $d = null): Document
    {
        $v = self::validate($r);
        $m = Access::membership($r);
        $tenant = $m->tenant_id;
        $actor = $r->user()->id;
        Access::require($m, $v['kind'].($d ? '.edit' : '.create'));
        $scope = json_decode($m->scope ?? '{}', true);
        foreach (['legal_entity_id', 'cost_center_id'] as $f) {
            abort_if(! empty($scope[$f]) && ! in_array($v[$f], $scope[$f]), 403, 'Outside your assigned scope');
        }
        foreach (['legal_entity_id' => 'legal_entities', 'cost_center_id' => 'cost_centers', 'budget_id' => 'budgets', 'vendor_id' => 'vendors'] as $f => $table) {
            if (! empty($v[$f])) {
                abort_unless(DB::table($table)->where('tenant_id', $tenant)->where('id', $v[$f])->exists(), 422, 'Invalid '.$f);
            }
        }
        if ($d) {
            abort_unless($d->created_by === $actor || in_array($m->role, ['procurement', 'manager', 'legal', 'finance', 'receiver']), 403);
            abort_unless(in_array($d->status, ['draft', 'returned', 'rejected']), 409, 'Final/submitted document needs a revision');
            abort_unless((int) $r->input('version') === $d->version, 409, 'Document changed; reload');
            abort_unless($v['kind'] === $d->kind, 422, 'Cannot change document kind');
            DB::table('document_revisions')->insert(['tenant_id' => $tenant, 'document_id' => $d->id, 'version' => $d->version, 'snapshot' => json_encode($d->load('lines')->toArray()), 'actor_id' => $actor, 'reason' => 'Draft revision', 'created_at' => now(), 'updated_at' => now()]);
            $d->version++;
        } else {
            $d = new Document(['tenant_id' => $tenant, 'created_by' => $actor, 'number' => strtoupper($v['kind']).'-'.now()->format('Ymd').'-'.strtoupper(Str::random(10))]);
        }
        $source = null;
        if (! empty($v['source_id'])) {
            $source = Access::document($r, $v['source_id'], true);
            abort_if($source->id === $d->id, 422, 'Invalid source');
            $allowed = ['tender' => ['pr'], 'contract' => ['tender'], 'po' => ['pr', 'tender', 'contract'], 'receipt' => ['po'], 'return' => ['receipt'], 'invoice' => ['po'], 'credit' => ['invoice']];
            abort_unless(in_array($source->kind, $allowed[$v['kind']] ?? [], true), 422, 'Invalid source document kind');
            $statuses = ['pr' => ['approved', 'partially_sourced'], 'tender' => ['awarded'], 'contract' => ['active'], 'po' => ['issued', 'acknowledged', 'partially_received', 'received'], 'receipt' => ['posted'], 'invoice' => ['matched', 'approved', 'paid']];
            abort_unless(in_array($source->status, $statuses[$source->kind] ?? [], true), 409, 'Source not eligible');
            foreach (['legal_entity_id', 'cost_center_id', 'budget_id'] as $f) {
                abort_unless(($v[$f] ?? null) == $source->$f, 422, 'Source scope/budget must match');
            }
            abort_unless($source->currency === $v['currency'] && bccomp($source->exchange_rate, (string) $v['exchange_rate'], 8) === 0, 422, 'Source currency/rate snapshot must match');
            if ($source->vendor_id) {
                abort_unless(($v['vendor_id'] ?? null) == $source->vendor_id, 422, 'Source vendor must match');
            }
            if ($source->kind === 'tender') {
                abort_unless(in_array((int) ($v['vendor_id'] ?? 0), $source->data['awarded_vendor_ids'] ?? [], true), 422, 'Vendor was not awarded');
            }
        }
        if (in_array($v['kind'], ['po', 'receipt', 'return', 'invoice', 'credit', 'contract'])) {
            abort_unless($source, 422, 'Source document required');
        }
        if (in_array($v['kind'], ['po', 'pr'])) {
            abort_unless(! empty($v['budget_id']), 422, 'Budget required');
        }
        if (in_array($v['kind'], ['po', 'invoice', 'contract'])) {
            abort_unless(! empty($v['vendor_id']) && DB::table('vendors')->where('tenant_id', $tenant)->where('id', $v['vendor_id'])->where('status', 'approved')->exists(), 422, 'Approved vendor required');
        }
        if (str_starts_with($m->role, 'vendor')) {
            abort_unless($v['kind'] === 'invoice' && ($v['vendor_id'] ?? null) == $m->vendor_id && $source?->vendor_id === $m->vendor_id, 403);
        }
        $data = $v['data'] ?? [];
        // Only business input keys can be authored; status/payment/ledger fields are never client writable.
        $keys = ['justification', 'specification', 'delivery_address', 'payment_terms', 'invoice_number', 'invoice_date', 'due_date', 'service', 'method', 'envelopes', 'criteria', 'evaluator_ids', 'opening_user_ids', 'opening_quorum', 'ends_at', 'starts_at', 'sla', 'milestones', 'tolerance_percent', 'comment'];
        $data = array_intersect_key($data, array_flip($keys));
        if ($v['kind'] === 'tender') {
            abort_unless($source, 422, 'Tender must reference approved PR');
            abort_unless(! empty($v['deadline']) && now()->lt($v['deadline']), 422, 'Future deadline required');
            $data['method'] = $data['method'] ?? 'rfq';
            abort_unless(in_array($data['method'], ['rfq', 'rfp', 'direct']), 422, 'Unknown procurement method');
            $criteria = $data['criteria'] ?? [['name' => 'Technical', 'weight' => 60], ['name' => 'Commercial', 'weight' => 40]];
            abort_unless(count($criteria) > 0 && count($criteria) <= 20 && abs(array_sum(array_column($criteria, 'weight')) - 100) < 0.0001, 422, 'Criterion weights must total 100');
            foreach ($criteria as $c) {
                abort_unless(isset($c['name'],$c['weight']) && $c['weight'] > 0, 422, 'Invalid criteria');
            }foreach ($criteria as $i => &$c) {
                $c['phase'] = $c['phase'] ?? ($i === 0 ? 'technical' : 'commercial');
                abort_unless(in_array($c['phase'], ['technical', 'commercial']), 422, 'Invalid criterion phase');
            }unset($c);
            $data['criteria'] = $criteria;
            $data['envelopes'] = $data['envelopes'] ?? 2;
            abort_unless(in_array((int) $data['envelopes'], [1, 2]), 422, 'Invalid envelope count');
            $data['opening_quorum'] = max(1, (int) ($data['opening_quorum'] ?? 2));
            foreach (['evaluator_ids', 'opening_user_ids'] as $f) {
                $ids = array_values(array_unique($data[$f] ?? []));
                abort_unless(count($ids) > 0, 422, 'Assigned '.$f.' required');
                foreach ($ids as $uid) {
                    $member = DB::table('memberships')->where('tenant_id', $tenant)->where('user_id', $uid)->first();
                    abort_unless($member && Access::can($member, $f === 'evaluator_ids' ? 'tender.evaluate' : 'tender.open'), 422, 'Invalid '.$f);
                }$data[$f] = $ids;
            }abort_unless($data['opening_quorum'] <= count($data['opening_user_ids']), 422, 'Quorum exceeds opening panel');
        }
        if ($v['kind'] === 'invoice') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$tenant.':invoice:'.$v['legal_entity_id'].':'.$v['vendor_id']]);
            abort_unless(! empty($data['invoice_number']), 422, 'Invoice number required');
            $n = mb_strtoupper(preg_replace('/\s+/', '', trim($data['invoice_number'])));
            $data['normalized_number'] = $n;
            abort_if(Document::where('tenant_id', $tenant)->where('kind', 'invoice')->where('legal_entity_id', $v['legal_entity_id'])->where('vendor_id', $v['vendor_id'])->where('data->normalized_number', $n)->when($d->exists, fn ($q) => $q->where('id', '!=', $d->id))->exists(), 409, 'Duplicate invoice number');
        }
        $lines = $v['lines'];
        $refs = array_filter(array_column($lines, 'source_line_id'));
        abort_unless(count($refs) === count(array_unique($refs)), 422, 'Duplicate source lines are prohibited');
        unset($v['lines'],$v['data']);
        $d->fill($v);
        $d->data = $data;
        $d->status = 'draft';
        $d->total = '0';
        $d->save();
        if ($d->exists) {
            $d->lines()->delete();
        }
        foreach ($lines as $line) {
            if ($source && in_array($d->kind, ['po', 'tender', 'contract', 'receipt', 'return', 'invoice', 'credit'])) {
                abort_unless(! empty($line['source_line_id']), 422, 'Source line required');
            }
            if (! empty($line['source_line_id'])) {
                abort_unless($source, 422, 'Source required');
                $sl = DocumentLine::where('tenant_id', $tenant)->where('document_id', $source->id)->find($line['source_line_id']);
                abort_unless($sl, 422, 'Invalid source line');
                if (in_array($d->kind, ['receipt', 'return', 'credit'])) {
                    $line['unit_price'] = $sl->unit_price;
                    $line['tax_percent'] = $sl->tax_percent;
                    $line['discount_percent'] = $sl->discount_percent;
                }abort_if($sl->uom !== $line['uom'], 422, 'Source unit must match');
            }
            $computed = Money::line($line);
            $d->lines()->create(array_merge(array_intersect_key($line, array_flip(['description', 'uom', 'quantity', 'unit_price', 'discount_percent', 'tax_percent', 'source_line_id'])), $computed, ['tenant_id' => $tenant]));
            $d->total = Money::add($d->total, $computed['total']);
        }
        if ($d->budget_id) {
            $b = DB::table('budgets')->where('tenant_id', $tenant)->where('id', $d->budget_id)->first();
            $d->budget_value = Money::mul($d->total, $d->exchange_rate);
            abort_unless($b->legal_entity_id === $d->legal_entity_id && $b->cost_center_id === $d->cost_center_id, 422, 'Budget scope mismatch');
            if ($d->currency === $b->currency) {
                abort_unless(bccomp($d->exchange_rate, '1', 8) === 0, 422, 'Same currency exchange rate must be 1');
            }
        }
        $d->save();
        Audit::write($tenant, $actor, 'document.saved', $d->kind, $d->id, ['number' => $d->number, 'version' => $d->version]);

        return $d->load('lines');
    }

    public static function action($r, Document $d, string $action): Document
    {
        $m = Access::membership($r);
        $actor = $r->user()->id;
        abort_unless((int) $r->input('version') === $d->version, 409, 'Document changed; reload');
        $reason = (string) $r->input('reason', '');
        if (in_array($action, ['approve', 'reject', 'return'])) {
            Access::require($m, 'approve');
            abort_unless(strlen($reason) >= 3, 422, 'Reason required');
            Workflow::decide($d, $actor, ['approve' => 'approved', 'reject' => 'rejected', 'return' => 'returned'][$action], $reason);
        } elseif ($action === 'submit') {
            Access::require($m, $d->kind.'.submit');
            abort_unless($d->status === 'draft' || ($d->kind === 'invoice' && $d->status === 'matched'), 409, 'Not draft/matched');
            abort_if(DB::table('attachments')->where('document_id', $d->id)->where('status', '!=', 'clean')->exists(), 409, 'All attachments must pass malware scanning before submission');
            if ($d->kind === 'pr') {
                BudgetLedger::move($d, 'reserve', $d->budget_value, '0', '0', $actor);
            }
            if ($d->kind === 'invoice') {
                self::match($d);
                abort_unless($d->status === 'matched', 409, 'Invoice mismatch must be resolved');
            }
            Workflow::start($d, $actor);
        } elseif ($action === 'issue') {
            Access::require($m, 'po.issue');
            abort_unless($d->kind === 'po' && $d->status === 'approved', 409);
            $d->status = 'issued';
            $d->save();
            self::emit($d, 'erp.po', ['document_id' => $d->id]);
        } elseif ($action === 'acknowledge') {
            Access::require($m, 'po.acknowledge');
            abort_unless($d->kind === 'po' && $d->status === 'issued' && $d->vendor_id === $m->vendor_id, 409);
            $d->status = 'acknowledged';
            $d->save();
        } elseif ($action === 'post') {
            Access::require($m, $d->kind.'.post');
            abort_unless($d->status === 'draft', 409);
            if (in_array($d->kind, ['receipt', 'return'])) {
                self::receive($d, $actor);
            } elseif ($d->kind === 'credit') {
                self::credit($d, $actor);
            } else {
                abort(422, 'Unsupported posting');
            }
        } elseif ($action === 'match') {
            Access::require($m, 'invoice.match');
            abort_unless($d->kind === 'invoice' && in_array($d->status, ['draft', 'mismatch']), 409);
            self::match($d);
        } elseif ($action === 'pay') {
            Access::require($m, 'invoice.pay');
            abort_unless($d->kind === 'invoice' && $d->status === 'approved', 409);
            abort_unless(strlen($r->input('payment_reference', '')) >= 3, 422, 'Payment reference required');
            abort_if($d->created_by === $actor, 403, 'Invoice maker cannot record payment');
            BudgetLedger::move($d, 'actual', '0', '-'.$d->budget_value, $d->budget_value, $actor);
            $d->data = array_merge($d->data, ['payment_reference' => $r->input('payment_reference'), 'paid_at' => now()->toISOString(), 'payment_source' => 'finance_manual']);
            $d->status = 'paid';
            $d->save();
        } elseif ($action === 'activate') {
            Access::require($m, 'contract.activate');
            abort_unless($d->kind === 'contract' && $d->status === 'approved', 409);
            $d->status = 'active';
            $d->save();
        } elseif ($action === 'cancel') {
            abort_unless($actor === $d->created_by || Access::can($m, $d->kind.'.cancel') || $m->role === 'manager', 403);
            abort_unless(strlen($reason) >= 5, 422, 'Cancellation reason required');
            abort_unless(in_array($d->status, ['draft', 'pending_approval', 'approved', 'issued', 'acknowledged', 'published']), 409, 'Cannot cancel current state');
            abort_if(Document::where('tenant_id', $d->tenant_id)->where('source_id', $d->id)->whereNotIn('status', ['cancelled', 'draft'])->exists(), 409, 'Downstream transactions exist');
            if ($d->kind === 'pr') {
                $net = (string) DB::table('budget_ledger')->where('document_id', $d->id)->sum('reserved_delta');
                if (bccomp($net, '0', 4) > 0) {
                    BudgetLedger::move($d, 'release', '-'.$net, '0', '0', $actor);
                }
            }
            if ($d->kind === 'po' && in_array($d->status, ['approved', 'issued', 'acknowledged'])) {
                BudgetLedger::move($d, 'release', '0', '-'.$d->budget_value, '0', $actor);
            }
            DB::table('approval_steps')->where('document_id', $d->id)->where('status', 'pending')->update(['status' => 'cancelled']);
            $d->data = array_merge($d->data, ['cancellation_reason' => $reason]);
            $d->status = 'cancelled';
            $d->save();
        } else {
            abort(422, 'Unsupported action');
        }
        Audit::write($d->tenant_id, $actor, 'document.'.$action, $d->kind, $d->id, ['status' => $d->status, 'reason' => $reason]);
        self::emit($d, 'notification', ['title' => $d->number.' • '.$d->status, 'user_id' => $d->created_by]);

        return $d->load('lines');
    }

    public static function receive(Document $d, int $actor): void
    {
        $source = Document::where('tenant_id', $d->tenant_id)->lockForUpdate()->findOrFail($d->source_id);
        abort_unless(($d->kind === 'receipt' && $source->kind === 'po' && in_array($source->status, ['issued', 'acknowledged', 'partially_received'])) || ($d->kind === 'return' && $source->kind === 'receipt' && $source->status === 'posted'), 409, 'Source cannot be received/returned');
        foreach ($d->lines as $line) {
            $sl = DocumentLine::where('tenant_id', $d->tenant_id)->lockForUpdate()->findOrFail($line->source_line_id);
            $used = DB::table('document_lines as l')->join('documents as d', 'd.id', '=', 'l.document_id')->where('l.source_line_id', $sl->id)->where('d.kind', $d->kind)->where('d.status', 'posted')->sum('l.quantity');
            abort_if(bccomp(Money::add((string) $used, $line->quantity), $sl->quantity, 4) > 0, 409, 'Quantity exceeds remaining source quantity');
        }
        $d->status = 'posted';
        $d->save();
        if ($d->kind === 'receipt') {
            $complete = true;
            foreach ($source->lines as $sl) {
                $received = DB::table('document_lines as l')->join('documents as d', 'd.id', '=', 'l.document_id')->where('l.source_line_id', $sl->id)->where('d.kind', 'receipt')->where('d.status', 'posted')->sum('l.quantity');
                if (bccomp((string) $received, $sl->quantity, 4) < 0) {
                    $complete = false;
                }
            }$source->status = $complete ? 'received' : 'partially_received';
            $source->save();
        } else {
            $po = Document::where('tenant_id', $d->tenant_id)->lockForUpdate()->findOrFail($source->source_id);
            abort_if(Document::where('source_id', $po->id)->where('kind', 'invoice')->whereIn('status', ['matched', 'pending_approval', 'approved', 'paid'])->exists(), 409, 'Return needs credit/reconciliation before invoiced receipt reversal');
            $po->status = 'partially_received';
            $po->save();
        }
        self::emit($d, 'erp.receipt', ['document_id' => $d->id]);
    }

    public static function match(Document $d): void
    {
        $po = Document::where('tenant_id', $d->tenant_id)->lockForUpdate()->findOrFail($d->source_id);
        abort_unless($po->kind === 'po' && in_array($po->status, ['issued', 'acknowledged', 'partially_received', 'received']), 409, 'PO not eligible for matching');
        $issues = [];
        foreach ($d->lines as $line) {
            $sl = DocumentLine::where('tenant_id', $d->tenant_id)->where('document_id', $po->id)->findOrFail($line->source_line_id);
            $received = (string) DB::table('document_lines as l')->join('documents as d', 'd.id', '=', 'l.document_id')->where('l.source_line_id', $sl->id)->where('d.kind', 'receipt')->where('d.status', 'posted')->sum('l.quantity');
            $returns = (string) DB::table('document_lines as rl')->join('documents as rd', 'rd.id', '=', 'rl.document_id')->join('document_lines as received', 'received.id', '=', 'rl.source_line_id')->where('received.source_line_id', $sl->id)->where('rd.kind', 'return')->where('rd.status', 'posted')->sum('rl.quantity');
            $received = Money::sub($received, $returns);
            $already = (string) DB::table('document_lines as l')->join('documents as d', 'd.id', '=', 'l.document_id')->where('l.source_line_id', $sl->id)->where('d.kind', 'invoice')->whereIn('d.status', ['matched', 'pending_approval', 'approved', 'paid'])->where('d.id', '!=', $d->id)->sum('l.quantity');
            if (bccomp(Money::add($already, $line->quantity), $received, 4) > 0) {
                $issues[] = ['line_id' => $line->id, 'code' => 'QUANTITY_NOT_RECEIVED', 'received' => $received, 'allocated' => $already];
            }
            foreach (['unit_price', 'discount_percent', 'tax_percent'] as $f) {
                if (bccomp($line->$f, $sl->$f, 4) !== 0) {
                    $issues[] = ['line_id' => $line->id, 'code' => 'PRICE_OR_TAX_MISMATCH', 'field' => $f];
                }
            }
        }
        $d->status = count($issues) ? 'mismatch' : 'matched';
        $d->data = array_merge($d->data, ['match_issues' => $issues, 'matched_at' => count($issues) ? null : now()->toISOString()]);
        $d->save();
    }

    public static function credit(Document $d, int $actor): void
    {
        $invoice = Document::where('tenant_id', $d->tenant_id)->lockForUpdate()->findOrFail($d->source_id);
        abort_unless($invoice->kind === 'invoice' && $invoice->status === 'paid', 409, 'Credit baseline requires paid invoice');
        $sum = (string) Document::where('source_id', $invoice->id)->where('kind', 'credit')->where('status', 'posted')->sum('budget_value');
        abort_if(bccomp(Money::add($sum, $d->budget_value), $invoice->budget_value, 4) > 0, 409, 'Credit exceeds invoice');
        foreach ($d->lines as $l) {
            $sl = DocumentLine::findOrFail($l->source_line_id);
            $used = (string) DB::table('document_lines as l')->join('documents as d', 'd.id', '=', 'l.document_id')->where('l.source_line_id', $sl->id)->where('d.kind', 'credit')->where('d.status', 'posted')->sum('l.quantity');
            abort_if(bccomp(Money::add($used, $l->quantity), $sl->quantity, 4) > 0, 409, 'Credit quantity exceeds invoice');
        }BudgetLedger::move($d, 'credit', '0', '0', '-'.$d->budget_value, $actor);
        $d->status = 'posted';
        $d->save();
    }

    public static function emit(Document $d, string $type, array $payload): void
    {
        DB::table('outbox')->insert(['tenant_id' => $d->tenant_id, 'type' => $type, 'payload' => json_encode($payload), 'status' => 'pending', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
