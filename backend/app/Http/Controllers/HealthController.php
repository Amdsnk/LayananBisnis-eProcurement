<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    public function ready(): JsonResponse
    {
        try {
            DB::select('select 1');
            $connections = [];
            if (config('cache.default') === 'redis') {
                $connections[] = config('cache.stores.redis.connection', 'cache');
            }
            if (config('session.driver') === 'redis') {
                $connections[] = config('session.connection') ?: 'default';
            }
            if (config('queue.default') === 'redis') {
                $connections[] = config('queue.connections.redis.connection', 'default');
            }
            foreach (array_unique($connections) as $connection) {
                Redis::connection($connection)->ping();
            }

            return response()->json(['ready' => true]);
        } catch (\Throwable) {
            return response()->json(['ready' => false], 503);
        }
    }
}
