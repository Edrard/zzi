<?php

namespace Tests\Unit\Services;

use App\Services\RuntimeCacheMaintenanceService;
use App\Services\Znuny\ZnunyTicketArticleCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RuntimeCacheMaintenanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private RuntimeCacheMaintenanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearTestCacheKeys();
        $this->service = app(RuntimeCacheMaintenanceService::class);
    }

    protected function tearDown(): void
    {
        $this->clearTestCacheKeys();
        parent::tearDown();
    }

    private function clearTestCacheKeys(): void
    {
        foreach ([
            'znuny_active_agents',
            'znuny.queues',
            'unrelated_settings_sentinel',
            'unrelated_sentinel',
            'settings_sentinel',
            'lookup_sentinel',
            'ticket_workspace_sentinel',
            'zabbix_problem_sentinel',
            'snapshot_state_sentinel',
            'session_sentinel',
            'lock_sentinel',
        ] as $key) {
            Cache::forget($key);
        }
    }

    public function test_clear_ticket_article_cache_invalidates_generation()
    {
        $articleService = app(ZnunyTicketArticleCacheService::class);
        $initialGeneration = $articleService->getGeneration();

        Cache::put('znuny_active_agents', 'agent_data');
        Cache::put('znuny.queues', 'queue_data');
        Cache::put('lookup_sentinel', 'safe');

        $this->service->clearTicketArticleCache();
        $generation2 = Cache::get('znuny:ticket:articles:generation');
        $this->assertGreaterThan($initialGeneration, $generation2);

        $this->service->clearTicketArticleCache();
        $generation3 = Cache::get('znuny:ticket:articles:generation');
        $this->assertGreaterThan($generation2, $generation3);

        $this->assertEquals('agent_data', Cache::get('znuny_active_agents'));
        $this->assertEquals('queue_data', Cache::get('znuny.queues'));
        $this->assertEquals('safe', Cache::get('lookup_sentinel'));
    }

    public function test_scope_guard_no_method_clears_unrelated_caches()
    {
        Cache::put('ticket_workspace_sentinel', 'data');
        Cache::put('zabbix_problem_sentinel', 'data');
        Cache::put('snapshot_state_sentinel', 'data');
        Cache::put('session_sentinel', 'data');
        Cache::put('lock_sentinel', 'data');

        $this->service->clearTicketArticleCache();

        $this->assertEquals('data', Cache::get('ticket_workspace_sentinel'));
        $this->assertEquals('data', Cache::get('zabbix_problem_sentinel'));
        $this->assertEquals('data', Cache::get('snapshot_state_sentinel'));
        $this->assertEquals('data', Cache::get('session_sentinel'));
        $this->assertEquals('data', Cache::get('lock_sentinel'));
    }
}
