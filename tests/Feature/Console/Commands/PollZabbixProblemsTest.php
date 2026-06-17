<?php

namespace Tests\Feature\Console\Commands;

use App\Services\SettingsService;
use App\Services\Zabbix\ZabbixClient;
use App\Services\Zabbix\ZabbixProblemCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class PollZabbixProblemsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Setup enough environment so PollZabbixProblems doesn't crash
        Cache::flush();
        // Assuming SettingsService uses database or something, we may need to mock it if it's not setup.
        // If it's a standard Laravel TestCase, DB is available if we use RefreshDatabase, but let's try without if it uses a facade.
    }

    public function test_poll_enriches_problems_with_host_ip()
    {
        $client = Mockery::mock(ZabbixClient::class);
        $cache = Mockery::mock(ZabbixProblemCache::class);

        $cache->shouldReceive('lastPoll')->andReturn(null);

        // Limit setting
        $client->shouldReceive('getProblemsForPolling')->andReturn([
            [
                'eventid' => '100',
                'severity' => '4',
                'clock' => time(),
                'name' => 'Test Problem',
                'r_eventid' => '0',
            ],
        ]);

        $client->shouldReceive('getEventHosts')->andReturn([
            '100' => [
                ['hostid' => '200', 'host' => 'server-1', 'name' => 'Server 1', 'status' => '0'],
            ],
        ]);

        // Interface mapping: prefer main interface that is non-loopback
        $client->shouldReceive('getHostInterfaces')->with(['200'])->andReturn([
            ['hostid' => '200', 'ip' => '127.0.0.1', 'main' => '0', 'type' => '1'],
            ['hostid' => '200', 'ip' => '192.168.1.50', 'main' => '1', 'type' => '1'],
        ]);

        $client->shouldReceive('getTriggersForProblems')->andReturn([]);

        // It should cache the problem with host_ip
        $cache->shouldReceive('putMany')->withArgs(function ($problems) {
            if (empty($problems)) {
                return false;
            }

            return $problems[0]['host_ip'] === '192.168.1.50';
        })->once();

        $cache->shouldReceive('markLastPollSuccess')->once();

        $this->app->instance(ZabbixClient::class, $client);
        $this->app->instance(ZabbixProblemCache::class, $cache);

        $exitCode = $this->artisan('app:poll-zabbix-problems', ['--force' => true])->run();

        $this->assertEquals(0, $exitCode);
    }

    public function test_poll_falls_back_to_first_ip_when_no_main()
    {
        $client = Mockery::mock(ZabbixClient::class);
        $cache = Mockery::mock(ZabbixProblemCache::class);

        $cache->shouldReceive('lastPoll')->andReturn(null);

        $client->shouldReceive('getProblemsForPolling')->andReturn([
            [
                'eventid' => '101',
                'severity' => '4',
                'clock' => time(),
                'name' => 'Test Problem',
                'r_eventid' => '0',
            ],
        ]);

        $client->shouldReceive('getEventHosts')->andReturn([
            '101' => [
                ['hostid' => '201', 'host' => 'server-2', 'name' => 'Server 2', 'status' => '0'],
            ],
        ]);

        $client->shouldReceive('getHostInterfaces')->with(['201'])->andReturn([
            ['hostid' => '201', 'ip' => '10.0.0.5', 'main' => '0', 'type' => '1'],
            ['hostid' => '201', 'ip' => '10.0.0.6', 'main' => '0', 'type' => '1'],
        ]);

        $client->shouldReceive('getTriggersForProblems')->andReturn([]);

        $cache->shouldReceive('putMany')->withArgs(function ($problems) {
            return $problems[0]['host_ip'] === '10.0.0.5';
        })->once();

        $cache->shouldReceive('markLastPollSuccess')->once();

        $this->app->instance(ZabbixClient::class, $client);
        $this->app->instance(ZabbixProblemCache::class, $cache);

        $this->artisan('app:poll-zabbix-problems', ['--force' => true])->run();
    }

    public function test_poll_handles_missing_interfaces()
    {
        $client = Mockery::mock(ZabbixClient::class);
        $cache = Mockery::mock(ZabbixProblemCache::class);

        $cache->shouldReceive('lastPoll')->andReturn(null);

        $client->shouldReceive('getProblemsForPolling')->andReturn([
            [
                'eventid' => '102',
                'severity' => '4',
                'clock' => time(),
                'name' => 'Test Problem',
                'r_eventid' => '0',
            ],
        ]);

        $client->shouldReceive('getEventHosts')->andReturn([
            '102' => [
                ['hostid' => '202', 'host' => 'server-3', 'name' => 'Server 3', 'status' => '0'],
            ],
        ]);

        $client->shouldReceive('getHostInterfaces')->with(['202'])->andReturn([]);

        $client->shouldReceive('getTriggersForProblems')->andReturn([]);

        $cache->shouldReceive('putMany')->withArgs(function ($problems) {
            return $problems[0]['host_ip'] === null;
        })->once();

        $cache->shouldReceive('markLastPollSuccess')->once();

        $this->app->instance(ZabbixClient::class, $client);
        $this->app->instance(ZabbixProblemCache::class, $cache);

        $this->artisan('app:poll-zabbix-problems', ['--force' => true])->run();
    }
}
