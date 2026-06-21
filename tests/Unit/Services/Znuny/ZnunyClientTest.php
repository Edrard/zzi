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

    public function test_connection_returns_success_when_all_endpoints_work()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/Health*' => Http::response(['Success' => 1], 200),
            'https://example.invalid/api/SystemConfig*' => Http::response(['Plugin' => 'ZnunyAgentList'], 200),
            'https://example.invalid/api/Agent*' => Http::response(['Agents' => [['UserID' => 1, 'UserLogin' => 'agent1']]], 200),
            'https://example.invalid/api/Queue*' => Http::response(['Queues' => [['QueueID' => 1, 'Name' => 'q1']]], 200),
            'https://example.invalid/api/TicketState*' => Http::response(['TicketStates' => [['ID' => 1, 'Name' => 'new']]], 200),
        ]);

        $client = new ZnunyClient;
        $result = $client->testConnection();

        $this->assertEquals('success', $result['status']);
        $this->assertTrue($result['checks']['session']);
        $this->assertTrue($result['checks']['health']);
        $this->assertTrue($result['checks']['system_config']);
        $this->assertEquals(1, $result['counts']['agents']);
        $this->assertEquals(1, $result['counts']['queues']);
        $this->assertEquals(1, $result['counts']['states']);
        $this->assertEmpty($result['warnings']);
        $this->assertEmpty($result['errors']);
    }

    public function test_connection_returns_partial_when_optional_ticket_fails()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/Health*' => Http::response(['Success' => 1], 200),
            'https://example.invalid/api/SystemConfig*' => Http::response(['Plugin' => 'ZnunyAgentList'], 200),
            'https://example.invalid/api/Agent*' => Http::response(['Agents' => [['UserID' => 1, 'UserLogin' => 'agent1']]], 200),
            'https://example.invalid/api/Queue*' => Http::response(['Queues' => [['QueueID' => 1, 'Name' => 'q1']]], 200),
            'https://example.invalid/api/TicketState*' => Http::response(['TicketStates' => [['ID' => 1, 'Name' => 'new']]], 200),
            'https://example.invalid/api/ZnunyAgentListTicket/123*' => Http::response([
                'Error' => [
                    'ErrorCode' => 'Ticket.NotFound',
                    'ErrorMessage' => 'Ticket 123 not found',
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $result = $client->testConnection(123);

        $this->assertEquals('partial', $result['status']);
        $this->assertFalse($result['checks']['ticket']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('Ticket 123 not found', $result['warnings'][0]);
    }

    public function test_connection_returns_failed_and_strips_credentials_on_auth_failure()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response([
                'Error' => [
                    'ErrorCode' => 'ZnunyAgentList.AuthFail',
                    'ErrorMessage' => 'Invalid password "secret"',
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $result = $client->testConnection();

        $this->assertEquals('failed', $result['status']);
        $this->assertCount(1, $result['errors']);

        // Assert password is redacted
        $this->assertStringContainsString('Invalid password "[redacted]"', $result['errors'][0]);
        $this->assertStringNotContainsString('secret', $result['errors'][0]);
    }

    public function test_connection_returns_failed_on_transport_failure()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response('', 500),
        ]);

        $client = new ZnunyClient;
        $result = $client->testConnection();

        $this->assertEquals('failed', $result['status']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('HTTP request failed with status 500', $result['errors'][0]);
    }

    public function test_close_ticket_normalization_success()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/TicketClose*' => Http::response([
                'Success' => 1,
                'Data' => [
                    'Ticket' => [
                        'TicketID' => 57115,
                        'TicketNumber' => '2026061846000189',
                        'State' => 'closed successful',
                        'StateType' => 'closed',
                    ],
                    'ArticleID' => 339513,
                    'State' => 'closed successful',
                    'Warnings' => [],
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->closeTicket(57115, ['Reason' => 'Test']);

        $this->assertTrue($response['success']);
        $this->assertEquals(57115, $response['ticket_id']);
        $this->assertEquals('2026061846000189', $response['ticket_number']);
        $this->assertEquals('closed successful', $response['state']);
        $this->assertEquals('closed', $response['state_type']);
        $this->assertEquals(339513, $response['article_id']);
        $this->assertEmpty($response['errors']);
    }

    public function test_reopen_ticket_normalization_success()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/TicketReopen*' => Http::response([
                'Success' => 1,
                'Data' => [
                    'Ticket' => [
                        'TicketID' => 57115,
                        'TicketNumber' => '2026061846000189',
                        'State' => 'open',
                        'StateType' => 'open',
                    ],
                    'ArticleID' => 339514,
                    'State' => 'open',
                    'Reason' => 'Problem reappeared.',
                    'Warnings' => [],
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->reopenTicket(57115, ['Reason' => 'Test']);

        $this->assertTrue($response['success']);
        $this->assertEquals(57115, $response['ticket_id']);
        $this->assertEquals('2026061846000189', $response['ticket_number']);
        $this->assertEquals('open', $response['state']);
        $this->assertEquals('open', $response['state_type']);
        $this->assertEquals(339514, $response['article_id']);
        $this->assertEmpty($response['errors']);
    }

    public function test_reopen_ticket_normalization_business_error()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/TicketReopen*' => Http::response([
                'Success' => 1,
                'Data' => [
                    'Ticket' => [
                        'TicketID' => 57115,
                        'TicketNumber' => '2026061846000189',
                        'State' => 'open',
                        'StateType' => 'open',
                    ],
                    'Errors' => ['Ticket is not closed.'],
                    'Warnings' => [],
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->reopenTicket(57115, ['Reason' => 'Test']);

        $this->assertFalse($response['success']);
        $this->assertContains('Ticket is not closed.', $response['errors']);
    }
}
