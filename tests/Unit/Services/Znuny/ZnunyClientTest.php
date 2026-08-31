<?php

namespace Tests\Unit\Services\Znuny;

use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\Znuny\ZnunyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_create_customer_user_success()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/CustomerUser' => Http::response([
                'Success' => 1,
                'Data' => [
                    'Created' => 1,
                    'CustomerUser' => [
                        'UserLogin' => 'testuser',
                        'UserCustomerID' => 'testcompany',
                    ],
                    'Errors' => [],
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;

        $response = $client->createCustomerUser([
            'FirstName' => 'Test',
            'LastName' => 'User',
            'Login' => 'testuser',
            'Email' => 'test@example.com',
            'CustomerID' => 'testcompany',
            'Password' => 'secret123',
            'ValidID' => 1,
            'ArbitraryExtra' => 'should_be_stripped',
        ]);

        $this->assertTrue($response['found']);
        $this->assertTrue($response['created']);
        $this->assertEquals('testuser', $response['login']);
        $this->assertEquals('testcompany', $response['customer_id']);
        $this->assertEmpty($response['errors']);

        Http::assertSent(function (Request $request) {
            if (str_contains($request->url(), '/CustomerUser')) {
                if ($request->method() !== 'POST') {
                    return false;
                }
                $data = $request->data();

                return $data['Login'] === 'testuser'
                    && $data['Email'] === 'test@example.com'
                    && $data['FirstName'] === 'Test'
                    && $data['LastName'] === 'User'
                    && $data['CustomerID'] === 'testcompany'
                    && $data['SessionID'] === 'fake_session'
                    && ! isset($data['Password'])
                    && ! isset($data['ValidID'])
                    && ! isset($data['ArbitraryExtra'])
                    && count($data) === 6;
            }

            return false;
        });
    }

    public function test_create_customer_user_logical_failure()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/CustomerUser' => Http::response([
                'Success' => 1,
                'Data' => [
                    'Created' => 0,
                    'CustomerUser' => null,
                    'Errors' => ['Duplicate Login'],
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;

        $response = $client->createCustomerUser([
            'FirstName' => 'Test',
            'LastName' => 'User',
            'Login' => 'testuser',
            'Email' => 'test@example.com',
            'CustomerID' => 'testcompany',
        ]);

        $this->assertFalse($response['found']);
        $this->assertFalse($response['created']);
        $this->assertContains('Duplicate Login', $response['errors']);
    }

    public function test_create_customer_user_missing_login_in_response()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/CustomerUser' => Http::response([
                'Success' => 1,
                'Data' => [
                    'Created' => 1,
                    'CustomerUser' => [
                        'UserCustomerID' => 'testcompany',
                    ],
                    'Errors' => [],
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;

        $response = $client->createCustomerUser([
            'FirstName' => 'Test',
            'LastName' => 'User',
            'Login' => 'testuser',
            'Email' => 'test@example.com',
            'CustomerID' => 'testcompany',
        ]);

        $this->assertFalse($response['found']);
        $this->assertFalse($response['created']);
        $this->assertContains('CustomerUser login missing in response.', $response['errors']);
    }

    public function test_create_customer_user_api_error()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/CustomerUser' => Http::response([
                'Error' => [
                    'ErrorCode' => 'ZnunyAgentList.SomeError',
                    'ErrorMessage' => 'Something went wrong',
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Znuny API Error: [ZnunyAgentList.SomeError] Something went wrong');

        $client->createCustomerUser([
            'FirstName' => 'Test',
            'LastName' => 'User',
            'Login' => 'testuser',
            'Email' => 'test@example.com',
            'CustomerID' => 'testcompany',
        ]);
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
            'https://example.invalid/api/Queue*' => Http::response(['Queues' => [['QueueID' => 1, 'Name' => 'q1', 'ValidID' => 1]]], 200),
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
            'https://example.invalid/api/Queue*' => Http::response(['Queues' => [['QueueID' => 1, 'Name' => 'q1', 'ValidID' => 1]]], 200),
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

    public function test_search_tickets_returns_empty_when_no_meaningful_filter()
    {
        $client = new ZnunyClient;
        $response = $client->searchTickets([]);
        $this->assertEmpty($response);

        $response = $client->searchTickets(['Limit' => 50]);
        $this->assertEmpty($response);
    }

    public function test_search_tickets_unwraps_tickets_array_and_preserves_new_fields()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/ZnunyAgentListTicketSearch*' => Http::response([
                'Tickets' => [
                    [
                        'TicketID' => 111,
                        'SyncFingerprint' => 'abc',
                        'QueueID' => 5,
                        'OwnerID' => 2,
                        'StateID' => 1,
                        'PriorityID' => 3,
                        'TypeID' => 4,
                        'ServiceID' => 6,
                        'SLAID' => 7,
                    ],
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->searchTickets(['Queue' => 'Raw']);

        $this->assertCount(1, $response);
        $this->assertEquals(111, $response[0]['TicketID']);
        $this->assertEquals('abc', $response[0]['SyncFingerprint']);
        $this->assertEquals(5, $response[0]['QueueID']);
        $this->assertEquals(2, $response[0]['OwnerID']);
        $this->assertEquals(1, $response[0]['StateID']);
        $this->assertEquals(3, $response[0]['PriorityID']);
        $this->assertEquals(4, $response[0]['TypeID']);
        $this->assertEquals(6, $response[0]['ServiceID']);
        $this->assertEquals(7, $response[0]['SLAID']);
    }

    public function test_search_tickets_unwraps_single_ticket()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/ZnunyAgentListTicketSearch*' => Http::response([
                'Ticket' => [
                    'TicketID' => 333,
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->searchTickets(['TicketNumber' => '1234']);

        $this->assertCount(1, $response);
        $this->assertEquals(333, $response[0]['TicketID']);
    }

    public function test_search_tickets_ignores_non_ticket_metadata_safely()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/ZnunyAgentListTicketSearch*' => Http::response([
                'Count' => 2,
                'Limit' => 10,
                'Offset' => 0,
                'Warnings' => ['Some warning'],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->searchTickets(['Queue' => 'Raw']);

        $this->assertEmpty($response);
    }

    public function test_search_tickets_with_metadata_returns_full_metadata()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/ZnunyAgentListTicketSearch*' => Http::response([
                'Tickets' => [
                    ['TicketID' => 111],
                    ['TicketID' => 222],
                ],
                'Count' => 2,
                'TotalCount' => 50,
                'Limit' => 2,
                'Offset' => 0,
                'Warnings' => ['Warning 1'],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->searchTicketsWithMetadata([
            'StateType' => 'new,open',
            'SortBy' => 'Changed',
            'SortDirection' => 'DESC',
        ]);

        $this->assertCount(2, $response['tickets']);
        $this->assertEquals(2, $response['count']);
        $this->assertEquals(50, $response['total_count']);
        $this->assertEquals(2, $response['limit']);
        $this->assertEquals(0, $response['offset']);
        $this->assertEquals('Changed', $response['sort_by']);
        $this->assertEquals('DESC', $response['sort_direction']);
        $this->assertFalse($response['count_only']);
        $this->assertEquals(['Warning 1'], $response['warnings']);
    }

    public function test_search_tickets_with_metadata_count_only()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/ZnunyAgentListTicketSearch*' => Http::response([
                'Count' => 0,
                'TotalCount' => 150,
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->searchTicketsWithMetadata([
            'StateType' => 'new,open,pending reminder,pending auto',
            'CountOnly' => 1,
        ]);

        $this->assertEmpty($response['tickets']);
        $this->assertEquals(0, $response['count']);
        $this->assertEquals(150, $response['total_count']);
        $this->assertTrue($response['count_only']);

        Http::assertSent(function (Request $request) {
            if (str_contains($request->url(), 'ZnunyAgentListTicketSearch')) {
                return $request['StateType'] === 'new,open,pending reminder,pending auto' &&
                       $request['CountOnly'] == 1;
            }

            return true;
        });
    }

    public function test_search_tickets_with_metadata_normalizes_numeric_strings()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/ZnunyAgentListTicketSearch*' => Http::response([
                'Tickets' => [
                    [
                        'TicketID' => '111',
                        'QueueID' => '5',
                    ],
                ],
                'Count' => '1',
                'TotalCount' => '1',
                'Limit' => '10',
                'Offset' => '0',
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->searchTicketsWithMetadata(['Queue' => 'Raw']);

        $this->assertSame(1, $response['count']);
        $this->assertSame(1, $response['total_count']);
        $this->assertSame(10, $response['limit']);
        $this->assertSame(0, $response['offset']);
        $this->assertSame(111, $response['tickets'][0]['TicketID']);
        $this->assertSame(5, $response['tickets'][0]['QueueID']);
    }

    public function test_unlock_ticket_normalization_success()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/TicketUnlock*' => Http::response([
                'Success' => 1,
                'Data' => [
                    'Ticket' => [
                        'TicketID' => 57115,
                        'TicketNumber' => '2026061846000189',
                        'State' => 'closed successful',
                        'StateType' => 'closed',
                        'LockID' => 1,
                        'Lock' => 'unlock',
                    ],
                    'Lock' => 'unlock',
                    'Warnings' => [],
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->unlockTicket(57115);

        $this->assertTrue($response['success']);
        $this->assertEquals(57115, $response['ticket_id']);
        $this->assertEquals('2026061846000189', $response['ticket_number']);
        $this->assertEquals('unlock', $response['lock']);
        $this->assertEmpty($response['errors']);
    }

    public function test_unlock_ticket_normalization_error()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/TicketUnlock*' => Http::response([
                'Success' => 1,
                'Data' => [
                    'Ticket' => [
                        'TicketID' => 57115,
                        'TicketNumber' => '2026061846000189',
                        'Lock' => 'lock',
                    ],
                    'Errors' => ['Ticket could not be unlocked.'],
                    'Warnings' => [],
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->unlockTicket(57115);

        $this->assertFalse($response['success']);
        $this->assertContains('Ticket could not be unlocked.', $response['errors']);
    }

    public function test_get_queue_assignable_agents_normalization()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/Queue/1/AssignableAgents*' => Http::response([
                'Agents' => [
                    [
                        'UserID' => 12,
                        'UserLogin' => 'john.doe',
                        'UserFirstname' => 'John',
                        'UserLastname' => 'Doe',
                        'UserFullname' => 'John Doe',
                    ],
                    [
                        'UserID' => 15,
                        'UserLogin' => 'jane.smith',
                        'UserFullname' => 'Jane Smith',
                    ],
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $agents = $client->getQueueAssignableAgents(1);

        $this->assertCount(2, $agents);
        $this->assertEquals(15, $agents[0]['id']);
        $this->assertEquals('jane.smith', $agents[0]['login']);
        $this->assertEquals('Jane Smith <jane.smith>', $agents[0]['label']);

        $this->assertEquals(12, $agents[1]['id']);
        $this->assertEquals('john.doe', $agents[1]['login']);
        $this->assertEquals('John Doe <john.doe>', $agents[1]['label']);
    }

    public function test_get_agent_assignable_queues_normalization()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/Agent/12/AssignableQueues*' => Http::response([
                'Queues' => [
                    [
                        'QueueID' => 2,
                        'Name' => 'Network',
                        'GroupID' => 5,
                    ],
                    [
                        'QueueID' => 1,
                        'Name' => 'Hardware',
                    ],
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $queues = $client->getAgentAssignableQueues(12);

        $this->assertCount(2, $queues);
        $this->assertEquals(1, $queues[0]['id']);
        $this->assertEquals('Hardware', $queues[0]['name']);

        $this->assertEquals(2, $queues[1]['id']);
        $this->assertEquals('Network', $queues[1]['name']);
    }

    public function test_validate_ticket_move_assign_passes_payload()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/TicketMoveAssign/Validate*' => function (Request $request) {
                if ($request['TicketID'] === 123 && $request['QueueName'] === 'Raw') {
                    return Http::response(['Valid' => 1], 200);
                }

                return Http::response(['Valid' => 0], 200);
            },
        ]);

        $client = new ZnunyClient;
        $response = $client->validateTicketMoveAssign(['TicketID' => 123, 'QueueName' => 'Raw']);

        $this->assertEquals(1, $response['Valid']);
    }

    public function test_move_assign_ticket_passes_payload()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/TicketMoveAssign*' => function (Request $request) {
                if ($request['TicketID'] === 123 && $request['OwnerLogin'] === 'john.doe') {
                    return Http::response(['Success' => 1], 200);
                }

                return Http::response(['Success' => 0], 200);
            },
        ]);

        $client = new ZnunyClient;
        $response = $client->moveAssignTicket(['TicketID' => 123, 'OwnerLogin' => 'john.doe']);

        $this->assertEquals(1, $response['Success']);
    }

    public function test_search_tickets_preserves_title_and_created_dates()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/ZnunyAgentListTicketSearch*' => function (Request $request) {
                if (isset($request['Title']) && $request['Title'] === '*MARKER*' &&
                    isset($request['CreatedFrom']) && $request['CreatedFrom'] === '2026-07-27 09:00:00' &&
                    isset($request['CreatedTo']) && $request['CreatedTo'] === '2026-07-27 11:00:00') {
                    return Http::response(['Tickets' => []], 200);
                }

                return Http::response(['Errors' => ['Filter mismatch']], 400);
            },
        ]);

        $client = new ZnunyClient;
        $response = $client->searchTicketsWithMetadata([
            'Title' => '*MARKER*',
            'CreatedFrom' => '2026-07-27 09:00:00',
            'CreatedTo' => '2026-07-27 11:00:00',
        ]);

        $this->assertArrayHasKey('tickets', $response);
        $this->assertArrayNotHasKey('errors', $response);
    }

    public static function provideInlineAttachmentCounts(): array
    {
        return [
            'valid integer' => [3, 3],
            'valid numeric string' => ['3', 3],
            'zero integer' => [0, 0],
            'zero numeric string' => ['0', 0],
            'missing field' => [null, 0],
            'negative integer' => [-1, 0],
            'negative string' => ['-1', 0],
            'empty string' => ['', 0],
            'partially numeric string' => ['3abc', 0],
            'float' => [3.5, 0],
            'boolean true' => [true, 0],
            'boolean false' => [false, 0],
            'array' => [[3], 0],
        ];
    }

    #[DataProvider('provideInlineAttachmentCounts')]
    public function test_search_tickets_normalizes_inline_attachment_count($inputValue, int $expectedValue)
    {
        $ticketPayload = [
            'TicketID' => 111,
            'Title' => 'Test',
        ];

        // Only add if not null to test the missing key case
        if ($inputValue !== null) {
            $ticketPayload['InlineAttachmentCount'] = $inputValue;
        }

        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/ZnunyAgentListTicketSearch*' => Http::response([
                'Tickets' => [
                    $ticketPayload,
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->searchTickets(['Queue' => 'Raw']);

        $this->assertCount(1, $response);
        $this->assertEquals(111, $response[0]['TicketID']);
        $this->assertEquals('Test', $response[0]['Title']);
        $this->assertEquals($expectedValue, $response[0]['InlineAttachmentCount']);
    }

    public function test_get_inline_attachment_success()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/ZnunyAgentListTicket/123/Article/456/InlineAttachment*' => Http::response([
                'Found' => 1,
                'TicketID' => '123',
                'ArticleID' => '456',
                'Filename' => 'image.png',
                'ContentType' => 'image/png',
                'ContentID' => 'image1@domain.com',
                'FilesizeRaw' => 1234,
                'Content' => base64_encode('fake_image_bytes'),
            ], 200),
        ]);

        $client = new ZnunyClient;
        $result = $client->getInlineAttachment(123, 456, 'image1@domain.com');

        $this->assertEquals([
            'found' => true,
            'ticket_id' => '123',
            'article_id' => '456',
            'filename' => 'image.png',
            'content_type' => 'image/png',
            'content_id' => 'image1@domain.com',
            'filesize_raw' => 1234,
            'content_base64' => base64_encode('fake_image_bytes'),
        ], $result);

        Http::assertSent(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return $path === '/api/ZnunyAgentListTicket/123/Article/456/InlineAttachment' &&
                   ! str_contains($path, 'image1') &&
                   $request['SessionID'] === 'fake_session' &&
                   $request['ContentID'] === 'image1@domain.com';
        });
    }

    public function test_get_inline_attachment_not_found()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/ZnunyAgentListTicket/123/Article/456/InlineAttachment*' => Http::response([
                'Found' => 0,
                'TicketID' => '123',
                'ArticleID' => '456',
                'Errors' => ['Not found error'],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $result = $client->getInlineAttachment(123, 456, 'image1@domain.com');

        $this->assertEquals([
            'found' => false,
            'ticket_id' => '123',
            'article_id' => '456',
            'errors' => ['Not found error'],
        ], $result);
    }

    public function test_get_inline_attachment_throws_on_http_failure()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/ZnunyAgentListTicket/*' => Http::response('Server Error', 500),
        ]);

        $client = new ZnunyClient;

        $this->expectException(\Exception::class);
        $client->getInlineAttachment(123, 456, 'image1@domain.com');
    }

    public function test_get_inline_attachment_throws_on_malformed_found()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/ZnunyAgentListTicket/*' => Http::response([
                'Found' => 2, // invalid
            ], 200),
        ]);

        $client = new ZnunyClient;

        $this->expectException(\Exception::class);
        $client->getInlineAttachment(123, 456, 'image1@domain.com');
    }

    public function test_get_inline_attachment_throws_on_missing_fields()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/ZnunyAgentListTicket/*' => Http::response([
                'Found' => 1,
                'TicketID' => '123',
                'ArticleID' => '456',
                // Missing ContentType, ContentID, Content
            ], 200),
        ]);

        $client = new ZnunyClient;

        $this->expectException(\Exception::class);
        $client->getInlineAttachment(123, 456, 'image1@domain.com');
    }

    public function test_get_inline_attachment_throws_on_ticket_id_mismatch()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/ZnunyAgentListTicket/*' => Http::response([
                'Found' => 1,
                'TicketID' => '999', // Mismatch
                'ArticleID' => '456',
                'ContentType' => 'image/png',
                'ContentID' => 'image1@domain.com',
                'Content' => base64_encode('fake_image_bytes'),
            ], 200),
        ]);

        $client = new ZnunyClient;

        $this->expectException(\Exception::class);
        $client->getInlineAttachment(123, 456, 'image1@domain.com');
    }

    public function test_get_inline_attachment_throws_on_article_id_mismatch()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/ZnunyAgentListTicket/*' => Http::response([
                'Found' => 1,
                'TicketID' => '123',
                'ArticleID' => '999', // Mismatch
                'ContentType' => 'image/png',
                'ContentID' => 'image1@domain.com',
                'Content' => base64_encode('fake_image_bytes'),
            ], 200),
        ]);

        $client = new ZnunyClient;

        $this->expectException(\Exception::class);
        $client->getInlineAttachment(123, 456, 'image1@domain.com');
    }

    public function test_get_inline_attachment_rejects_invalid_ticket_id()
    {
        $client = new ZnunyClient;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid TicketID provided.');
        $client->getInlineAttachment(0, 456, 'image1@domain.com');
    }

    public function test_get_inline_attachment_rejects_invalid_article_id()
    {
        $client = new ZnunyClient;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid TicketID provided.'); // Since normalizeTicketId is called for both
        $client->getInlineAttachment(123, 0, 'image1@domain.com');
    }

    public function test_get_ticket_inline_attachment_references_extracts_only_safe_metadata_without_binary_fetch(): void
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/Ticket/123*' => Http::response([
                'Ticket' => [
                    'TicketID' => 123,
                    'Article' => [
                        [
                            'ArticleID' => 456,
                            'Attachment' => [
                                ['ContentID' => 'image1@domain.com'],
                                ['ContentID' => '<image2@domain.com>'],
                                ['Filename' => 'no_content_id.pdf'],
                            ],
                        ],
                        [
                            'ArticleID' => '457',
                            'Attachment' => [
                                'ContentID' => 'image3@domain.com',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $refs = $client->getTicketInlineAttachmentReferences(123);

        $this->assertSame([
            ['TicketID' => 123, 'ArticleID' => 456, 'ContentID' => 'image1@domain.com'],
            ['TicketID' => 123, 'ArticleID' => 456, 'ContentID' => 'image2@domain.com'],
            ['TicketID' => 123, 'ArticleID' => 457, 'ContentID' => 'image3@domain.com'],
        ], $refs);

        Http::assertSent(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if ($path !== '/api/Ticket/123') {
                return true;
            }

            return $request->method() === 'GET'
                && (int) $request['AllArticles'] === 1
                && (int) $request['Attachments'] === 1
                && (int) $request['GetAttachmentContents'] === 0;
        });

        Http::assertNotSent(
            fn (Request $request) => str_contains(parse_url($request->url(), PHP_URL_PATH) ?? '', '/InlineAttachment')
        );
    }

    public function test_get_ticket_inline_attachment_references_skips_invalid_article_and_content_ids(): void
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/Ticket/123*' => Http::response([
                'Ticket' => [
                    'TicketID' => 123,
                    'Article' => [
                        [
                            'ArticleID' => 0,
                            'Attachment' => [['ContentID' => 'ignored1@domain.com']],
                        ],
                        [
                            'Attachment' => [['ContentID' => 'ignored2@domain.com']],
                        ],
                        [
                            'ArticleID' => 789,
                            'Attachment' => [
                                ['ContentID' => ''],
                                ['ContentID' => "bad\ncontent-id"],
                                ['ContentID' => 123],
                                ['Filename' => 'missing.txt'],
                                ['ContentID' => 'valid@domain.com'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;

        $this->assertSame([
            ['TicketID' => 123, 'ArticleID' => 789, 'ContentID' => 'valid@domain.com'],
        ], $client->getTicketInlineAttachmentReferences(123));
    }

    public function test_get_ticket_inline_attachment_references_handles_no_articles_safely(): void
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

        $this->assertSame([], $client->getTicketInlineAttachmentReferences(123));
    }

    public function test_get_customer_companies_page_success()
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test-session-id'], 200),
            '*/CustomerCompany*' => Http::response([
                'Errors' => [], 'CustomerCompanies' => [
                    ['CustomerID' => 'c1', 'CustomerCompanyName' => 'Company One'],
                    ['CustomerID' => 'c2', 'CustomerCompanyName' => 'Company Two'],
                ],
                'Count' => 2,
                'TotalCount' => 2,
                'Limit' => 100,
                'Offset' => 0,
                'HasMore' => 0,
            ], 200),
        ]);

        $client = new ZnunyClient;
        $result = $client->getCustomerCompaniesPage(0, 100);

        $this->assertEquals(2, $result['count']);
        $this->assertEquals('Company One', $result['companies'][0]['name']);
        $this->assertEquals('c1', $result['companies'][0]['customer_id']);

        Http::assertSent(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_contains($path, '/CustomerCompany')) {
                return $request['SessionID'] === 'test-session-id' &&
                       $request['Offset'] == 0 &&
                       $request['Limit'] == 100 &&
                       ! isset($request['Search']);
            }

            return true;
        });
    }

    public static function provideInvalidCustomerCompanyPages(): array
    {
        return [
            'errors missing' => [
                ['CustomerCompanies' => [], 'Count' => 0, 'TotalCount' => 0, 'Limit' => 100, 'Offset' => 0, 'HasMore' => 0],
                'Missing Errors field in response.',
            ],
            'errors non empty' => [
                ['Errors' => ['Some error']],
                'CustomerCompany API returned errors.',
            ],
            'errors malformed' => [
                ['Errors' => 'Not an array'],
                'Malformed Errors field in response.',
            ],
            'companies missing' => [
                ['Errors' => []],
                'Malformed CustomerCompanies array in response.',
            ],
            'companies malformed' => [
                ['Errors' => [], 'CustomerCompanies' => 'Not an array'],
                'Malformed CustomerCompanies array in response.',
            ],
            'missing count' => [
                ['Errors' => [], 'CustomerCompanies' => []],
                'Missing pagination metadata: Count.',
            ],
            'missing total_count' => [
                ['Errors' => [], 'CustomerCompanies' => [], 'Count' => 0],
                'Missing pagination metadata: TotalCount.',
            ],
            'missing limit' => [
                ['Errors' => [], 'CustomerCompanies' => [], 'Count' => 0, 'TotalCount' => 0],
                'Missing pagination metadata: Limit.',
            ],
            'missing offset' => [
                ['Errors' => [], 'CustomerCompanies' => [], 'Count' => 0, 'TotalCount' => 0, 'Limit' => 100],
                'Missing pagination metadata: Offset.',
            ],
            'missing has_more' => [
                ['Errors' => [], 'CustomerCompanies' => [], 'Count' => 0, 'TotalCount' => 0, 'Limit' => 100, 'Offset' => 0],
                'Missing pagination metadata: HasMore.',
            ],
            'malformed count' => [
                ['Errors' => [], 'CustomerCompanies' => [], 'Count' => 'abc', 'TotalCount' => 0, 'Limit' => 100, 'Offset' => 0, 'HasMore' => 0],
                'Malformed pagination metadata: Count.',
            ],
            'malformed total_count' => [
                ['Errors' => [], 'CustomerCompanies' => [], 'Count' => 0, 'TotalCount' => 'abc', 'Limit' => 100, 'Offset' => 0, 'HasMore' => 0],
                'Malformed pagination metadata: TotalCount.',
            ],
            'malformed limit' => [
                ['Errors' => [], 'CustomerCompanies' => [], 'Count' => 0, 'TotalCount' => 0, 'Limit' => 'abc', 'Offset' => 0, 'HasMore' => 0],
                'Malformed pagination metadata: Limit.',
            ],
            'limit out of bounds' => [
                ['Errors' => [], 'CustomerCompanies' => [], 'Count' => 0, 'TotalCount' => 0, 'Limit' => 101, 'Offset' => 0, 'HasMore' => 0],
                'Pagination metadata Limit out of range.',
            ],
            'limit overflow' => [
                ['Errors' => [], 'CustomerCompanies' => [], 'Count' => 0, 'TotalCount' => 0, 'Limit' => '99999999999999999999999999999', 'Offset' => 0, 'HasMore' => 0],
                'Pagination metadata Limit out of range.',
            ],
            'limit negative' => [
                ['Errors' => [], 'CustomerCompanies' => [], 'Count' => 0, 'TotalCount' => 0, 'Limit' => -1, 'Offset' => 0, 'HasMore' => 0],
                'Pagination metadata Limit out of range.',
            ],
            'limit negative string' => [
                ['Errors' => [], 'CustomerCompanies' => [], 'Count' => 0, 'TotalCount' => 0, 'Limit' => '-1', 'Offset' => 0, 'HasMore' => 0],
                'Malformed pagination metadata: Limit.',
            ],
            'malformed offset' => [
                ['Errors' => [], 'CustomerCompanies' => [], 'Count' => 0, 'TotalCount' => 0, 'Limit' => 100, 'Offset' => 'abc', 'HasMore' => 0],
                'Malformed pagination metadata: Offset.',
            ],
            'malformed has_more' => [
                ['Errors' => [], 'CustomerCompanies' => [], 'Count' => 0, 'TotalCount' => 0, 'Limit' => 100, 'Offset' => 0, 'HasMore' => 'false'],
                'Malformed pagination metadata: HasMore.',
            ],
            'row without id' => [
                ['Errors' => [], 'CustomerCompanies' => [['CustomerCompanyName' => 'Test']], 'Count' => 1, 'TotalCount' => 1, 'Limit' => 100, 'Offset' => 0, 'HasMore' => 0],
                'CustomerCompany row missing valid CustomerID.',
            ],
            'non-scalar id' => [
                ['Errors' => [], 'CustomerCompanies' => [['CustomerID' => ['id'], 'CustomerCompanyName' => 'Test']], 'Count' => 1, 'TotalCount' => 1, 'Limit' => 100, 'Offset' => 0, 'HasMore' => 0],
                'CustomerCompany row missing valid CustomerID.',
            ],
            'missing name' => [
                ['Errors' => [], 'CustomerCompanies' => [['CustomerID' => 'c1']], 'Count' => 1, 'TotalCount' => 1, 'Limit' => 100, 'Offset' => 0, 'HasMore' => 0],
                'CustomerCompany row missing valid CustomerCompanyName.',
            ],
            'non-scalar name' => [
                ['Errors' => [], 'CustomerCompanies' => [['CustomerID' => 'c1', 'CustomerCompanyName' => ['test']]], 'Count' => 1, 'TotalCount' => 1, 'Limit' => 100, 'Offset' => 0, 'HasMore' => 0],
                'CustomerCompany row missing valid CustomerCompanyName.',
            ],
        ];
    }

    #[DataProvider('provideInvalidCustomerCompanyPages')]
    public function test_get_customer_companies_page_strict_validation(array $payload, string $expectedExceptionMessage)
    {
        Http::fake([
            '*/Session*' => Http::response(['SessionID' => 'test-session-id'], 200),
            '*/CustomerCompany*' => Http::response($payload, 200),
        ]);

        $client = new ZnunyClient;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $client->getCustomerCompaniesPage(0, 100);
    }

    public function test_get_customer_companies_page_rejects_negative_offset()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Offset must be >= 0.');

        $client = new ZnunyClient;
        $client->getCustomerCompaniesPage(-1, 100);
    }

    public function test_get_customer_companies_page_rejects_zero_limit()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be between 1 and 100.');

        $client = new ZnunyClient;
        $client->getCustomerCompaniesPage(0, 0);
    }

    public function test_get_customer_companies_page_rejects_large_limit()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be between 1 and 100.');

        $client = new ZnunyClient;
        $client->getCustomerCompaniesPage(0, 101);
    }

    public function test_update_customer_user_uses_patch_encoded_login_and_whitelists_payload()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/CustomerUser/*' => Http::response([
                'Success' => 1,
                'Data' => [
                    'Updated' => 1,
                    'CustomerUser' => [
                        'UserLogin' => 'user+tag@example.com',
                        'UserCustomerID' => 'comp2',
                    ],
                    'Errors' => [],
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;

        $response = $client->updateCustomerUser('user+tag@example.com', [
            'Email' => 'new@example.com',
            'FirstName' => 'New',
            'LastName' => 'Name',
            'CustomerID' => 'comp2',
            'Login' => 'renamed-user@example.com',
            'Password' => 'secret',
            'Valid' => 0,
            'ArbitraryExtra' => 'strip-me',
        ]);

        $this->assertTrue($response['updated']);
        $this->assertSame('user+tag@example.com', $response['login']);
        $this->assertSame('comp2', $response['customer_id']);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), '/CustomerUser/user%2Btag%40example.com')) {
                return false;
            }

            if ($request->method() !== 'PATCH') {
                return false;
            }

            $data = $request->data();

            return ($data['Email'] ?? null) === 'new@example.com'
                && ($data['FirstName'] ?? null) === 'New'
                && ($data['LastName'] ?? null) === 'Name'
                && ($data['CustomerID'] ?? null) === 'comp2'
                && ($data['Login'] ?? null) === 'renamed-user@example.com'
                && ($data['SessionID'] ?? null) === 'fake_session'
                && ! array_key_exists('Password', $data)
                && ! array_key_exists('Valid', $data)
                && ! array_key_exists('ArbitraryExtra', $data)
                && count($data) === 6;
        });
    }

    public function test_update_customer_user_logical_failure()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/CustomerUser/*' => Http::response([
                'Success' => 1,
                'Data' => [
                    'Updated' => 0,
                    'CustomerUser' => null,
                    'Errors' => ['Update rejected'],
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;

        $response = $client->updateCustomerUser('user1', [
            'FirstName' => 'New',
        ]);

        $this->assertFalse($response['updated']);
        $this->assertContains('Update rejected', $response['errors']);
    }
}
