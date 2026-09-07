<?php

namespace Tests\Unit\Services\Znuny;

use App\Models\Setting;
use App\Services\Znuny\Cache\ZnunyCustomerUserCacheReadService;
use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyCustomerUserExistenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ZnunyCustomerUserExistenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private $cacheReadMock;

    private $clientMock;

    private ZnunyCustomerUserExistenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheReadMock = Mockery::mock(ZnunyCustomerUserCacheReadService::class);
        $this->clientMock = Mockery::mock(ZnunyClient::class);
        $this->service = new ZnunyCustomerUserExistenceService($this->cacheReadMock, $this->clientMock);

        Setting::updateOrCreate(['key' => 'znuny_prewarm_customer_users_interval_minutes'], ['value' => '30']);
    }

    public function test_blank_login_returns_false_no_live_call(): void
    {
        $this->clientMock->shouldNotReceive('getCustomerUser');

        $result = $this->service->checkCustomerUserExistence('   ', 'CUST1');

        $this->assertFalse($result['registered']);
        $this->assertEquals(ZnunyCustomerUserExistenceService::SOURCE_MISSING_LOGIN, $result['source']);
    }

    public function test_blank_customer_id_returns_false_no_live_call(): void
    {
        $this->clientMock->shouldNotReceive('getCustomerUser');

        $result = $this->service->checkCustomerUserExistence('user1', '   ');

        $this->assertFalse($result['registered']);
        $this->assertEquals(ZnunyCustomerUserExistenceService::SOURCE_MISSING_CUSTOMER_ID, $result['source']);
    }

    public function test_current_generation_temp_true_returns_true_no_live_call(): void
    {
        $this->clientMock->shouldNotReceive('getCustomerUser');
        $this->cacheReadMock->shouldReceive('getSnapshot')->andReturn(['generation' => 'gen1']);

        $hash = hash('sha256', 'user1');
        Cache::put("znuny_customer_user_exists:gen1:{$hash}", true);

        $result = $this->service->checkCustomerUserExistence('user1', 'CUST1');

        $this->assertTrue($result['registered']);
        $this->assertEquals(ZnunyCustomerUserExistenceService::SOURCE_SHORT_CACHE, $result['source']);
    }

    public function test_current_generation_temp_false_returns_false_no_live_call(): void
    {
        $this->clientMock->shouldNotReceive('getCustomerUser');
        $this->cacheReadMock->shouldReceive('getSnapshot')->andReturn(['generation' => 'gen1']);

        $hash = hash('sha256', 'user1');
        Cache::put("znuny_customer_user_exists:gen1:{$hash}", false);

        $result = $this->service->checkCustomerUserExistence('user1', 'CUST1');

        $this->assertFalse($result['registered']);
        $this->assertEquals(ZnunyCustomerUserExistenceService::SOURCE_SHORT_CACHE, $result['source']);
    }

    public function test_positive_prewarm_overrides_old_temp_false(): void
    {
        $this->clientMock->shouldNotReceive('getCustomerUser');

        // Simulating that prewarm says it exists
        $this->cacheReadMock->shouldReceive('getSnapshot')->andReturn([
            'generation' => 'gen1',
            'queues' => [
                ['options' => ['user1' => 'User One']],
            ],
        ]);

        // But temp cache says it's false
        $hash = hash('sha256', 'user1');
        Cache::put("znuny_customer_user_exists:gen1:{$hash}", false);

        $result = $this->service->checkCustomerUserExistence('user1', 'CUST1');

        $this->assertTrue($result['registered']);
        $this->assertEquals(ZnunyCustomerUserExistenceService::SOURCE_PREWARM, $result['source']);
    }

    public function test_exact_prewarm_positive_returns_true_no_live_call(): void
    {
        $this->clientMock->shouldNotReceive('getCustomerUser');

        $this->cacheReadMock->shouldReceive('getSnapshot')->andReturn([
            'generation' => 'gen1',
            'queues' => [
                ['options' => ['user1' => 'User One']],
            ],
        ]);

        $result = $this->service->checkCustomerUserExistence('user1', 'CUST1');

        $this->assertTrue($result['registered']);
        $this->assertEquals(ZnunyCustomerUserExistenceService::SOURCE_PREWARM, $result['source']);
    }

    public function test_prewarm_miss_returns_false_and_cache_miss(): void
    {
        $this->cacheReadMock->shouldReceive('getSnapshot')->andReturn(['generation' => 'gen1']);

        $this->clientMock->shouldNotReceive('getCustomerUser');

        $result = $this->service->checkCustomerUserExistence('user1', 'CUST1');

        $this->assertFalse($result['registered']);
        $this->assertEquals('cache_miss', $result['source']);
    }

    public function test_prewarm_miss_returns_false_no_live_call_and_no_temp_false_write(): void
    {
        $this->cacheReadMock->shouldReceive('getSnapshot')->andReturn(['generation' => 'gen1']);

        $this->clientMock->shouldNotReceive('getCustomerUser');

        $result = $this->service->checkCustomerUserExistence('user1', 'CUST1');

        $this->assertFalse($result['registered']);
        $this->assertEquals('cache_miss', $result['source']);

        $hash = hash('sha256', 'user1');
        $this->assertNull(Cache::get("znuny_customer_user_exists:gen1:{$hash}"));
    }

    public function test_forced_verify_live_found_returns_true_and_temp_true(): void
    {
        $this->cacheReadMock->shouldReceive('getSnapshot')->andReturn(['generation' => 'gen1']);

        $this->clientMock->shouldReceive('getCustomerUser')
            ->once()
            ->with('user1')
            ->andReturn(['found' => 1, 'login' => 'user1', 'customer_id' => 'CUST1']);

        $result = $this->service->lookupDirectly('user1', 'CUST1');

        $this->assertTrue($result['registered']);
        $this->assertEquals(ZnunyCustomerUserExistenceService::SOURCE_LIVE, $result['source']);

        $hash = hash('sha256', 'user1');
        $this->assertTrue(Cache::get("znuny_customer_user_exists:gen1:{$hash}"));
    }

    public function test_new_generation_ignores_old_temp_and_misses_cache(): void
    {
        // Set temp for gen1
        $hash = hash('sha256', 'user1');
        Cache::put("znuny_customer_user_exists:gen1:{$hash}", false);

        // Snapshot returns gen2
        $this->cacheReadMock->shouldReceive('getSnapshot')->andReturn(['generation' => 'gen2']);

        $this->clientMock->shouldNotReceive('getCustomerUser');

        // Since checkCustomerUserExistence no longer calls live, it should return cache_miss
        $result = $this->service->checkCustomerUserExistence('user1', 'CUST1');

        $this->assertFalse($result['registered']);
        $this->assertEquals('cache_miss', $result['source']);
    }

    public function test_forced_verify_bypasses_temp_false(): void
    {
        $this->cacheReadMock->shouldReceive('getSnapshot')->andReturn(['generation' => 'gen1']);

        $hash = hash('sha256', 'user1');
        Cache::put("znuny_customer_user_exists:gen1:{$hash}", false);

        $this->clientMock->shouldReceive('getCustomerUser')
            ->once()
            ->with('user1')
            ->andReturn(['found' => 1, 'login' => 'user1', 'customer_id' => 'CUST1']);

        // lookupDirectly bypasses short temp cache and prewarm
        $result = $this->service->lookupDirectly('user1', 'CUST1');

        $this->assertTrue($result['registered']);
        $this->assertEquals(ZnunyCustomerUserExistenceService::SOURCE_LIVE, $result['source']);

        // It should update temp true
        $this->assertTrue(Cache::get("znuny_customer_user_exists:gen1:{$hash}"));
    }

    public function test_forced_verify_bypasses_prewarm_true(): void
    {
        $this->cacheReadMock->shouldReceive('getSnapshot')->andReturn([
            'generation' => 'gen1',
            'queues' => [
                ['options' => ['user1' => 'User One']],
            ],
        ]);

        $this->clientMock->shouldReceive('getCustomerUser')
            ->once()
            ->with('user1')
            ->andReturn(['found' => 0]); // Actually deleted on Znuny

        $result = $this->service->lookupDirectly('user1', 'CUST1');

        $this->assertFalse($result['registered']);
        $this->assertEquals(ZnunyCustomerUserExistenceService::SOURCE_LIVE, $result['source']);

        $hash = hash('sha256', 'user1');
        $this->assertFalse(Cache::get("znuny_customer_user_exists:gen1:{$hash}"));
    }

    public function test_exact_login_semantics(): void
    {
        $this->cacheReadMock->shouldReceive('getSnapshot')->andReturn(['generation' => 'gen1']);

        // Check "  user1  "
        $this->clientMock->shouldReceive('getCustomerUser')
            ->once()
            ->with('user1')
            ->andReturn(['found' => 1, 'login' => 'user1', 'customer_id' => 'CUST1']);

        // Service should trim and pass 'user1'
        $result = $this->service->lookupDirectly('  user1  ', 'CUST1');

        $this->assertTrue($result['registered']);

        $hash = hash('sha256', 'user1');
        $this->assertTrue(Cache::get("znuny_customer_user_exists:gen1:{$hash}"));
    }

    public function test_live_fallback_rejects_missing_returned_customer_id_without_stale_fallback(): void
    {
        $this->cacheReadMock->shouldReceive('getSnapshot')->andReturn(['generation' => 'gen1']);
        $this->clientMock->shouldReceive('getCustomerUser')
            ->once()
            ->with('user1')
            ->andReturn([
                'found' => 1,
                'login' => 'user1',
            ]);

        $result = $this->service->lookupDirectly('user1', 'STALE-COMPANY');

        $this->assertNull($result['registered']);
        $this->assertSame(ZnunyCustomerUserExistenceService::SOURCE_UNAVAILABLE, $result['source']);
        $this->assertSame('invalid_authoritative_identity', $result['reason']);
        $this->assertArrayNotHasKey('customer_id', $result);

        $hash = hash('sha256', 'user1');
        $this->assertNull(Cache::get("znuny_customer_user_exists:gen1:{$hash}"));
    }

    public function test_forced_verify_rejects_mismatched_returned_login_without_caching_true(): void
    {
        $this->cacheReadMock->shouldReceive('getSnapshot')->andReturn(['generation' => 'gen1']);
        $this->clientMock->shouldReceive('getCustomerUser')
            ->once()
            ->with('user1')
            ->andReturn([
                'found' => 1,
                'login' => 'other-user',
                'customer_id' => 'CUST1',
            ]);

        $result = $this->service->lookupDirectly('user1', 'STALE');

        $this->assertNull($result['registered']);
        $this->assertSame(ZnunyCustomerUserExistenceService::SOURCE_UNAVAILABLE, $result['source']);
        $this->assertSame('invalid_authoritative_identity', $result['reason']);

        $hash = hash('sha256', 'user1');
        $this->assertNull(Cache::get("znuny_customer_user_exists:gen1:{$hash}"));
    }

    public function test_forced_verify_rejects_missing_returned_customer_id_without_stale_fallback(): void
    {
        $this->cacheReadMock->shouldReceive('getSnapshot')->andReturn(['generation' => 'gen1']);
        $this->clientMock->shouldReceive('getCustomerUser')
            ->once()
            ->with('user1')
            ->andReturn([
                'found' => 1,
                'login' => 'user1',
            ]);

        $result = $this->service->lookupDirectly('user1', 'STALE-COMPANY');

        $this->assertNull($result['registered']);
        $this->assertSame(ZnunyCustomerUserExistenceService::SOURCE_UNAVAILABLE, $result['source']);
        $this->assertSame('invalid_authoritative_identity', $result['reason']);
        $this->assertArrayNotHasKey('customer_id', $result);

        $hash = hash('sha256', 'user1');
        $this->assertNull(Cache::get("znuny_customer_user_exists:gen1:{$hash}"));
    }

    public function test_direct_customer_company_absence_is_not_added(): void
    {
        $lookup = Mockery::mock(ZnunyLookupCacheReadService::class);
        $lookup->shouldReceive('getAuthoritativeCustomerCompanies')->once()->andReturn(['OTHER' => 'Other']);
        $this->clientMock->shouldNotReceive('getCustomerUser');

        $service = new ZnunyCustomerUserExistenceService($this->cacheReadMock, $this->clientMock, $lookup);
        $result = $service->checkCustomerUserExistence('user1', 'CUST1');

        $this->assertFalse($result['registered']);
        $this->assertSame(ZnunyCustomerUserExistenceService::SOURCE_CUSTOMER_COMPANY_NOT_FOUND, $result['source']);
    }

    public function test_unavailable_customer_company_directory_is_unknown(): void
    {
        $lookup = Mockery::mock(ZnunyLookupCacheReadService::class);
        $lookup->shouldReceive('getAuthoritativeCustomerCompanies')->once()->andReturn(null);
        $this->clientMock->shouldNotReceive('getCustomerUser');

        $service = new ZnunyCustomerUserExistenceService($this->cacheReadMock, $this->clientMock, $lookup);
        $result = $service->checkCustomerUserExistence('user1', 'CUST1');

        $this->assertNull($result['registered']);
        $this->assertSame(ZnunyCustomerUserExistenceService::SOURCE_UNAVAILABLE, $result['source']);
    }

    public function test_cache_short_existence_uses_2x_warmer_ttl(): void
    {
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();

        Setting::updateOrCreate(['key' => 'znuny_prewarm_customer_users_interval_minutes'], ['value' => '45']);

        $this->service->cacheShortExistence('gen1', 'user1', true);

        $hash = hash('sha256', 'user1');
        $key = "znuny_customer_user_exists:gen1:{$hash}";

        $this->assertTrue(Cache::get($key));

        $this->travel(5399)->seconds();
        $this->assertTrue(Cache::get($key));

        $this->travel(2)->seconds();
        $this->assertNull(Cache::get($key));
    }

    public function test_direct_lookup_uses_znuny_when_ticket_customer_id_is_stale(): void
    {
        $lookup = Mockery::mock(ZnunyLookupCacheReadService::class);
        $lookup->shouldNotReceive('getAuthoritativeCustomerCompanies');

        $this->cacheReadMock->shouldReceive('getSnapshot')
            ->once()
            ->andReturn(['generation' => 'gen1']);

        $this->clientMock->shouldReceive('getCustomerUser')
            ->once()
            ->with('user1')
            ->andReturn([
                'found' => true,
                'login' => 'user1',
                'customer_id' => 'REAL-COMPANY',
                'status' => 'active',
            ]);

        $service = new ZnunyCustomerUserExistenceService(
            $this->cacheReadMock,
            $this->clientMock,
            $lookup,
        );

        $result = $service->lookupDirectly('user1', 'STALE-COMPANY');

        $this->assertTrue($result['registered']);
        $this->assertSame('REAL-COMPANY', $result['customer_id']);
    }

    public function test_direct_not_found_survives_short_cache_write_failure(): void
    {
        $this->cacheReadMock->shouldReceive('getSnapshot')
            ->once()
            ->andReturn(['generation' => 'gen1']);

        $this->clientMock->shouldReceive('getCustomerUser')
            ->once()
            ->with('missing@example.com')
            ->andReturn(['found' => false, 'errors' => []]);

        $service = new class($this->cacheReadMock, $this->clientMock) extends ZnunyCustomerUserExistenceService
        {
            public function cacheShortExistence(?string $generation, string $login, bool $exists): void
            {
                throw new \RuntimeException('cache down');
            }
        };

        $result = $service->lookupDirectly('missing@example.com', 'STALE-COMPANY');

        $this->assertFalse($result['registered']);
        $this->assertSame(ZnunyCustomerUserExistenceService::SOURCE_LIVE, $result['source']);
    }
}
