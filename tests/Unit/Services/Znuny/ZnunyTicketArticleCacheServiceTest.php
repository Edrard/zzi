<?php

namespace Tests\Unit\Services\Znuny;

use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyTicketArticleCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ZnunyTicketArticleCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->clearAllCaches();

        $client = Mockery::mock(ZnunyClient::class);
        (new ZnunyTicketArticleCacheService($client))->forgetAll();
    }

    protected function tearDown(): void
    {
        app(SettingsService::class)->clearAllCaches();
        parent::tearDown();
    }

    public function test_get_fetches_from_client_and_caches_result()
    {
        $clientMock = Mockery::mock(ZnunyClient::class);
        $clientMock->shouldReceive('getTicketArticles')
            ->once()
            ->with(123)
            ->andReturn([['article_id' => 1, 'subject' => 'Test Subject']]);

        $service = new ZnunyTicketArticleCacheService($clientMock);

        $result1 = $service->get(123);
        $this->assertCount(1, $result1);
        $this->assertEquals(1, $result1[0]['article_id']);

        $result2 = $service->get(123);
        $this->assertCount(1, $result2);
        $this->assertEquals(1, $result2[0]['article_id']);
    }

    public function test_get_handles_client_exception_gracefully()
    {
        $clientMock = Mockery::mock(ZnunyClient::class);
        $clientMock->shouldReceive('getTicketArticles')
            ->times(2)
            ->with(123)
            ->andReturnUsing(
                function () {
                    throw new \Exception('API Error');
                },
                function () {
                    return [['article_id' => 1, 'subject' => 'Success']];
                }
            );

        $service = new ZnunyTicketArticleCacheService($clientMock);

        $result1 = $service->get(123);
        $this->assertCount(1, $result1);
        $this->assertNull($result1[0]['article_id']);
        $this->assertEquals('Error loading articles', $result1[0]['subject']);

        $result2 = $service->get(123);
        $this->assertCount(1, $result2);
        $this->assertEquals(1, $result2[0]['article_id']);
        $this->assertEquals('Success', $result2[0]['subject']);
    }

    public function test_forget_clears_cache_for_specific_ticket()
    {
        $clientMock = Mockery::mock(ZnunyClient::class);
        $clientMock->shouldReceive('getTicketArticles')->times(2)->with(123)->andReturn([['article_id' => 1]], [['article_id' => 2]]);
        $clientMock->shouldReceive('getTicketArticles')->times(1)->with(456)->andReturn([['article_id' => 3]]);

        $service = new ZnunyTicketArticleCacheService($clientMock);

        // Initially cache both
        $service->get(123);
        $service->get(456);

        // Forget one
        $service->forget(123);

        // This triggers the second call for 123
        $result123 = $service->get(123);
        $this->assertEquals(2, $result123[0]['article_id']);

        // This still uses the cached version for 456
        $result456 = $service->get(456);
        $this->assertEquals(3, $result456[0]['article_id']);
    }

    public function test_refresh_clears_cache_and_fetches_anew()
    {
        $clientMock = Mockery::mock(ZnunyClient::class);
        $clientMock->shouldReceive('getTicketArticles')->times(2)->with(123)->andReturn([['article_id' => 1]]);

        $service = new ZnunyTicketArticleCacheService($clientMock);

        $service->get(123);

        $service->refresh(123);
    }

    public function test_forget_all_invalidates_all_tickets_by_incrementing_generation()
    {
        $clientMock = Mockery::mock(ZnunyClient::class);
        $clientMock->shouldReceive('getTicketArticles')->times(2)->with(123)->andReturn([['article_id' => 1]]);
        $clientMock->shouldReceive('getTicketArticles')->times(2)->with(456)->andReturn([['article_id' => 2]]);

        $service = new ZnunyTicketArticleCacheService($clientMock);

        $service->get(123);
        $service->get(456);

        $service->forgetAll();

        $service->get(123);
        $service->get(456);
    }

    public function test_ttl_expiration()
    {
        Setting::updateOrCreate(['key' => 'znuny_ticket_article_cache_ttl_minutes'], ['value' => '10', 'type' => 'integer']);
        app(SettingsService::class)->clearAllCaches();

        $clientMock = Mockery::mock(ZnunyClient::class);
        $clientMock->shouldReceive('getTicketArticles')->times(1)->with(123)->andReturn([['article_id' => 1]]);
        $clientMock->shouldReceive('getTicketArticles')->times(1)->with(123)->andReturn([['article_id' => 2]]);

        $service = new ZnunyTicketArticleCacheService($clientMock);

        try {
            $this->travelTo(now());

            // First call
            $result = $service->get(123);
            $this->assertEquals(1, $result[0]['article_id']);

            // After 9 minutes, still cached
            $this->travel(9)->minutes();
            $result = $service->get(123);
            $this->assertEquals(1, $result[0]['article_id']);

            // After 11 minutes total, expires
            $this->travel(2)->minutes();
            $result = $service->get(123);
            $this->assertEquals(2, $result[0]['article_id']);
        } finally {
            $this->travelBack();
        }
    }

    public function test_zero_ttl_bypasses_cache()
    {
        Setting::updateOrCreate(['key' => 'znuny_ticket_article_cache_ttl_minutes'], ['value' => '0', 'type' => 'integer']);
        app(SettingsService::class)->clearAllCaches();

        $clientMock = Mockery::mock(ZnunyClient::class);
        $clientMock->shouldReceive('getTicketArticles')->times(3)->with(123)->andReturn([['article_id' => 1]]);

        $service = new ZnunyTicketArticleCacheService($clientMock);

        $service->get(123);
        $service->get(123);
        $service->get(123);

        $generation = $service->getGeneration();
        $key = "znuny:ticket:articles:v{$generation}:123";

        $this->assertFalse(Cache::has($key));
    }

    public static function invalidTtlProvider(): array
    {
        return [
            'missing setting' => [null],
            'unreadable string' => ['not-an-integer'],
            'negative integer' => ['-5'],
        ];
    }

    #[DataProvider('invalidTtlProvider')]
    public function test_invalid_ttl_falls_back_to_15_minutes(?string $invalidValue)
    {
        if ($invalidValue === null) {
            Setting::where('key', 'znuny_ticket_article_cache_ttl_minutes')->delete();
        } else {
            Setting::updateOrCreate(
                ['key' => 'znuny_ticket_article_cache_ttl_minutes'],
                ['value' => $invalidValue, 'type' => 'string']
            );
        }
        app(SettingsService::class)->clearAllCaches();

        $clientMock = Mockery::mock(ZnunyClient::class);
        $clientMock->shouldReceive('getTicketArticles')->times(1)->with(123)->andReturn([['article_id' => 1]]);
        $clientMock->shouldReceive('getTicketArticles')->times(1)->with(123)->andReturn([['article_id' => 2]]);

        $service = new ZnunyTicketArticleCacheService($clientMock);

        try {
            $this->travelTo(now());

            $result = $service->get(123);
            $this->assertEquals(1, $result[0]['article_id']);

            // After 14 minutes, still cached
            $this->travel(14)->minutes();
            $result = $service->get(123);
            $this->assertEquals(1, $result[0]['article_id']);

            // After 16 minutes total, expires
            $this->travel(2)->minutes();
            $result = $service->get(123);
            $this->assertEquals(2, $result[0]['article_id']);
        } finally {
            $this->travelBack();
        }
    }

    public function test_forget_all_is_monotonic()
    {
        $clientMock = Mockery::mock(ZnunyClient::class);
        $service = new ZnunyTicketArticleCacheService($clientMock);

        $service->forgetAll();
        $gen1 = $service->getGeneration();

        $service->forgetAll();
        $gen2 = $service->getGeneration();

        $this->assertGreaterThan($gen1, $gen2);
    }
}
