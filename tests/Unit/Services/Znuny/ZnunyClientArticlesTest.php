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
            'https://example.invalid/api/ZnunyAgentListTicket/123*' => Http::response([
                'Articles' => [
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
                        'Created' => '2023-01-01 12:00:00',
                        'Changed' => '2023-01-01 12:00:00',
                        'HTMLBodyAvailable' => 1,
                        'HTMLBodyContentType' => 'text/html; charset=windows-1251',
                        'HTMLBodyContent' => base64_encode(mb_convert_encoding('Test Body 1 HTML', 'windows-1251', 'UTF-8')),
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
        $this->assertTrue($articles[0]['html_body_available']);
        $this->assertEquals('Test Body 1 HTML', $articles[0]['html_body']);
        $this->assertEquals('text/html; charset=windows-1251', $articles[0]['html_body_content_type']);

        $this->assertEquals(2, $articles[1]['article_id']);
        $this->assertEquals('Test Subject 2', $articles[1]['subject']);
        $this->assertFalse($articles[1]['html_body_available']);
        $this->assertArrayNotHasKey('html_body', $articles[1]);

        Http::assertSent(function (Request $request) {
            if (str_contains($request->url(), 'api/ZnunyAgentListTicket/123')) {
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
            'https://example.invalid/api/ZnunyAgentListTicket/123*' => Http::response([
                'Articles' => [
                    [
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
            'https://example.invalid/api/ZnunyAgentListTicket/123*' => Http::response([
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

    public function test_get_ticket_articles_throws_when_custom_endpoint_returns_found_zero(): void
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/ZnunyAgentListTicket/123*' => Http::response([
                'Found' => 0,
                'Ticket' => null,
                'Warnings' => ['Ticket not found.'],
            ], 200),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Ticket not found in Znuny.');

        (new ZnunyClient)->getTicketArticles(123);
    }

    public function test_get_ticket_articles_converts_windows_1251_html_and_preserves_cid_order(): void
    {
        $htmlUtf8 = '<p>Помилка: тест</p><img src="cid:first@example"><img src="cid:second@example">';
        $htmlWindows1251 = mb_convert_encoding($htmlUtf8, 'Windows-1251', 'UTF-8');

        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/ZnunyAgentListTicket/321*' => Http::response([
                'Found' => 1,
                'Ticket' => ['TicketID' => 321],
                'Articles' => [
                    [
                        'TicketID' => 321,
                        'ArticleID' => 30,
                        'ArticleNumber' => 3,
                        'Subject' => 'HTML alternative',
                        'Body' => 'Original plain body',
                        'MimeType' => 'text/plain',
                        'ContentType' => 'text/plain; charset=utf-8',
                        'Created' => '2026-08-24 10:01:02',
                        'HTMLBodyAvailable' => 1,
                        'HTMLBodyContentType' => 'text/html; charset="windows-1251"',
                        'HTMLBodyContent' => base64_encode($htmlWindows1251),
                    ],
                    [
                        'TicketID' => 321,
                        'ArticleID' => 10,
                        'ArticleNumber' => 1,
                        'Subject' => 'Second',
                        'Body' => 'Second',
                        'MimeType' => 'text/plain',
                        'ContentType' => 'text/plain; charset=utf-8',
                        'Created' => '2026-08-24 10:00:00',
                        'HTMLBodyAvailable' => 0,
                    ],
                ],
            ], 200),
        ]);

        $articles = (new ZnunyClient)->getTicketArticles(321);

        $this->assertSame([30, 10], array_column($articles, 'article_id'));
        $this->assertSame('2026-08-24 10:01:02', $articles[0]['created_at']);
        $this->assertSame('Original plain body', $articles[0]['body']);
        $this->assertSame('text/plain', $articles[0]['mime_type']);
        $this->assertSame('text/plain; charset=utf-8', $articles[0]['content_type']);
        $this->assertTrue($articles[0]['html_body_available']);
        $this->assertSame($htmlUtf8, $articles[0]['html_body']);

        $firstPos = strpos($articles[0]['html_body'], 'cid:first@example');
        $secondPos = strpos($articles[0]['html_body'], 'cid:second@example');
        $this->assertNotFalse($firstPos);
        $this->assertNotFalse($secondPos);
        $this->assertTrue($firstPos < $secondPos);
    }

    public function test_create_ticket_article_sends_correct_payload_for_note()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/Ticket/123*' => Http::response([
                'ArticleID' => 456,
                'TicketID' => 123,
                'TicketNumber' => '12345678',
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->createTicketArticle(123, 'Test Subject', 'Test Body', false);

        $this->assertTrue($response['success']);
        $this->assertEquals(456, $response['article_id']);
        $this->assertEquals(123, $response['ticket_id']);
        $this->assertEquals('12345678', $response['ticket_number']);

        Http::assertSent(function (Request $request) {
            if ($request->method() === 'PATCH' && str_contains($request->url(), 'api/Ticket/123')) {
                return $request['Ticket']['TicketID'] == 123 &&
                       $request['Article']['Subject'] === 'Test Subject' &&
                       $request['Article']['Body'] === 'Test Body' &&
                       $request['Article']['ContentType'] === 'text/plain; charset=utf-8' &&
                       $request['Article']['MimeType'] === 'text/plain' &&
                       $request['Article']['Charset'] === 'utf-8' &&
                       $request['Article']['IsVisibleForCustomer'] === 0;
            }

            return true;
        });
    }

    public function test_create_ticket_article_sends_correct_payload_for_article()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/Ticket/123*' => Http::response([
                'ArticleID' => 457,
                'TicketID' => 123,
                'TicketNumber' => '12345678',
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->createTicketArticle(123, 'Test Article Subject', 'Test Article Body', true);

        $this->assertTrue($response['success']);
        $this->assertEquals(457, $response['article_id']);
        $this->assertEquals(123, $response['ticket_id']);
        $this->assertEquals('12345678', $response['ticket_number']);

        Http::assertSent(function (Request $request) {
            if ($request->method() === 'PATCH' && str_contains($request->url(), 'api/Ticket/123')) {
                return $request['Ticket']['TicketID'] == 123 &&
                       $request['Article']['Subject'] === 'Test Article Subject' &&
                       $request['Article']['Body'] === 'Test Article Body' &&
                       $request['Article']['ContentType'] === 'text/plain; charset=utf-8' &&
                       $request['Article']['MimeType'] === 'text/plain' &&
                       $request['Article']['Charset'] === 'utf-8' &&
                       $request['Article']['IsVisibleForCustomer'] === 1;
            }

            return true;
        });
    }

    public function test_create_ticket_article_handles_failure_response()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/Ticket/123*' => Http::response([
                'Errors' => ['Something went wrong'],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->createTicketArticle(123, 'Fail Subject', 'Fail Body', false);

        $this->assertFalse($response['success']);
        $this->assertContains('Something went wrong', $response['errors']);
        $this->assertContains('Missing ArticleID or TicketID in response', $response['errors']);
    }

    public function test_get_ticket_articles_invalid_html_alternative_falls_back_to_original_body(): void
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/ZnunyAgentListTicket/555*' => Http::response([
                'Found' => 1,
                'Ticket' => ['TicketID' => 555],
                'Articles' => [
                    [
                        'TicketID' => 555,
                        'ArticleID' => 1,
                        'Body' => 'fallback one',
                        'MimeType' => 'text/plain',
                        'ContentType' => 'text/plain; charset=utf-8',
                        'HTMLBodyAvailable' => 1,
                        'HTMLBodyContentType' => 'text/html; charset=utf-8',
                        'HTMLBodyContent' => '%%%not-base64%%%',
                    ],
                    [
                        'TicketID' => 555,
                        'ArticleID' => 2,
                        'Body' => 'fallback two',
                        'MimeType' => 'text/plain',
                        'ContentType' => 'text/plain; charset=utf-8',
                        'HTMLBodyAvailable' => 1,
                        'HTMLBodyContentType' => 'text/html; charset=definitely-not-a-real-charset',
                        'HTMLBodyContent' => base64_encode('<p>html</p>'),
                    ],
                ],
            ], 200),
        ]);

        $articles = (new ZnunyClient)->getTicketArticles(555);

        $this->assertSame(['fallback one', 'fallback two'], array_column($articles, 'body'));

        foreach ($articles as $article) {
            $this->assertFalse($article['html_body_available']);
            $this->assertArrayNotHasKey('html_body', $article);
            $this->assertArrayNotHasKey('html_body_content_type', $article);
        }
    }

    public function test_search_metadata_normalizes_html_body_article_count(): void
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/ZnunyAgentListTicketSearch*' => Http::response([
                'Tickets' => [
                    ['TicketID' => 1, 'HTMLBodyArticleCount' => 3],
                    ['TicketID' => 2, 'HTMLBodyArticleCount' => '2'],
                    ['TicketID' => 3, 'HTMLBodyArticleCount' => 0],
                    ['TicketID' => 4, 'HTMLBodyArticleCount' => -1],
                    ['TicketID' => 5, 'HTMLBodyArticleCount' => 'bad'],
                    ['TicketID' => 6],
                ],
                'Count' => 6,
                'TotalCount' => 6,
            ], 200),
        ]);

        $result = (new ZnunyClient)->searchTicketsWithMetadata(['StateType' => 'open']);

        $this->assertSame(
            [3, 2, 0, 0, 0, 0],
            array_column($result['tickets'], 'HTMLBodyArticleCount')
        );
    }
}
