<?php

namespace App\Http\Controllers;

use App\Domain\Access;
use App\Domain\Audit;
use App\Domain\Idempotency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Reader;

class ImportController extends Controller
{
    public function template(Request $r, string $resource)
    {
        $this->authorizeResource($r, $resource);
        $columns = $resource === 'vendors' ? ['code', 'name', 'email', 'tax_id'] : ['code', 'name'];

        return response(implode(',', $columns)."\n", 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="'.$resource.'-template.csv"']);
    }

    private function authorizeResource(Request $r, string $resource): void
    {
        abort_unless(in_array($resource, AdminController::MASTER, true) || $resource === 'vendors', 404);
        Access::require(Access::membership($r), $resource === 'vendors' ? 'vendor.manage' : 'master');
    }

    public function preview(Request $r, string $resource)
    {
        $this->authorizeResource($r, $resource);
        $r->validate(['file' => 'required|file|max:25600|extensions:csv,xlsx']);
        $f = $r->file('file');
        $rows = [];
        $raw = [];
        $extension = strtolower($f->getClientOriginalExtension());
        if ($extension === 'xlsx') {
            $zip = new \ZipArchive;
            abort_unless($zip->open($f->getRealPath()) === true, 422, 'Invalid workbook');
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $size += $entry['size'];
                abort_if($size > 100 * 1024 * 1024 || ($entry['size'] > 1000000 && $entry['size'] / max(1, $entry['comp_size']) > 200), 422, 'Workbook decompression limit exceeded');
                if (str_ends_with($entry['name'], '.xml')) {
                    abort_if(str_contains($zip->getFromIndex($i), '<!DOCTYPE'), 422, 'XML DTD forbidden');
                }
            }$zip->close();
            $reader = new Reader;
            $reader->open($f->getRealPath());
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $values = [];
                    foreach ($row->getCells() as $cell) {
                        abort_if($cell instanceof FormulaCell, 422, 'Formula cells are prohibited');
                        $values[] = (string) $cell->getValue();
                    }$raw[] = $values;
                    abort_if(count($raw) > 10001, 422, 'Maximum 10,000 rows per import');
                }break;
            }$reader->close();
        } else {
            $h = fopen($f->getRealPath(), 'r');
            while (($line = fgetcsv($h)) !== false) {
                $raw[] = $line;
                abort_if(count($raw) > 10001, 422, 'Maximum 10,000 rows per import');
            }fclose($h);
        }
        abort_unless(count($raw) >= 2, 422, 'Headers and rows required');
        $headers = array_map(fn ($x) => trim(ltrim((string) $x, "\xEF\xBB\xBF")), array_shift($raw));
        $required = $resource === 'vendors' ? ['code', 'name', 'email'] : ['code', 'name'];
        foreach ($required as $column) {
            abort_unless(in_array($column, $headers, true), 422, 'Missing '.$column.' column');
        }$seen = [];
        foreach ($raw as $i => $cells) {
            if (count($cells) !== count($headers)) {
                $rows[] = ['row' => $i + 2, 'values' => [], 'errors' => ['Column count mismatch']];

                continue;
            }$v = array_intersect_key(array_combine($headers, $cells), array_flip(['code', 'name', 'email', 'tax_id']));
            $validator = Validator::make($v, ['code' => 'required|string|max:40', 'name' => 'required|string|max:180', 'email' => $resource === 'vendors' ? 'required|email' : 'nullable', 'tax_id' => 'nullable|string|max:50']);
            $errors = $validator->errors()->all();
            foreach ($v as $cell) {
                if (preg_match('/^[=+@\-\t\r]/', $cell)) {
                    $errors[] = 'Formula-like cell prohibited';
                }
            }if (isset($seen[$v['code'] ?? ''])) {
                $errors[] = 'Duplicate code in workbook';
            }$seen[$v['code'] ?? ''] = true;
            $rows[] = ['row' => $i + 2, 'values' => $v, 'errors' => $errors, 'existing' => DB::table($resource)->where('tenant_id', Access::tenant($r))->where('code', $v['code'] ?? '')->exists()];
        }
        $id = DB::table('import_previews')->insertGetId(['tenant_id' => Access::tenant($r), 'user_id' => $r->user()->id, 'resource' => $resource, 'rows' => json_encode($rows), 'hash' => hash_file('sha256', $f->getRealPath()), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);

        return ['id' => $id, 'total' => count($rows), 'invalid' => count(array_filter($rows, fn ($x) => count($x['errors']) > 0)), 'rows' => $rows];
    }

    public function commit(Request $r, int $id)
    {
        return Idempotency::run($r, function () use ($r, $id) {
            $p = DB::table('import_previews')->where('tenant_id', Access::tenant($r))->where('user_id', $r->user()->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($p, 404);
            $this->authorizeResource($r, $p->resource);
            abort_unless($p->status === 'preview' && now()->lt($p->expires_at), 409, 'Import preview expired/already consumed');
            $rows = json_decode($p->rows, true);
            abort_if(count(array_filter($rows, fn ($x) => count($x['errors']) > 0)), 422, 'Resolve validation errors before importing');
            $created = 0;
            $skipped = 0;
            DB::table('tenants')->where('id', $p->tenant_id)->lockForUpdate()->first();
            foreach ($rows as $row) {
                $v = $row['values'];
                if (DB::table($p->resource)->where('tenant_id', $p->tenant_id)->where('code', $v['code'])->exists()) {
                    $skipped++;

                    continue;
                }$values = ['tenant_id' => $p->tenant_id, 'code' => $v['code'], 'name' => $v['name'], 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'];
                if ($p->resource === 'vendors') {
                    $values += ['email' => $v['email'], 'tax_id' => $v['tax_id'] ?? null, 'status' => 'under_review', 'created_by' => $r->user()->id];
                }DB::table($p->resource)->insert($values);
                $created++;
            }DB::table('import_previews')->where('id', $id)->update(['status' => 'committed', 'updated_at' => now()]);
            Audit::write($p->tenant_id, $r->user()->id, 'import.committed', $p->resource, $id, ['hash' => $p->hash, 'created' => $created, 'skipped_existing' => $skipped]);

            return ['created' => $created, 'skipped_existing' => $skipped];
        });
    }
}
