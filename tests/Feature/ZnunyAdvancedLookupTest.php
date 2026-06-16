<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyLookupService;
use App\Services\Znuny\ZnunyTicketDefaultRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZnunyAdvancedLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => SettingsService::encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'znuny_queue_from_host_regex'], ['value' => '^(?<queue>[^\s]+)', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'znuny_customer_user_from_queue_template'], ['value' => '<queue>Clients', 'type' => 'string']);

        Http::fake([
            'https://example.invalid/api/Session' => Http::response([
                'SessionID' => 'fake_session_123',
            ], 200),
        ]);
    }

    private function getLookupService(): ZnunyLookupService
    {
        $mockQueueService = \Mockery::mock(\App\Services\Znuny\ZnunyQueueService::class);
        $mockQueueService->shouldReceive('findQueueByName')->andReturnUsing(function($name) {
            return (new ZnunyClient)->getQueueByName($name);
        });
        return new ZnunyLookupService(new ZnunyTicketDefaultRuleService, new ZnunyClient, $mockQueueService);
    }

    public function test_health_normalization()
    {
        Http::fake([
            'https://example.invalid/api/Health*' => Http::response([
                'Plugin' => 'ZnunyAgentList',
                'Success' => 1,
                'Version' => '1.1.0',
                'Time' => '2026-06-15T12:00:00Z',
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->health();

        $this->assertEquals([
            'success' => true,
            'plugin' => 'ZnunyAgentList',
            'version' => '1.1.0',
            'time' => '2026-06-15T12:00:00Z',
        ], $response);
    }

    public function test_system_config_normalization()
    {
        Http::fake([
            'https://example.invalid/api/SystemConfig*' => Http::response([
                'Plugin' => 'ZnunyAgentList',
                'Version' => '1.1.0',
                'Znuny' => ['Version' => '6.5.20'],
                'Features' => ['QueueList' => 1],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->systemConfig();

        $this->assertEquals([
            'plugin' => 'ZnunyAgentList',
            'version' => '1.1.0',
            'znuny_version' => '6.5.20',
            'features' => ['QueueList' => true],
        ], $response);
    }

    public function test_get_queues_normalization()
    {
        Http::fake([
            'https://example.invalid/api/Queue*' => Http::response([
                'Queues' => [
                    ['QueueID' => 85, 'Name' => 'TestCompany', 'FullName' => 'TestCompany Full', 'ValidID' => 1],
                    ['QueueID' => -1, 'Name' => 'Invalid'], // Should be skipped
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->getQueues();

        $this->assertCount(1, $response);
        $this->assertEquals([
            'id' => 85,
            'name' => 'TestCompany',
            'full_name' => 'TestCompany Full',
            'valid_id' => 1,
            'label' => 'TestCompany', // Falls back to Name based on new logic
        ], $response[0]);
    }

    public function test_queue_found()
    {
        Http::fake([
            'https://example.invalid/api/QueueByName/TestCompany*' => Http::response([
                'Queue' => [
                    'QueueID' => 85,
                    'Name' => 'TestCompany',
                    'FullName' => 'TestCompany Full',
                    'ValidID' => 1,
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->getQueueByName('TestCompany');

        $this->assertTrue($response['found']);
        $this->assertEquals(85, $response['id']);
        $this->assertEquals('TestCompany', $response['name']);
        $this->assertEquals('TestCompany Full', $response['full_name']);
    }

    public function test_queue_found_with_spaces()
    {
        Http::fake([
            'https://example.invalid/api/QueueByName/Example%20Company*' => Http::response([
                'Queue' => [
                    'QueueID' => 86,
                    'Name' => 'Example Company',
                    'FullName' => 'Example Company',
                    'ValidID' => 1,
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->getQueueByName('Example Company');

        $this->assertTrue($response['found']);
        $this->assertEquals(86, $response['id']);
        $this->assertEquals('Example Company', $response['name']);
    }

    public function test_queue_not_found()
    {
        Http::fake([
            'https://example.invalid/api/QueueByName/Unknown*' => Http::response([
                'Queue' => [],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->getQueueByName('Unknown');

        $this->assertFalse($response['found']);
        $this->assertContains('Queue not found.', $response['warnings']);
    }

    public function test_customer_user_search_normalization()
    {
        Http::fake([
            'https://example.invalid/api/CustomerUser*' => Http::response([
                'CustomerUsers' => [
                    [
                        'UserLogin' => 'TestCompanyClients',
                        'UserCustomerID' => 'testcompany',
                        'UserFirstname' => 'Test',
                        'UserLastname' => 'User',
                        'UserEmail' => 'test@example.invalid',
                    ],
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->searchCustomerUsers('TestCompanyClients');

        $this->assertCount(1, $response);
        $this->assertEquals([
            'login' => 'TestCompanyClients',
            'customer_id' => 'testcompany',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.invalid',
            'label' => 'Test User <TestCompanyClients>',
        ], $response[0]);
    }

    public function test_customer_user_found()
    {
        Http::fake([
            'https://example.invalid/api/CustomerUser/TestCompanyClients*' => Http::response([
                'CustomerUser' => [
                    'UserLogin' => 'TestCompanyClients',
                    'UserCustomerID' => 'testcompany',
                    'UserFirstname' => 'Test',
                    'UserLastname' => 'User',
                    'UserEmail' => 'test@example.invalid',
                ],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->getCustomerUser('TestCompanyClients');

        $this->assertTrue($response['found']);
        $this->assertEquals('TestCompanyClients', $response['login']);
        $this->assertEquals('testcompany', $response['customer_id']);
        $this->assertEquals('Test User <TestCompanyClients>', $response['label']);
    }

    public function test_customer_user_not_found()
    {
        Http::fake([
            'https://example.invalid/api/CustomerUser/Unknown*' => Http::response([
                'CustomerUser' => [],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->getCustomerUser('Unknown');

        $this->assertFalse($response['found']);
        $this->assertContains('CustomerUser not found.', $response['warnings']);
    }

    public function test_resolve_defaults_full_success()
    {
        Http::fake([
            'https://example.invalid/api/ResolveTicketDefaults*' => Http::response([
                'Input' => ['HostName' => 'TestCompany swiss test01'],
                'Detected' => ['QueueName' => 'TestCompany', 'CustomerUserLogin' => 'TestCompanyClients'],
                'Queue' => ['Found' => 1, 'QueueID' => 85, 'Name' => 'TestCompany', 'FullName' => 'TestCompany Full'],
                'CustomerUser' => ['Found' => 1, 'UserCustomerID' => 'testcompany', 'UserLogin' => 'TestCompanyClients'],
                'Warnings' => [],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->resolveTicketDefaults('TestCompany swiss test01');

        $this->assertEquals('TestCompany', $response['detected']['queue_name']);
        $this->assertTrue($response['queue']['found']);
        $this->assertEquals(85, $response['queue']['id']);
        $this->assertTrue($response['customer_user']['found']);
        $this->assertEquals('testcompany', $response['customer_user']['customer_id']);
    }

    public function test_resolve_defaults_partial_success()
    {
        Http::fake([
            'https://example.invalid/api/ResolveTicketDefaults*' => Http::response([
                'Input' => ['HostName' => 'TestCompany swiss test01'],
                'Detected' => ['QueueName' => 'TestCompany', 'CustomerUserLogin' => 'TestCompanyClients'],
                'Queue' => ['Found' => 0],
                'CustomerUser' => ['Found' => 1, 'UserCustomerID' => 'testcompany', 'UserLogin' => 'TestCompanyClients'],
                'Warnings' => ['Queue not found'],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->resolveTicketDefaults('TestCompany swiss test01');

        $this->assertFalse($response['queue']['found']);
        $this->assertTrue($response['customer_user']['found']);
        $this->assertContains('Queue not found', $response['warnings']);
    }

    public function test_validate_ticket_create_valid()
    {
        Http::fake([
            'https://example.invalid/api/ValidateTicketCreate' => Http::response([
                'Valid' => 1,
                'Errors' => [],
                'Warnings' => [],
            ], 200),
        ]);

        $client = new ZnunyClient;
        $response = $client->validateTicketCreate([
            'OwnerID' => 11,
            'Queue' => 'TestCompany',
        ]);

        $this->assertTrue($response['valid']);
        $this->assertEmpty($response['errors']);
    }

    public function test_lookup_service_full_success()
    {
        Http::fake([
            'https://example.invalid/api/QueueByName/TestCompany*' => Http::response([
                'Queue' => [
                    'QueueID' => 85,
                    'Name' => 'TestCompany',
                    'FullName' => 'TestCompany Full',
                    'ValidID' => 1,
                ],
            ], 200),
            'https://example.invalid/api/CustomerUser/TestCompanyClients*' => Http::response([
                'CustomerUser' => [
                    'UserLogin' => 'TestCompanyClients',
                    'UserCustomerID' => 'testcompany',
                ],
            ], 200),
        ]);

        $service = $this->getLookupService();
        $response = $service->resolveTicketDefaultCandidates('TestCompany swiss test01');

        $this->assertEquals('TestCompany', $response['detected']['queue_name']);
        $this->assertEquals('TestCompanyClients', $response['detected']['customer_user_login']);
        $this->assertTrue($response['queue']['found']);
        $this->assertEquals(85, $response['queue']['id']);
        $this->assertTrue($response['customer_user']['found']);
        $this->assertEquals('testcompany', $response['customer_user']['customer_id']);
    }

    public function test_lookup_service_queue_missing_but_cu_exists()
    {
        Http::fake([
            'https://example.invalid/api/QueueByName/TestCompany*' => Http::response([
                'Queue' => [],
            ], 200),
            'https://example.invalid/api/CustomerUser/TestCompanyClients*' => Http::response([
                'CustomerUser' => [
                    'UserLogin' => 'TestCompanyClients',
                    'UserCustomerID' => 'testcompany',
                ],
            ], 200),
        ]);

        $service = $this->getLookupService();
        $response = $service->resolveTicketDefaultCandidates('TestCompany swiss test01');

        $this->assertFalse($response['queue']['found']);
        $this->assertTrue($response['customer_user']['found']);
        $this->assertContains('Queue not found.', $response['warnings']);
    }

    public function test_lookup_service_local_regex_no_match()
    {
        // Force no match logic
        Setting::updateOrCreate(['key' => 'znuny_queue_from_host_regex'], ['value' => '^(?<queue>[0-9]+)$']);

        // No Http fakes needed as API shouldn't be called if detection fails
        $service = $this->getLookupService();
        $response = $service->resolveTicketDefaultCandidates('TestCompany swiss test01');

        $this->assertNull($response['detected']['queue_name']);
        $this->assertNull($response['detected']['customer_user_login']);
        $this->assertFalse($response['queue']['found']);
        $this->assertFalse($response['customer_user']['found']);
        $this->assertContains('Queue could not be detected from host name.', $response['warnings']);
    }

    public function test_lookup_service_mapping_exact_match()
    {
        Setting::updateOrCreate(['key' => 'znuny_queue_host_mappings'], ['value' => json_encode([
            [

                'host_prefix' => 'TestCompany',
                'queue_name' => 'MappedQueue',
            ],
        ]), 'type' => 'json']);

        Http::fake([
            'https://example.invalid/api/QueueByName/TestCompany*' => Http::response(['Queue' => []], 200),
            'https://example.invalid/api/CustomerUser/TestCompanyClients*' => Http::response(['CustomerUser' => []], 200),
            'https://example.invalid/api/QueueByName/MappedQueue*' => Http::response([
                'Queue' => ['QueueID' => 99, 'Name' => 'MappedQueue', 'FullName' => 'Mapped Queue', 'ValidID' => 1],
            ], 200),
        ]);

        $service = $this->getLookupService();
        $response = $service->resolveTicketDefaultCandidates('TestCompany swiss test01');

        $this->assertTrue($response['queue']['found']);
        $this->assertEquals('MappedQueue', $response['queue']['name']);
        $this->assertFalse($response['customer_user']['found']);
    }

    public function test_lookup_service_mapping_ignored_if_queue_empty()
    {
        Setting::updateOrCreate(['key' => 'znuny_queue_host_mappings'], ['value' => json_encode([
            [
                'host_prefix' => 'TestCompany',
                'queue_name' => '', // Empty queue should be ignored
            ],
        ]), 'type' => 'json']);

        Http::fake([
            'https://example.invalid/api/QueueByName/TestCompany*' => Http::response(['Queue' => []], 200),
            'https://example.invalid/api/CustomerUser/TestCompanyClients*' => Http::response(['CustomerUser' => []], 200),
        ]);

        $service = $this->getLookupService();
        $response = $service->resolveTicketDefaultCandidates('TestCompany');

        $this->assertFalse($response['queue']['found']);
    }

    public function test_lookup_service_mapping_customer_user_is_not_fallback()
    {
        Setting::updateOrCreate(['key' => 'znuny_queue_host_mappings'], ['value' => json_encode([
            [
                'host_prefix' => 'TestCompany',
                'queue_name' => 'ExampleCompany',
            ],
        ]), 'type' => 'json']);

        Http::fake([
            'https://example.invalid/api/QueueByName/TestCompany*' => Http::response(['Queue' => []], 200),
            'https://example.invalid/api/CustomerUser/TestCompanyClients*' => Http::response([
                'CustomerUser' => [
                    'UserLogin' => 'TestCompanyClients',
                    'UserCustomerID' => 'testcompany',
                ],
            ], 200),
            'https://example.invalid/api/QueueByName/ExampleCompany*' => Http::response([
                'Queue' => ['QueueID' => 103, 'Name' => 'ExampleCompany', 'FullName' => 'Example Company', 'ValidID' => 1],
            ], 200),
        ]);

        $service = $this->getLookupService();
        $response = $service->resolveTicketDefaultCandidates('TestCompany kyiv sw01');

        $this->assertTrue($response['queue']['found']);
        $this->assertEquals('ExampleCompany', $response['queue']['name']);
        $this->assertTrue($response['customer_user']['found']);
        $this->assertEquals('TestCompanyClients', $response['customer_user']['login']);
    }

    public function test_lookup_service_mapping_mapped_queue_not_found()
    {
        Setting::updateOrCreate(['key' => 'znuny_queue_host_mappings'], ['value' => json_encode([
            [
                'host_prefix' => 'BadQueueHost',
                'queue_name' => 'NonExistentQueue',
            ],
        ]), 'type' => 'json']);

        Http::fake([
            'https://example.invalid/api/QueueByName/BadQueueHost*' => Http::response(['Queue' => []], 200),
            'https://example.invalid/api/CustomerUser/BadQueueHostClients*' => Http::response(['CustomerUser' => []], 200),
            'https://example.invalid/api/QueueByName/NonExistentQueue*' => Http::response(['Queue' => []], 200),
        ]);

        $service = $this->getLookupService();
        $response = $service->resolveTicketDefaultCandidates('BadQueueHost router01');

        $this->assertFalse($response['queue']['found']);
        $this->assertContains('Mapped queue not found in Znuny: NonExistentQueue', $response['warnings']);
    }

    public function test_lookup_service_mapping_ignored_if_primary_found()
    {
        Setting::updateOrCreate(['key' => 'znuny_queue_host_mappings'], ['value' => json_encode([
            [
                'host_prefix' => 'TestCompany',
                'queue_name' => 'MappedQueue',
            ],
        ]), 'type' => 'json']);

        Http::fake([
            'https://example.invalid/api/QueueByName/TestCompany*' => Http::response([
                'Queue' => ['QueueID' => 85, 'Name' => 'TestCompany', 'FullName' => 'TestCompany Full', 'ValidID' => 1],
            ], 200),
            'https://example.invalid/api/CustomerUser/TestCompanyClients*' => Http::response(['CustomerUser' => []], 200),
        ]);

        $service = $this->getLookupService();
        $response = $service->resolveTicketDefaultCandidates('TestCompany swiss test01');

        $this->assertTrue($response['queue']['found']);
        $this->assertEquals('TestCompany', $response['queue']['name']);
        $this->assertFalse($response['customer_user']['found']);
    }

    public function test_lookup_service_mapping_first_match_wins()
    {
        Setting::updateOrCreate(['key' => 'znuny_queue_host_mappings'], ['value' => json_encode([
            [
                'host_prefix' => 'MatchHost',
                'queue_name' => 'FirstWinnerQueue',
            ],
            [
                'host_prefix' => 'MatchHost',
                'queue_name' => 'SecondQueue',
            ],
        ]), 'type' => 'json']);

        Http::fake([
            'https://example.invalid/api/QueueByName/MatchHost*' => Http::response(['Queue' => []], 200),
            'https://example.invalid/api/CustomerUser/MatchHostClients*' => Http::response(['CustomerUser' => []], 200),
            'https://example.invalid/api/QueueByName/FirstWinnerQueue*' => Http::response([
                'Queue' => ['QueueID' => 200, 'Name' => 'FirstWinnerQueue', 'FullName' => 'First Winner', 'ValidID' => 1],
            ], 200),
        ]);

        $service = $this->getLookupService();
        $response = $service->resolveTicketDefaultCandidates('MatchHost firewall');

        $this->assertTrue($response['queue']['found']);
        $this->assertEquals('FirstWinnerQueue', $response['queue']['name']);
    }
}
