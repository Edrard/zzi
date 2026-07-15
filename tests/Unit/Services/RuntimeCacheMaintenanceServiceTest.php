<?php

namespace Tests\Unit\Services;

use App\Models\Setting;
use App\Services\RuntimeCacheMaintenanceService;
use App\Services\SettingsService;
use App\Services\Znuny\ZnunyCachedLookupService;
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

        app(SettingsService::class)->clearAllCaches();
    }

    public function test_clear_settings_cache_delegates_to_settings_service_and_clears_settings_cache()
    {
        // Persist a setting
        Setting::updateOrCreate(['key' => 'test_maintenance_setting'], ['value' => 'initial_value', 'type' => 'string']);

        // Populate cache
        $settingsService = app(SettingsService::class);
        $settingsService->clearAllCaches();

        $this->assertEquals('initial_value', SettingsService::string('test_maintenance_setting'));

        // Change DB value bypassing model events (which would normally clear cache)
        Setting::where('key', 'test_maintenance_setting')->update(['value' => 'new_value']);

        // Assert cache is still old
        $this->assertEquals('initial_value', SettingsService::string('test_maintenance_setting'));

        // Put an unrelated sentinel
        Cache::put('unrelated_settings_sentinel', 'safe');

        // Call our maintenance service
        $this->service->clearSettingsCache();

        // Verify settings cache is cleared and pulls new value
        $this->assertEquals('new_value', SettingsService::string('test_maintenance_setting'));

        // Verify unrelated sentinel remains
        $this->assertEquals('safe', Cache::get('unrelated_settings_sentinel'));
    }

    public function test_clear_znuny_agent_cache_clears_agents_only()
    {
        Cache::put('znuny_active_agents', 'agent_data');
        Cache::put('unrelated_sentinel', 'safe');
        Cache::put('znuny.queues', 'queue_data');

        $this->service->clearZnunyAgentCache();

        $this->assertNull(Cache::get('znuny_active_agents'));
        $this->assertEquals('safe', Cache::get('unrelated_sentinel'));
        $this->assertEquals('queue_data', Cache::get('znuny.queues'));
    }

    public function test_clear_znuny_queue_cache_clears_queues_only()
    {
        Cache::put('znuny.queues', 'queue_data');
        Cache::put('znuny_active_agents', 'agent_data');
        Cache::put('unrelated_sentinel', 'safe');

        $this->service->clearZnunyQueueCache();

        $this->assertNull(Cache::get('znuny.queues'));
        $this->assertEquals('agent_data', Cache::get('znuny_active_agents'));
        $this->assertEquals('safe', Cache::get('unrelated_sentinel'));
    }

    public function test_clear_znuny_lookup_cache_invalidates_version()
    {
        $lookupService = app(ZnunyCachedLookupService::class);
        $initialVersion = $lookupService->getCacheVersion();

        Cache::put('znuny_active_agents', 'agent_data');
        Cache::put('znuny.queues', 'queue_data');
        Cache::put('settings_sentinel', 'safe');

        $this->service->clearZnunyLookupCache();
        $version2 = Cache::get('znuny_lookup_cache_version');
        $this->assertGreaterThan($initialVersion, $version2);

        $this->service->clearZnunyLookupCache();
        $version3 = Cache::get('znuny_lookup_cache_version');
        $this->assertGreaterThan($version2, $version3);

        $this->assertEquals('agent_data', Cache::get('znuny_active_agents'));
        $this->assertEquals('queue_data', Cache::get('znuny.queues'));
        $this->assertEquals('safe', Cache::get('settings_sentinel'));
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

        $this->service->clearSettingsCache();
        $this->service->clearZnunyAgentCache();
        $this->service->clearZnunyQueueCache();
        $this->service->clearZnunyLookupCache();
        $this->service->clearTicketArticleCache();

        $this->assertEquals('data', Cache::get('ticket_workspace_sentinel'));
        $this->assertEquals('data', Cache::get('zabbix_problem_sentinel'));
        $this->assertEquals('data', Cache::get('snapshot_state_sentinel'));
        $this->assertEquals('data', Cache::get('session_sentinel'));
        $this->assertEquals('data', Cache::get('lock_sentinel'));
    }
}
