<?php

use App\Jobs\DeliverOutbox;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('procurement:outbox', function () {
    DB::table('outbox')->where('status', 'pending')->where('available_at', '<=', now())->orderBy('id')->limit(100)->get()->each(fn ($e) => DeliverOutbox::dispatch($e->id));
    $this->info('Pending events dispatched');
});
Schedule::command('procurement:outbox')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::call(fn () => \Illuminate\Support\Facades\Cache::put('scheduler-heartbeat', now()->toISOString(), 300))->everyMinute()->name('heartbeat')->onOneServer();
Artisan::command('procurement:bootstrap {name} {email}', function () {
    if (User::exists()) {
        $this->error('Bootstrap refused: users already exist');

        return 1;
    }$password = getenv('BOOTSTRAP_PASSWORD');
    if (! $password || strlen($password) < 14) {
        $this->error('Set BOOTSTRAP_PASSWORD (14+ characters) in secure environment');

        return 1;
    }DB::transaction(function () use ($password) {
        $tenant = DB::table('tenants')->insertGetId(['name' => $this->argument('name'), 'slug' => 'company', 'created_at' => now(), 'updated_at' => now()]);
        $u = User::create(['name' => 'Administrator', 'email' => $this->argument('email'), 'password' => $password]);
        DB::table('memberships')->insert(['tenant_id' => $tenant, 'user_id' => $u->id, 'role' => 'admin', 'scope' => '{}']);
    });
    $this->info('Bootstrap completed. Remove BOOTSTRAP_PASSWORD; enroll MFA at first sign-in.');

    return 0;
});
Artisan::command('procurement:sandbox', function () {
    if (app()->environment('production')) {
        $this->error('Demo data forbidden in production');

        return 1;
    }$this->call('db:seed', ['--class' => 'SandboxSeeder']);

    return 0;
});
