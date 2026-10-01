<?php

namespace App\Jobs;

use App\Domain\Access;
use App\Models\Document;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class GenerateExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public int $exportId) {}

    public function handle(): void
    {
        $e = DB::table('exports')->find($this->exportId);
        if (! $e || $e->status === 'ready') {
            return;
        }$m = DB::table('memberships')->where('tenant_id', $e->tenant_id)->where('user_id', $e->user_id)->first();
        if (! $m || ! User::where('id', $e->user_id)->where('active', true)->exists()) {
            throw new \RuntimeException('Exporter no longer authorized');
        }$filters = json_decode($e->filters, true);
        $q = Access::visible(Document::query(), $m, $e->user_id)->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))->orderBy('id');
        $tmp = tempnam(sys_get_temp_dir(), 'export');
        $head = ['Number', 'Kind', 'Title', 'Status', 'Currency', 'Total', 'Created'];
        $writer = null;
        $csv = null;
        if ($e->format === 'xlsx') {
            $writer = new Writer;
            $writer->openToFile($tmp);
            $writer->addRow(Row::fromValues($head));
        } else {
            $csv = fopen($tmp, 'w');
            fputcsv($csv, $head);
        }foreach ($q->lazyById(500) as $d) {
            $values = [$d->number, $d->kind, $d->title, $d->status, $d->currency, $d->total, $d->created_at->toISOString()];
            foreach ($values as &$v) {
                if (preg_match('/^[=+@\-\t\r]/', (string) $v)) {
                    $v = "'".$v;
                }
            }unset($v);
            if ($writer) {
                $writer->addRow(Row::fromValues($values));
            } else {
                fputcsv($csv, $values);
            }
        }if ($writer) {
            $writer->close();
        }if ($csv) {
            fclose($csv);
        }$key = 'exports/'.$e->tenant_id.'/'.$e->user_id.'/'.$e->id.'.'.$e->format;
        $stream = fopen($tmp, 'r');
        Storage::put($key, $stream);
        fclose($stream);
        unlink($tmp);
        DB::table('exports')->where('id', $e->id)->update(['status' => 'ready', 'key' => $key, 'updated_at' => now()]);
    }

    public function failed(\Throwable $e): void
    {
        DB::table('exports')->where('id', $this->exportId)->update(['status' => 'failed', 'error' => 'Export generation failed; inspect correlated operational logs', 'updated_at' => now()]);
    }
}
