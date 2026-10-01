<?php

namespace App\Http\Controllers;

use App\Domain\Access;
use App\Domain\Audit;
use App\Jobs\ScanAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentController extends Controller
{
    public function upload(Request $r, int $id)
    {
        $d = Access::document($r, $id);
        $m = Access::membership($r);
        abort_unless($d->status === 'draft' && Access::can($m, $d->kind.'.edit') && ($d->created_by === $r->user()->id || ! str_starts_with($m->role, 'vendor')), 403, 'Draft editor only');
        $r->validate(['file' => 'required|file|max:25600|mimes:pdf,png,jpg,jpeg,txt,csv,xlsx,docx']);
        $f = $r->file('file');
        $key = 'quarantine/'.$d->tenant_id.'/'.Str::uuid();
        Storage::put($key, file_get_contents($f->getRealPath()));
        try {
            $aid = DB::transaction(function () use ($r, $d, $f, $key) {
                $aid = DB::table('attachments')->insertGetId(['tenant_id' => $d->tenant_id, 'document_id' => $d->id, 'uploaded_by' => $r->user()->id, 'name' => basename($f->getClientOriginalName()), 'key' => $key, 'mime' => $f->getMimeType(), 'size' => $f->getSize(), 'hash' => hash_file('sha256', $f->getRealPath()), 'status' => 'quarantined', 'created_at' => now(), 'updated_at' => now()]);
                Audit::write($d->tenant_id, $r->user()->id, 'attachment.uploaded', 'attachment', $aid);
                ScanAttachment::dispatch($aid)->afterCommit();

                return $aid;
            });
        } catch (\Throwable $e) {
            Storage::delete($key);
            throw $e;
        }

        return ['id' => $aid, 'status' => 'quarantined'];
    }

    public function download(Request $r, int $id)
    {
        $a = DB::table('attachments')->where('tenant_id', Access::tenant($r))->where('id', $id)->first();
        abort_unless($a, 404);
        Access::document($r, $a->document_id);
        abort_unless($a->status === 'clean', 403, 'Attachment is not cleared by scanner');

        return Storage::download($a->key, $a->name, ['Content-Type' => $a->mime, 'X-Content-Type-Options' => 'nosniff']);
    }
}
