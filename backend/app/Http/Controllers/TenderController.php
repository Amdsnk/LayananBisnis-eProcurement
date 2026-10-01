<?php

namespace App\Http\Controllers;

use App\Domain\Access;
use App\Domain\Audit;
use App\Domain\Idempotency;
use App\Domain\Workflow;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenderController extends Controller
{
    private function cipher(): Encrypter
    {
        $key = base64_decode(config('procurement.bid_key'), true);
        abort_unless($key && strlen($key) === 32, 503, 'Sealed bid key not configured');

        return new Encrypter($key, 'AES-256-CBC');
    }

    public function publish(Request $r, int $id)
    {
        return Idempotency::run($r, function () use ($r, $id) {
            $d = Access::document($r, $id, true);
            Access::require(Access::membership($r), 'tender.publish');
            abort_unless($d->kind === 'tender' && $d->status === 'draft' && now()->lt($d->deadline), 409, 'Tender not publishable');
            $this->cipher();
            $v = $r->validate(['vendor_ids' => 'required|array|min:1', 'vendor_ids.*' => 'integer']);
            foreach (array_unique($v['vendor_ids']) as $vid) {
                abort_unless(DB::table('vendors')->where('tenant_id', $d->tenant_id)->where('id', $vid)->where('status', 'approved')->exists(), 422, 'Approved vendor required');
                DB::table('tender_invitations')->insert(['tenant_id' => $d->tenant_id, 'document_id' => $d->id, 'vendor_id' => $vid]);
            }$d->status = 'published';
            $d->save();
            Audit::write($d->tenant_id, $r->user()->id, 'tender.published', 'tender', $d->id);

            return $d->load('lines')->toArray();
        });
    }

    public function bid(Request $r, int $id)
    {
        return Idempotency::run($r, function () use ($r, $id) {
            $d = Access::document($r, $id, true);
            $m = Access::membership($r);
            Access::require($m, 'bid.submit');
            abort_unless($d->kind === 'tender' && $d->status === 'published' && now()->lt($d->deadline), 409, 'Submission deadline passed or tender closed');
            abort_unless(DB::table('tender_invitations')->where('document_id', $d->id)->where('vendor_id', $m->vendor_id)->exists(), 403);
            $v = $r->validate(['technical' => 'required|array', 'technical.summary' => 'required|string|max:10000', 'commercial' => 'required|array', 'commercial.lines' => 'required|array|min:1|max:200', 'commercial.lines.*.source_line_id' => 'required|integer', 'commercial.lines.*.quantity' => 'required|numeric|gt:0', 'commercial.lines.*.unit_price' => 'required|numeric|min:0', 'commercial.currency' => 'required|string|size:3', 'commercial.lead_days' => 'required|integer|min:0|max:3650', 'commercial.payment_terms' => 'required|string|max:200']);
            abort_unless($v['commercial']['currency'] === $d->currency, 422, 'Bid currency must match event');
            $seen = [];
            foreach ($v['commercial']['lines'] as $l) {
                abort_if(in_array($l['source_line_id'], $seen), 422, 'Duplicate bid line');
                $seen[] = $l['source_line_id'];
                $sl = $d->lines->find($l['source_line_id']);
                abort_unless($sl && bccomp((string) $l['quantity'], $sl->quantity, 4) <= 0, 422, 'Invalid bid quantity/line');
            }DB::table('bids')->where('document_id', $d->id)->where('vendor_id', $m->vendor_id)->where('status', 'submitted')->update(['status' => 'superseded']);
            $rev = (int) DB::table('bids')->where('document_id', $d->id)->where('vendor_id', $m->vendor_id)->max('revision') + 1;
            $hash = hash('sha256', json_encode($v));
            $bid = DB::table('bids')->insertGetId(['tenant_id' => $d->tenant_id, 'document_id' => $d->id, 'vendor_id' => $m->vendor_id, 'revision' => $rev, 'technical_cipher' => $this->cipher()->encryptString(json_encode($v['technical'])), 'commercial_cipher' => $this->cipher()->encryptString(json_encode($v['commercial'])), 'hash' => $hash, 'status' => 'submitted', 'submitted_at' => now()]);
            Audit::write($d->tenant_id, $r->user()->id, 'bid.submitted', 'bid', $bid, ['revision' => $rev, 'hash' => $hash]);

            return ['receipt_id' => $bid, 'revision' => $rev, 'hash' => $hash, 'submitted_at' => now()->toISOString()];
        });
    }

    public function bids(Request $r, int $id)
    {
        $d = Access::document($r, $id);
        abort_unless($d->kind === 'tender', 422);
        $m = Access::membership($r);
        $q = DB::table('bids')->where('document_id', $d->id)->where('status', 'submitted');
        if (str_starts_with($m->role, 'vendor')) {
            $q->where('vendor_id', $m->vendor_id);
        } else {
            abort_unless(in_array($r->user()->id, array_merge($d->data['evaluator_ids'] ?? [], $d->data['opening_user_ids'] ?? []), true) || in_array($m->role, ['procurement', 'manager', 'auditor']), 403);
        }
        $technical = in_array($d->status, ['technical_open', 'commercial_open', 'awarded', 'closed']);
        $commercial = in_array($d->status, ['commercial_open', 'awarded', 'closed']);

        return $q->get()->map(function ($b) use ($m, $technical, $commercial) {
            $own = str_starts_with($m->role, 'vendor');

            return ['id' => $b->id, 'vendor_id' => $b->vendor_id, 'revision' => $b->revision, 'hash' => $b->hash, 'submitted_at' => $b->submitted_at, 'technical' => ($own || $technical) ? json_decode($this->cipher()->decryptString($b->technical_cipher), true) : null, 'commercial' => ($own || $commercial) ? json_decode($this->cipher()->decryptString($b->commercial_cipher), true) : null, 'sealed' => ! $technical];
        });
    }

    public function open(Request $r, int $id)
    {
        return Idempotency::run($r, function () use ($r, $id) {
            $d = Access::document($r, $id, true);
            Access::require(Access::membership($r), 'tender.open');
            $v = $r->validate(['envelope' => 'required|in:technical,commercial']);
            abort_unless(now()->gte($d->deadline), 409, 'Tender deadline not passed');
            abort_unless(in_array($r->user()->id, $d->data['opening_user_ids'] ?? [], true), 403, 'Not a panel member');
            $e = $v['envelope'];
            abort_unless(($e === 'technical' && $d->status === 'published') || ($e === 'commercial' && $d->status === 'technical_open'), 409, 'Invalid opening phase');
            if ($e === 'commercial' && (int) $d->data['envelopes'] === 2) {
                $vendors = DB::table('bids')->where('document_id', $d->id)->where('status', 'submitted')->pluck('vendor_id');
                foreach ($vendors as $vid) {
                    abort_unless(DB::table('evaluations')->where('document_id', $d->id)->where('vendor_id', $vid)->where('conflict_declared', false)->where('phase', 'technical')->count() >= count($d->data['evaluator_ids']), 409, 'Technical evaluations must be completed first');
                }
            }DB::table('opening_votes')->insertOrIgnore(['document_id' => $d->id, 'user_id' => $r->user()->id, 'envelope' => $e, 'created_at' => now()]);
            $votes = DB::table('opening_votes')->where('document_id', $d->id)->where('envelope', $e)->count();
            if ($votes >= ($d->data['opening_quorum'] ?? 2)) {
                $d->status = $e === 'technical' ? 'technical_open' : 'commercial_open';
                if ($e === 'technical' && (int) $d->data['envelopes'] === 1) {
                    $d->status = 'commercial_open';
                }$d->opened_at = now();
                $d->save();
            }Audit::write($d->tenant_id, $r->user()->id, 'tender.opening_vote', 'tender', $d->id, ['envelope' => $e, 'votes' => $votes, 'status' => $d->status]);

            return ['status' => $d->status, 'votes' => $votes, 'quorum' => $d->data['opening_quorum']];
        });
    }

    public function evaluate(Request $r, int $id)
    {
        return Idempotency::run($r, function () use ($r, $id) {
            $d = Access::document($r, $id, true);
            Access::require(Access::membership($r), 'tender.evaluate');
            abort_unless(in_array($r->user()->id, $d->data['evaluator_ids'] ?? [], true), 403, 'Not an assigned evaluator');
            abort_unless(in_array($d->status, ['technical_open', 'commercial_open']), 409, 'Not in evaluation phase');
            $v = $r->validate(['vendor_id' => 'required|integer', 'scores' => 'required|array', 'comment' => 'required|string|min:5|max:5000', 'conflict_declared' => 'required|boolean']);
            abort_if($v['conflict_declared'], 422, 'Conflicted evaluator cannot score');
            abort_unless(DB::table('bids')->where('document_id', $d->id)->where('vendor_id', $v['vendor_id'])->where('status', 'submitted')->exists(), 422, 'No submitted bid');
            $phase = $d->status === 'technical_open' ? 'technical' : 'commercial';
            $score = '0';
            $weight = '0';
            foreach ($d->data['criteria'] as $i => $criterion) {
                if (($criterion['phase'] ?? 'technical') !== $phase) {
                    continue;
                }$weight = bcadd($weight, (string) $criterion['weight'], 4);
                $n = $v['scores'][$i] ?? null;
                abort_unless(is_numeric($n) && $n >= 0 && $n <= 100, 422, 'Every criterion needs a 0–100 score');
                $score = bcadd($score, bcdiv(bcmul((string) $n, (string) $criterion['weight'], 8), '100', 4), 4);
            }if (bccomp($weight, '0', 4) > 0) {
                $score = bcdiv(bcmul($score, '100', 8), $weight, 4);
            }DB::table('evaluations')->updateOrInsert(['phase' => $phase, 'document_id' => $d->id, 'vendor_id' => $v['vendor_id'], 'evaluator_id' => $r->user()->id], ['tenant_id' => $d->tenant_id, 'scores' => json_encode($v['scores']), 'score' => $score, 'comment' => $v['comment'], 'conflict_declared' => false, 'created_at' => now()]);
            Audit::write($d->tenant_id, $r->user()->id, 'tender.evaluated', 'tender', $d->id, ['vendor_id' => $v['vendor_id'], 'phase' => $d->status]);

            return ['score' => $score];
        });
    }

    public function award(Request $r, int $id)
    {
        return Idempotency::run($r, function () use ($r, $id) {
            $d = Access::document($r, $id, true);
            Access::require(Access::membership($r), 'tender.award');
            abort_unless($d->status === 'commercial_open', 409, 'Commercial opening required');
            abort_if($d->created_by === $r->user()->id, 403, 'Tender maker cannot award');
            $v = $r->validate(['vendor_ids' => 'required|array|min:1', 'reason' => 'required|string|min:10']);
            foreach ($v['vendor_ids'] as $vid) {
                $count = DB::table('evaluations')->where('document_id', $d->id)->where('vendor_id', $vid)->where('phase', 'commercial')->count();
                abort_unless($count >= count($d->data['evaluator_ids']), 409, 'Assigned evaluations incomplete');
            }$d->data = array_merge($d->data, ['awarded_vendor_ids' => array_map('intval', $v['vendor_ids']), 'award_reason' => $v['reason'], 'award_proposed_by' => $r->user()->id]);
            $d->save();
            Workflow::start($d, $r->user()->id);
            Audit::write($d->tenant_id, $r->user()->id, 'tender.awarded', 'tender', $d->id, ['vendor_ids' => $v['vendor_ids'], 'reason' => $v['reason']]);

            return $d->toArray();
        });
    }
}
