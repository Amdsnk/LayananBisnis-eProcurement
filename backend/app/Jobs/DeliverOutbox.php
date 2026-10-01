<?php

namespace App\Jobs;

use App\Domain\ErpConnector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class DeliverOutbox implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $eventId) {}

    public function handle(): void
    {
        DB::transaction(function () {
            $e = DB::table('outbox')->where('id', $this->eventId)->lockForUpdate()->first();
            if (! $e || in_array($e->status, ['delivered', 'dead']) || now()->lt($e->available_at)) {
                return;
            }$payload = json_decode($e->payload, true);
            try {
                if ($e->type === 'notification') {
                    DB::table('notifications')->insert(['tenant_id' => $e->tenant_id, 'user_id' => $payload['user_id'], 'title' => $payload['title'], 'data' => '{}', 'created_at' => now()]);
                } elseif (str_starts_with($e->type, 'erp.')) {
                    $config = DB::table('integration_configs')->where('tenant_id', $e->tenant_id)->where('enabled', true)->first();
                    if (! $config) {
                        throw new \RuntimeException('No enabled ERP connector');
                    }$result = (new ErpConnector)->send($config, $payload, 'outbox-'.$e->id);
                    $payload['result'] = $result;
                } else {
                    throw new \RuntimeException('No handler registered');
                }
                DB::table('outbox')->where('id', $e->id)->update(['status' => 'delivered', 'payload' => json_encode($payload), 'last_error' => null, 'attempts' => $e->attempts + 1, 'updated_at' => now()]);
            } catch (\Throwable $error) {
                $attempt = $e->attempts + 1;
                DB::table('outbox')->where('id', $e->id)->update(['status' => $attempt >= 5 ? 'dead' : 'pending', 'attempts' => $attempt, 'last_error' => substr($error->getMessage(), 0, 200), 'available_at' => now()->addSeconds(min(3600, 30 * (2 ** $attempt) + random_int(0, 20))), 'updated_at' => now()]);
            }
        });
    }
}
