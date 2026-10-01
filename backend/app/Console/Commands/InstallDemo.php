<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;

class InstallDemo extends Command
{
    protected $signature = 'procurement:demo {--force : Allow explicit setup in production} {--skip-files : Skip object-storage files and export jobs}';
    protected $description = 'Install complete synthetic demo fixtures once in an isolated workspace';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Production demo setup requires --force. Existing workspaces are not changed.');
            return self::FAILURE;
        }
        $password = $this->secret('Password for NEW demo accounts (12+ characters; Enter generates one)');
        $password = $password ?: 'Demo!'.bin2hex(random_bytes(12));
        $seeder = app(DemoSeeder::class);
        $seeder->password = $password;
        $seeder->allowProduction = (bool) $this->option('force');
        $seeder->withFiles = ! $this->option('skip-files');
        try {
            $seeder->run();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            if ($seeder->created) {
                $this->table(['Email', 'Role'], array_map(fn ($email, $role) => [$email, $role], array_keys($seeder->accounts), array_values($seeder->accounts)));
                $this->line('Password for the NEW demo accounts: '.$password);
            }
            $this->line('If fixtures were committed but storage failed, accounts remain created. Retry exports/uploads from the app after storage is fixed.');
            return self::FAILURE;
        }
        if (! $seeder->created) {
            $this->info('Demo is already installed. No accounts, passwords or data were changed.');
            return self::SUCCESS;
        }
        $this->info('DEMO_INSTALLED tenant='.$seeder->tenant);
        $this->table(['Email', 'Role'], array_map(fn ($email, $role) => [$email, $role], array_keys($seeder->accounts), array_values($seeder->accounts)));
        $this->line('Password for the NEW demo accounts: '.$password);
        $this->line('Attachments and exports are processed by the real worker; integrations remain disabled.');
        return self::SUCCESS;
    }
}
