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

    public function test_process_response_unwraps_data_array()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/TicketState*' => Http::response([
                'Success' => 1,
                'Data' => [
                    'TicketStates' => [
                        ['ID' => 1, 'Name' => 'new'],
                    ],
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $states = $client->getTicketStates();

        $this->assertCount(1, $states);
        $this->assertEquals('new', $states[0]['Name']);
    }

    public function test_authfail_error_code_triggers_session_retry()
    {
        // First request returns AuthFail, second returns Success
        Http::fake([
            'https://example.invalid/api/Session*' => Http::sequence()
                ->push(['SessionID' => 'fake_session_1'], 200)
                ->push(['SessionID' => 'fake_session_2'], 200),
            'https://example.invalid/api/TicketState*' => Http::sequence()
                ->push([
                    'Error' => [
                        'ErrorCode' => 'ZnunyAgentList.AuthFail',
                        'ErrorMessage' => 'Session invalid',
                    ],
                ], 200)
                ->push([
                    'Success' => 1,
                    'Data' => [
                        'TicketStates' => [
                            ['ID' => 1, 'Name' => 'new'],
                        ],
                    ],
                ], 200),
        ]);

        $client = new ZnunyClient;
        $states = $client->getTicketStates();

        $this->assertCount(1, $states);
        $this->assertEquals('new', $states[0]['Name']);
    }

    public function test_process_response_throws_exception_on_other_error()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/TicketState*' => Http::response([
                'Error' => [
                    'ErrorCode' => 'ZnunyAgentList.SomeError',
                    'ErrorMessage' => 'Something went wrong',
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Znuny API Error: [ZnunyAgentList.SomeError] Something went wrong');

        $client->getTicketStates();
    }
}
