<?php

namespace App\Domain;

use App\Models\Document;
use App\Models\DocumentLine;
use Illuminate\Support\Facades\DB;

class ProcurementLimits
{
    public static function purchaseOrder(Document $po): void
    {
        $source = Document::where('tenant_id', $po->tenant_id)->lockForUpdate()->findOrFail($po->source_id);
        foreach ($po->lines as $line) {
            $sl = DocumentLine::where('document_id', $source->id)->where('tenant_id', $po->tenant_id)->findOrFail($line->source_line_id);
            $used = (string) DB::table('document_lines as l')->join('documents as d', 'd.id', '=', 'l.document_id')->where('l.source_line_id', $sl->id)->where('d.kind', 'po')->whereIn('d.status', ['approved', 'issued', 'acknowledged', 'partially_received', 'received', 'closed'])->sum('l.quantity');
            abort_if(bccomp(Money::add($used, $line->quantity), $sl->quantity, 4) > 0, 409, 'Purchase quantity exceeds source allocation');
        }
        if ($source->kind === 'contract') {
            $used = (string) Document::where('source_id', $source->id)->where('kind', 'po')->whereIn('status', ['approved', 'issued', 'acknowledged', 'partially_received', 'received', 'closed'])->sum('total');
            abort_if(bccomp(Money::add($used, $po->total), $source->total, 4) > 0, 409, 'Contract value limit exceeded');
        }
        // A shared root PR lock prevents split sourcing from exceeding original requested quantity.
        $pr = Procurement::rootPr($po);
        if ($pr) {
            Document::where('id', $pr->id)->lockForUpdate()->first();
            foreach ($po->lines as $line) {
                $root = DocumentLine::findOrFail($line->source_line_id);
                $seen = [];
                while ($root->source_line_id) {
                    abort_if(in_array($root->id, $seen), 409, 'Line source cycle');
                    $seen[] = $root->id;
                    $root = DocumentLine::where('tenant_id', $po->tenant_id)->findOrFail($root->source_line_id);
                }if ($root->document_id !== $pr->id) {
                    continue;
                }
                $used = '0';
                $existing = Document::where('tenant_id', $po->tenant_id)->where('kind', 'po')->whereIn('status', ['approved', 'issued', 'acknowledged', 'partially_received', 'received', 'closed'])->with('lines')->get();
                foreach ($existing as $other) {
                    foreach ($other->lines as $ol) {
                        $r = $ol;
                        while ($r->source_line_id) {
                            $r = DocumentLine::where('tenant_id', $po->tenant_id)->findOrFail($r->source_line_id);
                        }if ($r->id === $root->id) {
                            $used = Money::add($used, $ol->quantity);
                        }
                    }
                }abort_if(bccomp(Money::add($used, $line->quantity), $root->quantity, 4) > 0, 409, 'Split sourcing exceeds original requisition quantity');
            }
        }
    }
}
