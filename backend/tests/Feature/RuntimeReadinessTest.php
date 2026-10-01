<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RuntimeReadinessTest extends TestCase
{
    public function test_readiness_reports_database_failure_without_details(): void
    {
        DB::shouldReceive('select')->once()->with('select 1')->andThrow(new \RuntimeException('secret-host:password'));
        $this->getJson('/health/ready')->assertStatus(503)->assertExactJson(['ready' => false]);
    }

    public function test_readiness_reports_redis_failure_even_when_database_is_available(): void
    {
        config(['queue.default' => 'redis']);
        DB::shouldReceive('select')->once()->with('select 1')->andReturn([]);
        Redis::shouldReceive('connection')->once()->with('default')->andThrow(new \RuntimeException('redis credentials'));
        $this->getJson('/health/ready')->assertStatus(503)->assertExactJson(['ready' => false]);
    }

    public function test_readiness_checks_each_required_redis_connection(): void
    {
        config(['cache.default' => 'redis', 'session.driver' => 'redis', 'queue.default' => 'redis']);
        DB::shouldReceive('select')->once()->with('select 1')->andReturn([]);
        Redis::shouldReceive('connection')->once()->with('cache')->andReturnSelf();
        Redis::shouldReceive('connection')->once()->with('default')->andReturnSelf();
        Redis::shouldReceive('ping')->twice()->andReturn('PONG');
        $this->getJson('/health/ready')->assertOk()->assertExactJson(['ready' => true]);
    }

    public function test_startup_rejects_invalid_application_key(): void
    {
        config(['app.key' => 'invalid', 'procurement.bid_key' => base64_encode(str_repeat('b', 32))]);
        $this->artisan('procurement:check-config')->expectsOutput('APP_KEY must be a valid key for the configured cipher')->assertFailed();
    }

    public function test_startup_rejects_shared_bid_and_application_keys(): void
    {
        $key = base64_encode(str_repeat('k', 32));
        config(['app.key' => 'base64:'.$key, 'procurement.bid_key' => $key]);
        $this->artisan('procurement:check-config')->expectsOutput('BID_ENCRYPTION_KEY must be separate from APP_KEY')->assertFailed();
    }

    public function test_startup_rejects_queue_retry_before_worker_timeout(): void
    {
        config(['queue.default' => 'redis', 'queue.connections.redis.retry_after' => 60]);
        $this->artisan('procurement:check-config')->expectsOutput('Redis retry_after must exceed the worker timeout of 130 seconds')->assertFailed();
    }
}
