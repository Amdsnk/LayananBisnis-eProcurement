<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    private function fixture(): Document
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $t = DB::table('tenants')->insertGetId(['name' => 'Concurrency fixture', 'slug' => 'concurrency-'.bin2hex(random_bytes(5))]);
        $u = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $t, 'user_id' => $u->id, 'role' => 'requester', 'scope' => '{}']);
        $e = DB::table('legal_entities')->insertGetId(['tenant_id' => $t, 'code' => 'LE', 'name' => 'Entity', 'data' => '{}']);
        $c = DB::table('cost_centers')->insertGetId(['tenant_id' => $t, 'code' => 'CC', 'name' => 'Center', 'data' => '{}']);
        $b = DB::table('budgets')->insertGetId(['tenant_id' => $t, 'legal_entity_id' => $e, 'cost_center_id' => $c, 'name' => 'Concurrency budget', 'year' => 2026, 'currency' => 'IDR', 'allocated' => '100']);

        return Document::create(['tenant_id' => $t, 'kind' => 'pr', 'number' => 'TEST-CONCURRENT', 'title' => 'Concurrent reserve', 'created_by' => $u->id, 'legal_entity_id' => $e, 'cost_center_id' => $c, 'budget_id' => $b, 'total' => '60', 'budget_value' => '60', 'data' => []]);
    }

    private function parallel(Document $d, bool $sameKey): array
    {
        $start = (string) (microtime(true) + 0.3);
        $procs = [];
        $outputs = [];
        for ($i = 0; $i < 2; $i++) {
            $key = $sameKey ? 'concurrent-shared-key' : 'concurrent-key-'.$i;
            $command = [PHP_BINARY, base_path('tests/support/ledger-worker.php'), (string) $d->id, $key, $start];
            $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_URL' => '', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_HOST' => config('database.connections.pgsql.host'), 'DB_USERNAME' => config('database.connections.pgsql.username'), 'DB_PASSWORD' => config('database.connections.pgsql.password'), 'APP_KEY' => config('app.key'), 'BID_ENCRYPTION_KEY' => config('procurement.bid_key')]);
            $pipes = [];
            $procs[] = [proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env), $pipes];
        }
        foreach ($procs as [$process,$pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $this->assertSame(0, $exit, $err);
            $outputs[] = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
        }

        return $outputs;
    }

    public function test_two_processes_cannot_overspend_locked_budget(): void
    {
        $d = $this->fixture();
        $r = $this->parallel($d, false);
        $this->assertSame(1, count(array_filter($r, fn ($x) => $x['accepted'])));
        $this->assertSame('60.0000', DB::table('budgets')->where('id', $d->budget_id)->value('reserved'));
        $this->assertSame(1, DB::table('budget_ledger')->where('document_id', $d->id)->count());
    }

    public function test_parallel_idempotency_key_produces_single_effect(): void
    {
        $d = $this->fixture();
        $r = $this->parallel($d, true);
        $this->assertTrue($r[0]['accepted']);
        $this->assertTrue($r[1]['accepted']);
        $this->assertEquals($r[0]['result'], $r[1]['result']);
        $this->assertSame('60.0000', DB::table('budgets')->where('id', $d->budget_id)->value('reserved'));
        $this->assertSame(1, DB::table('budget_ledger')->where('document_id', $d->id)->count());
    }
}
