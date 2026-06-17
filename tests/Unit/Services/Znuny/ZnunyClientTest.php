<?php

namespace Tests\Unit\Services\Znuny;

use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\Znuny\ZnunyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZnunyClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);
    }

    public function test_create_ticket_success()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/Ticket*' => Http::response([
                'TicketID' => 12345,
                'TicketNumber' => 'TN1234567890',
            ], 200),
        ]);

        $client = new ZnunyClient;

        $response = $client->createTicket([
            'Ticket' => [
                'Title' => 'Test Ticket',
            ],
            'Article' => [
                'Body' => 'Test Body',
            ],
        ]);

        $this->assertTrue($response['success']);
        $this->assertEquals(12345, $response['ticket_id']);
        $this->assertEquals('TN1234567890', $response['ticket_number']);
        $this->assertEmpty($response['warnings']);
        $this->assertEmpty($response['errors']);
    }

    public function test_create_ticket_missing_ticket_number()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/Ticket*' => Http::response([
                'TicketID' => 12345,
            ], 200),
        ]);

        $client = new ZnunyClient;

        $response = $client->createTicket([
            'Ticket' => [
                'Title' => 'Test Ticket',
            ],
        ]);

        $this->assertFalse($response['success']);
        $this->assertNull($response['ticket_id']);
        $this->assertNull($response['ticket_number']);
        $this->assertContains('Missing TicketID or TicketNumber in response', $response['errors']);
    }

    public function test_create_ticket_api_error()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/Ticket*' => Http::response([
                'Errors' => ['Some API error'],
            ], 200),
        ]);

        $client = new ZnunyClient;

        $response = $client->createTicket([
            'Ticket' => [
                'Title' => 'Test Ticket',
            ],
        ]);

        $this->assertFalse($response['success']);
        $this->assertNull($response['ticket_id']);
        $this->assertNull($response['ticket_number']);
        $this->assertContains('Some API error', $response['errors']);
    }
}
