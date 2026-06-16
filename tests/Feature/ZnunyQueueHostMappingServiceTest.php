<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Zabbix\ZabbixProblemCache;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyQueueHostMappingService;
use App\Services\Znuny\ZnunyTicketDefaultRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZnunyQueueHostMappingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::updateOrCreate(['key' => 'znuny_queue_from_host_regex'], ['value' => '^(?<queue>[^\s]+)']);
    }

    public function test_scan_missing_mappings_logic()
    {
        // Setup Problem Cache Mock
        $mockCache = $this->mock(ZabbixProblemCache::class);
        $mockCache->shouldReceive('all')->andReturn([
            ['eventid' => 1, 'hosts' => [['name' => 'ExistingQueueHost swiss']]],
            ['eventid' => 2, 'hosts' => [['name' => 'ExistingMappingHost usa']]],
            ['eventid' => 3, 'hosts' => [['name' => 'MissingQueueHost uk']]],
            ['eventid' => 4, 'hosts' => [['name' => 'MissingQueueHost uk2']]], // duplicate prefix
            ['eventid' => 5, 'hosts' => [['name' => 'FailedApiHost ru']]],
        ]);

        // Existing Mappings
        $currentMappings = [
            [
                'host_prefix' => 'ExistingMappingHost',
                'queue_name' => 'SomeMappedQueue',
                'note' => 'Already mapped',
            ],
        ];

        // API Mocks
        $client = $this->mock(ZnunyClient::class);
        $client->shouldReceive('getQueues')->andReturn([
            ['id' => 10, 'name' => 'ExistingQueueHost', 'full_name' => 'Parent::ExistingQueueHost'],
        ]);

        $service = new ZnunyQueueHostMappingService($client, new ZnunyTicketDefaultRuleService, $mockCache);

        $result = $service->scanMissingMappings($currentMappings);

        $this->assertCount(2, $result['drafts']);
        $this->assertEquals('MissingQueueHost', $result['drafts'][0]['host_prefix']);
        $this->assertEquals('', $result['drafts'][0]['queue_name']);
        $this->assertEquals('Detected from current Zabbix problems', $result['drafts'][0]['note']);

        $this->assertEquals('FailedApiHost', $result['drafts'][1]['host_prefix']);
        $this->assertEquals('', $result['drafts'][1]['queue_name']);
        $this->assertEquals('Detected from current Zabbix problems', $result['drafts'][1]['note']);

        $stats = $result['stats'];
        $this->assertEquals(5, $stats['scanned']);
        $this->assertEquals(4, $stats['unique_prefixes']);
        $this->assertEquals(1, $stats['skipped_existing_queue']);
        $this->assertEquals(1, $stats['skipped_existing_mapping']);
        $this->assertEquals(2, $stats['added']);
        $this->assertEquals(0, $stats['failed_api']);
    }

    public function test_get_selectable_queues_result_success()
    {
        $client = $this->mock(ZnunyClient::class);
        $client->shouldReceive('getQueues')->andReturn([
            ['id' => 1, 'name' => 'QueueA', 'full_name' => 'Parent::QueueA'],
        ]);

        $mockCache = $this->mock(ZabbixProblemCache::class);
        $service = new ZnunyQueueHostMappingService($client, new ZnunyTicketDefaultRuleService, $mockCache);

        $result = $service->getSelectableQueuesResult();
        $this->assertNull($result['error']);
        $this->assertArrayHasKey('QueueA', $result['options']);
        $this->assertEquals('Parent::QueueA', $result['options']['QueueA']);
    }

    public function test_get_selectable_queues_result_failure()
    {
        $client = $this->mock(ZnunyClient::class);
        $client->shouldReceive('getQueues')->andThrow(new \Exception('API Error'));

        $mockCache = $this->mock(ZabbixProblemCache::class);
        $service = new ZnunyQueueHostMappingService($client, new ZnunyTicketDefaultRuleService, $mockCache);

        $result = $service->getSelectableQueuesResult();
        $this->assertNotNull($result['error']);
        $this->assertEmpty($result['options']);
    }

    public function test_save_mappings_normalizes_and_saves()
    {
        $mockCache = $this->mock(ZabbixProblemCache::class);
        $client = $this->mock(ZnunyClient::class);
        $service = new ZnunyQueueHostMappingService($client, new ZnunyTicketDefaultRuleService, $mockCache);

        $raw = [
            ['host_prefix' => '  Prefix1  ', 'queue_name' => '  Queue1  ', 'note' => '  Note  '],
            ['host_prefix' => '', 'queue_name' => 'Queue2', 'note' => ''], // empty prefix dropped
            ['host_prefix' => 'Prefix3', 'queue_name' => '', 'note' => ''], // empty queue dropped
            ['host_prefix' => 'prefix1', 'queue_name' => 'Queue3', 'note' => ''], // duplicate prefix (case-insensitive) dropped
        ];

        $service->saveMappings($raw);

        $setting = Setting::where('key', 'znuny_queue_host_mappings')->first();
        $this->assertNotNull($setting);
        $val = json_decode($setting->value, true);

        $this->assertCount(1, $val);
        $this->assertEquals('Prefix1', $val[0]['host_prefix']);
        $this->assertEquals('Queue1', $val[0]['queue_name']);
        $this->assertEquals('Note', $val[0]['note']);
        $this->assertArrayNotHasKey('enabled', $val[0]);
    }
}
