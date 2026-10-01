<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;

class CheckConfiguration extends Command
{
    protected $signature = 'procurement:check-config';

    protected $description = 'Validate runtime prerequisites without exposing secrets';

    public function handle(): int
    {
        $errors = [];
        if (config('database.default') !== 'pgsql') {
            $errors[] = 'PostgreSQL is required';
        }
        $key = base64_decode(config('procurement.bid_key') ?? '', true);
        if (! $key || strlen($key) !== 32) {
            $errors[] = 'BID_ENCRYPTION_KEY must be base64 of 32 random bytes';
        }
        $appKey = config('app.key') ?? '';
        $decodedAppKey = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;
        if (! is_string($decodedAppKey) || ! Encrypter::supported($decodedAppKey, config('app.cipher'))) {
            $errors[] = 'APP_KEY must be a valid key for the configured cipher';
        }
        if (is_string($key) && is_string($decodedAppKey) && hash_equals($key, $decodedAppKey)) {
            $errors[] = 'BID_ENCRYPTION_KEY must be separate from APP_KEY';
        }
        if (config('queue.default') === 'redis' && config('queue.connections.redis.retry_after') <= 130) {
            $errors[] = 'Redis retry_after must exceed the worker timeout of 130 seconds';
        }
        if (app()->environment('production')) {
            if (config('app.debug')) {
                $errors[] = 'APP_DEBUG must be false';
            }
            if (! str_starts_with(config('app.url'), 'https://')) {
                $errors[] = 'APP_URL must use HTTPS';
            }
            if (! config('session.secure')) {
                $errors[] = 'Secure session cookies required';
            }
            if (config('filesystems.default') !== 's3') {
                $errors[] = 'Private object storage required';
            }
            foreach (['key', 'secret', 'bucket'] as $field) {
                if (! config('filesystems.disks.s3.'.$field)) {
                    $errors[] = 'S3 '.$field.' missing';
                }
            }
            if (! config('procurement.scanner_host')) {
                $errors[] = 'SCANNER_HOST required';
            }
            if (config('queue.default') !== 'redis' || config('cache.default') !== 'redis' || config('session.driver') !== 'redis') {
                $errors[] = 'Redis queue/cache/session required';
            }
        }
        foreach ($errors as $error) {
            $this->error($error);
        }if (count($errors)) {
            return self::FAILURE;
        }$this->info('Runtime configuration valid; this does not imply production approval');

        return self::SUCCESS;
    }
}
