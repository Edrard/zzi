<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyTicketArticleCacheService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ZnunyTicketArticleCacheServiceTest extends TestCase
{
    public function test_get_fetches_from_client_and_caches_result()
    {
        Cache::flush();

        $clientMock = Mockery::mock(ZnunyClient::class);
        $clientMock->shouldReceive('getTicketArticles')
            ->once()
            ->with(123)
            ->andReturn([['article_id' => 1, 'subject' => 'Test Subject']]);

        $service = new ZnunyTicketArticleCacheService($clientMock);

        // First call should hit the client
        $result1 = $service->get(123);
        $this->assertCount(1, $result1);
        $this->assertEquals(1, $result1[0]['article_id']);

        // Second call should hit the cache (client not called again due to ->once())
        $result2 = $service->get(123);
        $this->assertCount(1, $result2);
        $this->assertEquals(1, $result2[0]['article_id']);
    }

    public function test_get_handles_client_exception_gracefully()
    {
        Cache::flush();

        $clientMock = Mockery::mock(ZnunyClient::class);
        $clientMock->shouldReceive('getTicketArticles')
            ->once()
            ->with(123)
            ->andThrow(new \Exception('API Error'));

        $service = new ZnunyTicketArticleCacheService($clientMock);

        $result = $service->get(123);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]['article_id']);
        $this->assertEquals('Error loading articles', $result[0]['subject']);
    }

    public function test_forget_clears_cache_for_specific_ticket()
    {
        Cache::flush();

        $clientMock = Mockery::mock(ZnunyClient::class);
        $clientMock->shouldReceive('getTicketArticles')->times(2)->with(123)->andReturn([['article_id' => 1]]);

        $service = new ZnunyTicketArticleCacheService($clientMock);

        $service->get(123);
        $service->forget(123);

        // This will trigger the second call to client because cache was cleared
        $service->get(123);
    }

    public function test_refresh_clears_cache_and_fetches_anew()
    {
        Cache::flush();

        $clientMock = Mockery::mock(ZnunyClient::class);
        $clientMock->shouldReceive('getTicketArticles')->times(2)->with(123)->andReturn([['article_id' => 1]]);

        $service = new ZnunyTicketArticleCacheService($clientMock);

        $service->get(123);

        // This will trigger the second call to client
        $service->refresh(123);
    }

    public function test_forget_all_invalidates_all_tickets_by_incrementing_generation()
    {
        Cache::flush();

        $clientMock = Mockery::mock(ZnunyClient::class);
        $clientMock->shouldReceive('getTicketArticles')->times(2)->with(123)->andReturn([['article_id' => 1]]);
        $clientMock->shouldReceive('getTicketArticles')->times(2)->with(456)->andReturn([['article_id' => 2]]);

        $service = new ZnunyTicketArticleCacheService($clientMock);

        $service->get(123);
        $service->get(456);

        // Invalidate globally
        $service->forgetAll();

        // These should trigger new calls to the client
        $service->get(123);
        $service->get(456);
    }
}
