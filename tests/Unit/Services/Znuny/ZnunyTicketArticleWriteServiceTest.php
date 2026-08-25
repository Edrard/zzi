<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyTicketArticleCacheService;
use App\Services\Znuny\ZnunyTicketArticleWriteService;
use Exception;
use Mockery\MockInterface;
use Tests\TestCase;

class ZnunyTicketArticleWriteServiceTest extends TestCase
{
    public function test_create_ticket_article_success_invalidates_cache()
    {
        $clientMock = $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('createTicketArticle')
                ->once()
                ->with(123, 'Subject', 'Body', false)
                ->andReturn([
                    'success' => true,
                    'article_id' => 456,
                    'ticket_id' => 123,
                    'ticket_number' => '12345678',
                ]);
        });

        $cacheMock = $this->mock(ZnunyTicketArticleCacheService::class, function (MockInterface $mock) {
            $mock->shouldReceive('forget')
                ->once()
                ->with(123);
        });

        $service = new ZnunyTicketArticleWriteService($clientMock, $cacheMock);

        $result = $service->createTicketArticle(123, 'Subject', 'Body', false);

        $this->assertTrue($result['success']);
        $this->assertEquals(456, $result['article_id']);
    }

    public function test_create_ticket_article_failure_does_not_invalidate_cache()
    {
        $clientMock = $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('createTicketArticle')
                ->once()
                ->with(123, 'Subject', 'Body', true)
                ->andReturn([
                    'success' => false,
                    'errors' => ['API Error'],
                ]);
        });

        $cacheMock = $this->mock(ZnunyTicketArticleCacheService::class, function (MockInterface $mock) {
            $mock->shouldReceive('forget')->never();
        });

        $service = new ZnunyTicketArticleWriteService($clientMock, $cacheMock);

        $result = $service->createTicketArticle(123, 'Subject', 'Body', true);

        $this->assertFalse($result['success']);
        $this->assertContains('API Error', $result['errors']);
    }

    public function test_create_ticket_article_exception_handled()
    {
        $clientMock = $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('createTicketArticle')
                ->once()
                ->andThrow(new Exception('Network timeout'));
        });

        $cacheMock = $this->mock(ZnunyTicketArticleCacheService::class, function (MockInterface $mock) {
            $mock->shouldReceive('forget')->never();
        });

        $service = new ZnunyTicketArticleWriteService($clientMock, $cacheMock);

        $result = $service->createTicketArticle(123, 'Subject', 'Body', true);

        $this->assertFalse($result['success']);
        $this->assertContains('Network timeout', $result['errors']);
    }
}
