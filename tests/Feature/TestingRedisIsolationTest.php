<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class TestingRedisIsolationTest extends TestCase
{
    public function test_phpunit_uses_the_dedicated_isolated_redis_instance(): void
    {
        $this->assertSame('testing', app()->environment());

        $this->assertSame('127.0.0.1', (string) config('database.redis.default.host'));
        $this->assertSame('6399', (string) config('database.redis.default.port'));
        $this->assertSame('0', (string) config('database.redis.default.database'));
        $this->assertSame('1', (string) config('database.redis.cache.database'));
        $this->assertSame('2', (string) config('database.redis.inline_images.database'));
        $this->assertSame('work_phpunit_', (string) config('database.redis.options.prefix'));

        $connection = Redis::connection();
        $key = 'phpunit:redis-isolation:probe';

        $connection->set($key, 'isolated');
        $this->assertSame('isolated', $connection->get($key));
        $connection->del($key);
    }
}
