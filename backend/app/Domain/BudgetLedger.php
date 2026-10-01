<?php

namespace App\Domain;

use App\Models\Document;
use Illuminate\Support\Facades\DB;

class BudgetLedger
{
    public static function move(Document $d, string $action, string $reserved, string $committed, string $actual, int $actor): void
    {
        abort_unless($d->budget_id, 422, 'Budget required');
        $b = DB::table('budgets')->where('tenant_id', $d->tenant_id)->where('id', $d->budget_id)->lockForUpdate()->first();
        abort_unless($b, 422, 'Budget not found');
        $r = Money::add($b->reserved, $reserved);
        $c = Money::add($b->committed, $committed);
        $a = Money::add($b->actual, $actual);
        foreach ([$r, $c, $a] as $v) {
            abort_if(bccomp($v, '0', 4) < 0, 409, 'Budget ledger would become negative');
        }
        abort_if(bccomp(Money::add(Money::add($r, $c), $a), $b->allocated, 4) > 0, 409, 'Insufficient available budget');
        DB::table('budgets')->where('id', $b->id)->update(['reserved' => $r, 'committed' => $c, 'actual' => $a, 'updated_at' => now()]);
        DB::table('budget_ledger')->insert(['tenant_id' => $d->tenant_id, 'budget_id' => $b->id, 'document_id' => $d->id, 'action' => $action, 'reserved_delta' => $reserved, 'committed_delta' => $committed, 'actual_delta' => $actual, 'actor_id' => $actor, 'created_at' => now()]);
    }
}
