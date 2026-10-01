<?php

namespace App\Http\Controllers;

use App\Domain\Access;
use App\Domain\Idempotency;
use App\Jobs\GenerateExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExportController extends Controller
{
    public function create(Request $r)
    {
        return Idempotency::run($r, function () use ($r) {
            $v = $r->validate(['format' => 'required|in:csv,xlsx', 'kind' => 'nullable|in:pr,tender,po,contract,receipt,return,invoice,credit']);
            $id = DB::table('exports')->insertGetId(['tenant_id' => Access::tenant($r), 'user_id' => $r->user()->id, 'format' => $v['format'], 'filters' => json_encode(['kind' => $v['kind'] ?? null, 'membership' => hash('sha256', json_encode(Access::membership($r)))]), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
            GenerateExport::dispatch($id)->afterCommit();

            return ['id' => $id, 'status' => 'pending'];
        });
    }

    public function list(Request $r)
    {
        return DB::table('exports')->where('tenant_id', Access::tenant($r))->where('user_id', $r->user()->id)->where('expires_at', '>', now())->orderByDesc('id')->get(['id', 'format', 'status', 'error', 'expires_at', 'created_at']);
    }

    public function download(Request $r, int $id)
    {
        $e = DB::table('exports')->where('tenant_id', Access::tenant($r))->where('user_id', $r->user()->id)->find($id);
        abort_unless($e, 404);
        abort_unless($e->status === 'ready' && now()->lt($e->expires_at), 403, 'Export unavailable/expired');
        $m = Access::membership($r);
        $snapshot = json_decode($e->filters, true);
        abort_unless(hash_equals($snapshot['membership'], hash('sha256', json_encode($m))), 403, 'Membership changed; generate a new export'); // Download authorization is live; job output is private.

        return Storage::download($e->key, 'procurement-'.$e->id.'.'.$e->format);
    }
}
