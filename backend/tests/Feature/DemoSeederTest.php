<?php
namespace Tests\Feature;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use App\Jobs\ScanAttachment;
use App\Jobs\GenerateExport;
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;
    public function test_complete_demo_is_isolated_and_repeatable(): void
    {
        config(['procurement.bid_key' => base64_encode(str_repeat('k', 32))]);
        $seed = new DemoSeeder;
        $seed->password = 'Demo-Test-Password!';
        Storage::fake();
        Bus::fake([ScanAttachment::class, GenerateExport::class]);
        $seed->withFiles = true;
        $seed->run();
        $this->assertTrue($seed->created);
        Bus::assertDispatched(ScanAttachment::class);
        $this->assertSame(2, DB::table('exports')->count());
        foreach (DB::table('exports')->get() as $export) {
            (new GenerateExport($export->id))->handle();
            $ready = DB::table('exports')->find($export->id);
            $this->assertSame('ready', $ready->status);
            Storage::assertExists($ready->key);
        }
        $this->assertCount(15, $seed->accounts);
        $kinds = DB::table('documents')->where('tenant_id', $seed->tenant)->distinct()->pluck('kind')->sort()->values()->all();
        $this->assertEquals(['contract','credit','invoice','po','pr','receipt','return','tender'], $kinds);
        $this->assertGreaterThan(20, DB::table('documents')->count());
        $this->assertGreaterThan(0, DB::table('bids')->count());
        $before = DB::table('documents')->count();
        $user = DB::table('users')->where('email', 'demo.admin@example.com')->first();
        $this->assertTrue(Hash::check($seed->password, $user->password));
        $again = new DemoSeeder;
        $again->password = 'Different-Password!';
        $again->withFiles = false;
        $again->run();
        $this->assertFalse($again->created);
        $this->assertSame($before, DB::table('documents')->count());
        $this->assertSame($user->password, DB::table('users')->find($user->id)->password);
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => $seed->password])->assertOk()->assertJsonPath('mfa_required', false);
        $this->getJson('/api/v1/me')->assertOk();
    }
}
