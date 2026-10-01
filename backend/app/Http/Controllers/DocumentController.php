<?php

namespace App\Http\Controllers;

use App\Domain\Access;
use App\Domain\Idempotency;
use App\Domain\Procurement;
use App\Models\Document;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocumentController extends Controller
{
    public function index(Request $r)
    {
        $r->validate(['kind' => 'nullable|in:'.implode(',', Procurement::KINDS), 'search' => 'nullable|string|max:120', 'per_page' => 'nullable|integer|between:1,100']);
        $q = Access::visible(Document::query(), Access::membership($r), $r->user()->id);
        if ($r->filled('kind')) {
            $q->where('kind', $r->input('kind'));
        }if ($r->filled('status')) {
            $q->where('status', $r->input('status'));
        }if ($r->filled('search')) {
            $q->where(fn ($x) => $x->where('title', 'ilike', '%'.$r->input('search').'%')->orWhere('number', 'ilike', '%'.$r->input('search').'%'));
        }

        return $q->orderByDesc('id')->paginate($r->integer('per_page', 20));
    }

    public function show(Request $r, int $id)
    {
        $d = Access::document($r, $id)->load('lines');

        return ['document' => $d, 'approvals' => DB::table('approval_steps')->leftJoin('users', 'users.id', '=', 'approval_steps.approver_id')->where('document_id', $d->id)->select('approval_steps.*', 'users.name as approver_name')->orderBy('round')->orderBy('position')->get(), 'revisions' => DB::table('document_revisions')->where('document_id', $d->id)->select('id', 'version', 'reason', 'created_at')->get(), 'attachments' => DB::table('attachments')->where('document_id', $d->id)->select('id', 'name', 'mime', 'size', 'status')->get()];
    }

    public function store(Request $r)
    {
        return Idempotency::run($r, fn () => Procurement::write($r)->toArray());
    }

    public function update(Request $r, int $id)
    {
        return Idempotency::run($r, fn () => Procurement::write($r, Access::document($r, $id, true))->toArray());
    }

    public function action(Request $r, int $id, string $action)
    {
        return Idempotency::run($r, fn () => Procurement::action($r, Access::document($r, $id, true), $action)->toArray());
    }

    public function pdf(Request $r, int $id)
    {
        $d = Access::document($r, $id)->load('lines');

        return Pdf::loadView('document-pdf', ['d' => $d])->setPaper('a4')->download($d->number.'.pdf');
    }

    public function export(Request $r)
    {
        $rows = Access::visible(Document::query(), Access::membership($r), $r->user()->id)->when($r->filled('kind'), fn ($q) => $q->where('kind', $r->input('kind')))->orderBy('id')->limit(10001)->get(['number', 'kind', 'title', 'status', 'currency', 'total', 'created_at']);
        abort_if($rows->count() > 10000, 422, 'Use asynchronous export for more than 10,000 documents');

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Number', 'Kind', 'Title', 'Status', 'Currency', 'Total', 'Created']);
            foreach ($rows as $row) {
                $v = array_values($row->toArray());
                foreach ($v as &$cell) {
                    if (preg_match('/^[=+@\-\t\r]/', (string) $cell)) {
                        $cell = "'".$cell;
                    }
                }fputcsv($out, $v);
            }fclose($out);
        }, 'procurement.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function dashboard(Request $r)
    {
        $base = Access::visible(Document::query(), Access::membership($r), $r->user()->id);
        $counts = (clone $base)->select('kind', 'status', DB::raw('count(*) as count'))->groupBy('kind', 'status')->get();
        $recent = (clone $base)->latest()->limit(6)->get();
        $pending = DB::table('approval_steps')->join('documents', 'documents.id', '=', 'approval_steps.document_id')->where('approval_steps.tenant_id', Access::tenant($r))->where('approver_id', $r->user()->id)->where('approval_steps.status', 'pending')->where('documents.status', 'pending_approval')->select('documents.id', 'documents.number', 'documents.title', 'documents.total', 'documents.currency', 'approval_steps.position')->get();
        $budgets = [];
        if (! str_starts_with(Access::membership($r)->role, 'vendor')) {
            $q = DB::table('budgets')->where('tenant_id', Access::tenant($r));
            $scope = json_decode(Access::membership($r)->scope, true);
            foreach (['legal_entity_id', 'cost_center_id'] as $f) {
                if (! empty($scope[$f])) {
                    $q->whereIn($f, $scope[$f]);
                }
            }$budgets = $q->get();
        }

        return ['counts' => $counts, 'recent' => $recent, 'pending' => $pending, 'budgets' => $budgets, 'notifications' => DB::table('notifications')->where('tenant_id', Access::tenant($r))->where('user_id', $r->user()->id)->orderByDesc('id')->limit(10)->get(), 'definitions' => ['budget_available' => 'allocated - reserved - committed - actual', 'documents' => 'Documents visible to current membership; no assumed savings']];
    }
}
