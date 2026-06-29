<?php

namespace Tests\Unit\Services\Znuny;

use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\Znuny\ZnunyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZnunyClientArticlesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);
    }

    public function test_get_ticket_articles_sends_correct_parameters_and_normalizes_response()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/Ticket/123*' => Http::response([
                'Ticket' => [
                    [
                        'TicketID' => 123,
                        'Article' => [
                            [
                                'ArticleID' => 1,
                                'ArticleNumber' => 1,
                                'TicketID' => 123,
                                'Subject' => 'Test Subject 1',
                                'Body' => 'Test Body 1',
                                'From' => 'test@example.com',
                                'To' => 'support@example.com',
                                'SenderType' => 'customer',
                                'CommunicationChannel' => 'Email',
                                'IsVisibleForCustomer' => 1,
                                'MimeType' => 'text/plain',
                                'ContentType' => 'text/plain; charset=utf-8',
                                'CreateTime' => '2023-01-01 12:00:00',
                                'ChangeTime' => '2023-01-01 12:00:00',
                            ],
                            [
                                'ArticleID' => 2,
                                'ArticleNumber' => 2,
                                'TicketID' => 123,
                                'Subject' => 'Test Subject 2',
                                'Body' => 'Test Body 2',
                                'From' => 'agent@example.com',
                                'To' => 'test@example.com',
                                'SenderType' => 'agent',
                                'CommunicationChannel' => 'Email',
                                'IsVisibleForCustomer' => 1,
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $articles = $client->getTicketArticles(123);

        $this->assertCount(2, $articles);

        $this->assertEquals(1, $articles[0]['article_id']);
        $this->assertEquals(1, $articles[0]['article_number']);
        $this->assertEquals('Test Subject 1', $articles[0]['subject']);
        $this->assertEquals('Test Body 1', $articles[0]['body']);
        $this->assertEquals('test@example.com', $articles[0]['from']);
        $this->assertTrue($articles[0]['is_visible_for_customer']);
        $this->assertEquals('customer', $articles[0]['sender_type']);
        $this->assertEquals('Email', $articles[0]['communication_channel']);

        $this->assertEquals(2, $articles[1]['article_id']);
        $this->assertEquals('Test Subject 2', $articles[1]['subject']);

        Http::assertSent(function (Request $request) {
            if (str_contains($request->url(), 'api/Ticket/123')) {
                return $request['AllArticles'] == 1 &&
                       $request['DynamicFields'] == 0 &&
                       $request['Attachments'] == 0;
            }

            return true;
        });
    }

    public function test_get_ticket_articles_handles_single_article()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/Ticket/123*' => Http::response([
                'Ticket' => [
                    'TicketID' => 123,
                    'Article' => [
                        'ArticleID' => 1,
                        'Subject' => 'Test Subject 1',
                    ],
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $articles = $client->getTicketArticles(123);

        $this->assertCount(1, $articles);
        $this->assertEquals(1, $articles[0]['article_id']);
        $this->assertEquals('Test Subject 1', $articles[0]['subject']);
    }

    public function test_get_ticket_articles_handles_missing_articles()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/Ticket/123*' => Http::response([
                'Ticket' => [
                    'TicketID' => 123,
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $articles = $client->getTicketArticles(123);

        $this->assertIsArray($articles);
        $this->assertEmpty($articles);
    }
}
