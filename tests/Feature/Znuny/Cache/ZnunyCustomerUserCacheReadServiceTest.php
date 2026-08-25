<?php

namespace Tests\Feature\Znuny\Cache;

use App\Services\Znuny\Cache\PrewarmSnapshotManager;
use App\Services\Znuny\Cache\ZnunyCustomerUserCacheReadService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ZnunyCustomerUserCacheReadServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
    }

    public function test_missing_snapshot_returns_null_and_empty_arrays()
    {
        $service = new ZnunyCustomerUserCacheReadService();
        $this->assertNull($service->getSnapshot());
        $this->assertEquals([], $service->getOptionsForQueue('IT Support'));
        $this->assertEquals([], $service->getSearchTermsForQueue('IT Support'));
    }

    public function test_valid_snapshot_with_non_empty_options()
    {
        Cache::put('znuny_prewarm_customer_users_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', [
            'queues' => [
                [
                    'queue_id' => 123,
                    'queue_name' => 'IT Support',
                    'search_terms' => ['IT Support', 'it'],
                    'options' => ['user@test' => 'User <user@test>'],
                ]
            ],
        ], now()->addMinutes(10));

        $service = new ZnunyCustomerUserCacheReadService();
        $snapshot = $service->getSnapshot();

        $this->assertEquals('gen_1', $snapshot['generation']);
        $this->assertCount(1, $snapshot['queues']);
        $this->assertEquals(['user@test' => 'User <user@test>'], $service->getOptionsForQueue('IT Support'));
        $this->assertEquals(['IT Support', 'it'], $service->getSearchTermsForQueue('IT Support'));
    }

    public function test_valid_snapshot_with_empty_options_and_empty_search_terms()
    {
        Cache::put('znuny_prewarm_customer_users_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', [
            'queues' => [
                [
                    'queue_id' => 123,
                    'queue_name' => 'IT Support',
                    'search_terms' => [],
                    'options' => [],
                ]
            ],
        ], now()->addMinutes(10));

        $service = new ZnunyCustomerUserCacheReadService();
        $snapshot = $service->getSnapshot();

        $this->assertNotNull($snapshot);
        $this->assertEquals([], $service->getOptionsForQueue('IT Support'));
        $this->assertEquals([], $service->getSearchTermsForQueue('IT Support'));
    }

    public function test_exact_complete_multi_word_queue_name_lookup()
    {
        Cache::put('znuny_prewarm_customer_users_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', [
            'queues' => [
                [
                    'queue_id' => 1,
                    'queue_name' => 'IT Support Kyiv',
                    'search_terms' => [],
                    'options' => ['u1' => 'u1'],
                ]
            ],
        ], now()->addMinutes(10));

        $service = new ZnunyCustomerUserCacheReadService();

        $this->assertEquals(['u1' => 'u1'], $service->getOptionsForQueue('IT Support Kyiv'));
        $this->assertEquals([], $service->getOptionsForQueue('IT Support'));
        $this->assertEquals([], $service->getOptionsForQueue('it support kyiv'));
    }

    public function test_unknown_queue_returns_empty_array()
    {
        Cache::put('znuny_prewarm_customer_users_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', [
            'queues' => [
                [
                    'queue_id' => 1,
                    'queue_name' => 'IT Support',
                    'search_terms' => [],
                    'options' => ['u1' => 'u1'],
                ]
            ],
        ], now()->addMinutes(10));

        $service = new ZnunyCustomerUserCacheReadService();
        $this->assertEquals([], $service->getOptionsForQueue('Unknown Queue'));
        $this->assertEquals([], $service->getSearchTermsForQueue('Unknown Queue'));
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_malformed_snapshots_return_null(array $payload)
    {
        Cache::put('znuny_prewarm_customer_users_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', $payload, now()->addMinutes(10));

        $service = new ZnunyCustomerUserCacheReadService();
        $this->assertNull($service->getSnapshot());
        $this->assertEquals([], $service->getOptionsForQueue('IT Support'));
        $this->assertEquals([], $service->getSearchTermsForQueue('IT Support'));
    }

    public static function invalidPayloadProvider(): array
    {
        $validQueue = [
            'queue_id' => 123,
            'queue_name' => 'IT Support',
            'search_terms' => ['IT Support'],
            'options' => ['u1' => 'u1'],
        ];

        $tooManyOptions = [];
        for ($i = 1; $i <= 51; $i++) {
            $tooManyOptions["user{$i}"] = "Label {$i}";
        }

        return [
            'missing payload/queues' => [[]],
            'queues is scalar' => [['queues' => 'scalar']],
            'empty queue list' => [['queues' => []]],
            'associative non-list queue list' => [['queues' => ['a' => $validQueue]]],
            'malformed queue ID (string)' => [['queues' => [array_merge($validQueue, ['queue_id' => 'abc'])]]],
            'malformed queue ID (zero)' => [['queues' => [array_merge($validQueue, ['queue_id' => 0])]]],
            'blank queue name' => [['queues' => [array_merge($validQueue, ['queue_name' => '   '])]]],
            'padded queue name' => [['queues' => [array_merge($validQueue, ['queue_name' => ' padded '])]]],
            'duplicate queue ID' => [['queues' => [$validQueue, array_merge($validQueue, ['queue_name' => 'Other'])]]],
            'duplicate queue name' => [['queues' => [$validQueue, array_merge($validQueue, ['queue_id' => 124])]]],
            'non-list search terms' => [['queues' => [array_merge($validQueue, ['search_terms' => ['a' => 'b']])]]],
            'blank search term' => [['queues' => [array_merge($validQueue, ['search_terms' => ['  ']])]]],
            'short search term' => [['queues' => [array_merge($validQueue, ['search_terms' => ['a']])]]],
            'padded search term' => [['queues' => [array_merge($validQueue, ['search_terms' => [' padded ']])]]],
            'duplicate search term' => [['queues' => [array_merge($validQueue, ['search_terms' => ['term', 'Term']])]]],
            'non-array options' => [['queues' => [array_merge($validQueue, ['options' => 'string'])]]],
            'blank login key' => [['queues' => [array_merge($validQueue, ['options' => [' ' => 'Label']])]]],
            'padded login key' => [['queues' => [array_merge($validQueue, ['options' => [' padded ' => 'Label']])]]],
            'non-string label' => [['queues' => [array_merge($validQueue, ['options' => ['u1' => 123]])]]],
            'blank label' => [['queues' => [array_merge($validQueue, ['options' => ['u1' => '  ']])]]],
            'padded label' => [['queues' => [array_merge($validQueue, ['options' => ['u1' => ' padded ']])]]],
            'more than 50 options' => [['queues' => [array_merge($validQueue, ['options' => $tooManyOptions])]]],
        ];
    }

    public function test_cache_only_behavior_sends_no_http_requests()
    {
        Http::fake();
        Cache::put('znuny_prewarm_customer_users_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', [
            'queues' => [
                [
                    'queue_id' => 1,
                    'queue_name' => 'IT Support',
                    'search_terms' => [],
                    'options' => ['u1' => 'u1'],
                ]
            ],
        ], now()->addMinutes(10));

        $service = new ZnunyCustomerUserCacheReadService();
        $this->assertEquals(['u1' => 'u1'], $service->getOptionsForQueue('IT Support'));
        Http::assertNothingSent();
    }

    public function test_reads_perform_no_cache_writes_and_never_call_refresh()
    {
        $mock = $this->createMock(PrewarmSnapshotManager::class);
        $mock->expects($this->once())->method('readActiveSnapshot')->willReturn(null);
        $mock->expects($this->never())->method('refresh');

        Cache::shouldReceive('put')->never();
        Cache::shouldReceive('forever')->never();

        $service = new ZnunyCustomerUserCacheReadService($mock);
        $this->assertNull($service->getSnapshot());
    }

    public function test_metadata_exposure_remains_normalized()
    {
        $service = new ZnunyCustomerUserCacheReadService();
        $meta = $service->getMetadata();

        $this->assertEquals('customer_users', $meta['dataset_name']);
        $this->assertEquals('missing', $meta['status']);
    }
}
