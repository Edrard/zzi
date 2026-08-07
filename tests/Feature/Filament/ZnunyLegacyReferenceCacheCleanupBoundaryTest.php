<?php

namespace Tests\Feature\Filament;

use Tests\TestCase;

class ZnunyLegacyReferenceCacheCleanupBoundaryTest extends TestCase
{
    public function test_cached_lookup_service_cleanup()
    {
        $path = base_path('app/Services/Znuny/ZnunyCachedLookupService.php');
        $this->assertFileExists($path);
        $content = file_get_contents($path);

        // Should not contain legacy methods/keys
        $this->assertStringNotContainsString('znuny_lookup_cache_ttl_minutes', $content);
        $this->assertStringNotContainsString('znuny_lookup_cache_version', $content);
        $this->assertStringNotContainsString('function getCacheTtl(', $content);
        $this->assertStringNotContainsString('function getCacheVersion(', $content);
        $this->assertStringNotContainsString('function invalidateCache(', $content);
        $this->assertStringNotContainsString('function clearCache(', $content);
        $this->assertStringNotContainsString('rememberLookup(', $content);
        $this->assertStringNotContainsString('getCacheKey(', $content);
        $this->assertStringNotContainsString('Cache::', $content);

        // Should contain remaining expected active methods
        $this->assertStringContainsString('searchCustomerUserOptions', $content);
        $this->assertStringContainsString('searchCustomerUsers', $content);
        $this->assertStringContainsString('getPrewarmDatasetState', $content);
        $this->assertStringContainsString('getCustomerUserPrimaryOptionsForQueue', $content);
        $this->assertStringContainsString('getCustomerUserLabel', $content);
        $this->assertStringContainsString('getTicketStates', $content);
        $this->assertStringContainsString('getTicketPriorities', $content);
        $this->assertStringContainsString('getTicketTypes', $content);
    }

    public function test_queue_and_agent_legacy_keys_gone()
    {
        $queuePath = base_path('app/Services/Znuny/ZnunyQueueService.php');
        $this->assertFileExists($queuePath);
        $queueContent = file_get_contents($queuePath);

        $this->assertStringNotContainsString('znuny.queues', $queueContent);
        $this->assertStringNotContainsString('QUEUE_CACHE_KEY', $queueContent);
        $this->assertStringNotContainsString('function clearCache(', $queueContent);
        $this->assertStringNotContainsString('Cache::', $queueContent);

        $this->assertStringContainsString('ZnunyQueueCacheReadService', $queueContent);
        $this->assertStringContainsString('getQueues', $queueContent);
        $this->assertStringContainsString('getSelectableQueuesResult', $queueContent);
        $this->assertStringContainsString('findQueueByName', $queueContent);

        $agentPath = base_path('app/Services/Znuny/ZnunyAgentService.php');
        $this->assertFileExists($agentPath);
        $agentContent = file_get_contents($agentPath);

        $this->assertStringNotContainsString('znuny_active_agents', $agentContent);
        $this->assertStringNotContainsString('CACHE_KEY', $agentContent);
        $this->assertStringNotContainsString('function clearCache(', $agentContent);
        $this->assertStringNotContainsString('Cache::', $agentContent);

        $this->assertStringContainsString('ZnunyAgentCacheReadService', $agentContent);
    }

    public function test_settings_runtime_invalidation_removed_but_compatibility_retained()
    {
        $path = base_path('app/Filament/Pages/Settings.php');
        $this->assertFileExists($path);
        $content = file_get_contents($path);

        // Invalidation removed
        $this->assertStringNotContainsString('->invalidateCache()', $content);
        $this->assertStringNotContainsString('Cache::forget(\'znuny_active_agents\')', $content);
        $this->assertStringNotContainsString('Cache::forget(\'znuny.queues\')', $content);

        // But compatibility retained
        $this->assertStringContainsString('znuny_agent_cache_ttl_minutes', $content);
        $this->assertStringContainsString('znuny_queue_cache_ttl_minutes', $content);
        $this->assertStringContainsString('znuny_lookup_cache_ttl_minutes', $content);
        $this->assertStringContainsString('znuny_ticket_article_cache_ttl_minutes', $content);
        $this->assertStringContainsString('clearTicketArticleCache', $content);
        $this->assertStringContainsString('znuny_prewarm_queues_interval_minutes', $content);
        $this->assertStringContainsString('znuny_prewarm_agents_interval_minutes', $content);
        $this->assertStringContainsString('znuny_prewarm_lookups_interval_minutes', $content);
        $this->assertStringContainsString('znuny_prewarm_customer_users_interval_minutes', $content);
    }

    public function test_compatibility_defaults_and_protected_systems_remain()
    {
        $defaultPath = base_path('app/Support/Settings/DefaultSettings.php');
        $this->assertFileExists($defaultPath);
        $defaultContent = file_get_contents($defaultPath);
        $this->assertStringContainsString('znuny_agent_cache_ttl_minutes', $defaultContent);
        $this->assertStringContainsString('znuny_queue_cache_ttl_minutes', $defaultContent);
        $this->assertStringContainsString('znuny_lookup_cache_ttl_minutes', $defaultContent);

        $prewarmPath = base_path('app/Services/Znuny/Cache/PrewarmSnapshotManager.php');
        $this->assertFileExists($prewarmPath);
        $prewarmContent = file_get_contents($prewarmPath);
        $this->assertStringContainsString('znuny_prewarm_', $prewarmContent);

        $articlePath = base_path('app/Services/Znuny/ZnunyTicketArticleCacheService.php');
        $this->assertFileExists($articlePath);
        $articleContent = file_get_contents($articlePath);
        $this->assertStringContainsString('znuny:ticket:articles:generation', $articleContent);
        $this->assertStringContainsString('znuny_ticket_article_cache_ttl_minutes', $articleContent);
    }

    public function test_obsolete_lookup_precache_command_and_schedule_are_removed(): void
    {
        $this->assertFileDoesNotExist(
            base_path('app/Console/Commands/ZnunyPrecacheLookupsCommand.php')
        );

        $this->assertFileDoesNotExist(
            base_path('tests/Feature/Console/Commands/ZnunyPrecacheLookupsCommandTest.php')
        );

        $routes = file_get_contents(base_path('routes/console.php'));

        $this->assertStringNotContainsString(
            'znuny:precache-lookups',
            $routes
        );
    }
}
