<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\ClosedTicketCacheService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyTicketArticleCacheService;
use App\Services\Znuny\ZnunyTicketCacheService;
use App\Services\Znuny\ZnunyTicketWorkspaceCacheReader;
use App\Services\Znuny\ZnunyTicketWorkspaceTicketRefreshService;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class TicketWorkspaceTicketRefreshTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Redis::flushall();
    }

    public function test_skips_article_refresh_when_unchanged()
    {
        $mockClient = Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldReceive('getTicket')->with(123)->andReturn([
            'TicketID' => 123,
            'ArticleCount' => 5,
            'LastArticleID' => 105,
            'StateType' => 'open',
        ]);

        $mockActiveCache = Mockery::mock(ZnunyTicketCacheService::class)->makePartial();
        $mockActiveCache->shouldReceive('getTicket')->with(123)->andReturn([
            'TicketID' => 123,
            'ArticleCount' => 5,
            'LastArticleID' => 105,
            'LastArticleCreated' => '2023-01-01 10:00:00',
            'StateType' => 'open',
        ]);
        $mockActiveCache->shouldReceive('upsertTicket')->once();
        $mockActiveCache->shouldReceive('forgetTicket')->never();

        $mockClosedCache = Mockery::mock(ClosedTicketCacheService::class)->makePartial();
        $mockClosedCache->shouldReceive('forgetTicket')->with(123)->once();

        $mockArticleCache = Mockery::mock(ZnunyTicketArticleCacheService::class)->makePartial();
        $mockArticleCache->shouldReceive('refresh')->never(); // This proves it skipped

        $mockReader = Mockery::mock(ZnunyTicketWorkspaceCacheReader::class)->makePartial();
        $mockReader->shouldReceive('normalizeSingleTicket')->once()->andReturn(['id' => 123]);

        $service = new ZnunyTicketWorkspaceTicketRefreshService(
            $mockClient,
            $mockArticleCache,
            $mockActiveCache,
            $mockClosedCache,
            $mockReader
        );

        $result = $service->refreshTicket(123);

        $this->assertEquals('open', $result['status']);
        $this->assertEquals(123, $result['ticket']['id']);
    }

    public function test_refreshes_articles_when_article_count_changes()
    {
        $mockClient = Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldReceive('getTicket')->with(123)->andReturn([
            'TicketID' => 123,
            'ArticleCount' => 6, // changed
            'LastArticleID' => 105,
            'StateType' => 'open',
        ]);

        $mockActiveCache = Mockery::mock(ZnunyTicketCacheService::class)->makePartial();
        $mockActiveCache->shouldReceive('getTicket')->with(123)->andReturn([
            'TicketID' => 123,
            'ArticleCount' => 5,
            'LastArticleID' => 105,
            'StateType' => 'open',
        ]);
        $mockActiveCache->shouldReceive('upsertTicket')->once();

        $mockClosedCache = Mockery::mock(ClosedTicketCacheService::class)->makePartial();
        $mockClosedCache->shouldReceive('forgetTicket')->with(123)->once();

        $mockArticleCache = Mockery::mock(ZnunyTicketArticleCacheService::class)->makePartial();
        $mockArticleCache->shouldReceive('refresh')->with(123)->once()->andReturn([
            ['article_id' => 101],
            ['article_id' => 102],
            ['article_id' => 103],
            ['article_id' => 104],
            ['article_id' => 105],
            ['article_id' => 106, 'created_at' => '2023-01-01 11:00:00'],
        ]); // This proves it fetched

        $mockReader = Mockery::mock(ZnunyTicketWorkspaceCacheReader::class)->makePartial();
        $mockReader->shouldReceive('normalizeSingleTicket')->once()->andReturn(['id' => 123]);

        $service = new ZnunyTicketWorkspaceTicketRefreshService(
            $mockClient,
            $mockArticleCache,
            $mockActiveCache,
            $mockClosedCache,
            $mockReader
        );

        $result = $service->refreshTicket(123);
        $this->assertEquals('open', $result['status']);
    }

    public function test_refreshes_articles_when_last_article_id_changes()
    {
        $mockClient = Mockery::mock(ZnunyClient::class)->makePartial();
        $mockClient->shouldReceive('getTicket')->with(123)->andReturn([
            'TicketID' => 123,
            'ArticleCount' => 5,
            'LastArticleID' => 106, // changed
            'StateType' => 'open',
        ]);

        $mockActiveCache = Mockery::mock(ZnunyTicketCacheService::class)->makePartial();
        $mockActiveCache->shouldReceive('getTicket')->with(123)->andReturn([
            'TicketID' => 123,
            'ArticleCount' => 5,
            'LastArticleID' => 105,
            'StateType' => 'open',
        ]);
        $mockActiveCache->shouldReceive('upsertTicket')->once();

        $mockClosedCache = Mockery::mock(ClosedTicketCacheService::class)->makePartial();
        $mockClosedCache->shouldReceive('forgetTicket')->with(123)->once();

        $mockArticleCache = Mockery::mock(ZnunyTicketArticleCacheService::class)->makePartial();
        $mockArticleCache->shouldReceive('refresh')->with(123)->once()->andReturn([
            ['article_id' => 101],
            ['article_id' => 102],
            ['article_id' => 103],
            ['article_id' => 105],
            ['article_id' => 106, 'created_at' => '2023-01-01 11:00:00'],
        ]); // This proves it fetched

        $mockReader = Mockery::mock(ZnunyTicketWorkspaceCacheReader::class)->makePartial();
        $mockReader->shouldReceive('normalizeSingleTicket')->once()->andReturn(['id' => 123]);

        $service = new ZnunyTicketWorkspaceTicketRefreshService(
            $mockClient,
            $mockArticleCache,
            $mockActiveCache,
            $mockClosedCache,
            $mockReader
        );

        $result = $service->refreshTicket(123);
        $this->assertEquals('open', $result['status']);
    }
}
