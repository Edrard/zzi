<?php

namespace Tests\Unit\Services\Znuny;

use App\Models\Setting;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ZnunyQueueServiceTest extends TestCase
{
    use RefreshDatabase;

    private ZnunyQueueService $service;

    private $clientMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientMock = Mockery::mock(ZnunyClient::class);
        $this->service = new ZnunyQueueService($this->clientMock);

        Cache::clear(); // Important to clear cache for isolated tests
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Cache::clear();
        parent::tearDown();
    }

    private function getSampleQueues(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Raw',
                'full_name' => 'Raw',
                'valid_id' => 1,
                'label' => 'Raw',
            ],
            [
                'id' => 2,
                'name' => 'IT::Network',
                'full_name' => 'IT::Network',
                'valid_id' => 1,
                'label' => 'IT::Network',
            ],
            [
                'id' => 3,
                'name' => 'Junk',
                'full_name' => 'Junk',
                'valid_id' => 2, // invalid/closed
                'label' => 'Junk',
            ],
        ];
    }

    public function test_get_queues_caches_results(): void
    {
        $this->clientMock->shouldReceive('getQueues')
            ->once()
            ->andReturn($this->getSampleQueues());

        // First call should hit the client and cache it
        $queues1 = $this->service->getQueues();
        $this->assertCount(3, $queues1);

        // Second call should hit the cache, NOT the client
        $queues2 = $this->service->getQueues();
        $this->assertCount(3, $queues2);
    }

    public function test_queue_cache_ttl_zero_bypasses_cache_and_repeated_calls_hit_api(): void
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_queue_cache_ttl_minutes'],
            ['type' => 'integer', 'value' => 0]
        );

        $this->clientMock->shouldReceive('getQueues')
            ->twice()
            ->andReturn($this->getSampleQueues());

        $this->service->getQueues();
        $this->service->getQueues();

        $this->assertFalse(Cache::has('znuny.queues'));
    }

    public function test_queue_cache_expiration(): void
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_queue_cache_ttl_minutes'],
            ['type' => 'integer', 'value' => 10]
        );

        $this->clientMock->shouldReceive('getQueues')
            ->times(2)
            ->andReturn(
                [['id' => 1, 'name' => 'QueueA', 'valid_id' => 1]],
                [['id' => 2, 'name' => 'QueueB', 'valid_id' => 1]]
            );

        $result1 = $this->service->getQueues();
        $this->assertEquals('QueueA', $result1[0]['name']);

        $this->travel(9)->minutes();

        $result2 = $this->service->getQueues();
        $this->assertEquals('QueueA', $result2[0]['name']);

        $this->travel(2)->minutes(); // total 11 minutes

        $result3 = $this->service->getQueues();
        $this->assertEquals('QueueB', $result3[0]['name']);

        $this->travelBack();
    }

    public static function queueFallbackDataProvider(): array
    {
        return [
            'missing' => [null, 'string'],
            'unreadable string' => ['not-an-integer', 'string'],
            'negative' => [-5, 'integer'],
        ];
    }

    #[DataProvider('queueFallbackDataProvider')]
    public function test_queue_cache_ttl_fallback_for_invalid_values($value, $type): void
    {
        if ($value !== null) {
            Setting::updateOrCreate(
                ['key' => 'znuny_queue_cache_ttl_minutes'],
                ['type' => $type, 'value' => $value]
            );
        } else {
            Setting::where('key', 'znuny_queue_cache_ttl_minutes')->delete();
        }

        $this->clientMock->shouldReceive('getQueues')
            ->once()
            ->andReturn($this->getSampleQueues());

        $this->service->getQueues();
        $this->service->getQueues();

        $this->assertTrue(Cache::has('znuny.queues'));
    }

    public function test_get_selectable_queues_result_preserves_label_fallback(): void
    {
        $this->clientMock->shouldReceive('getQueues')
            ->once()
            ->andReturn([
                [
                    'name' => 'Q1',
                    'full_name' => 'Queue 1 Full',
                    'label' => 'Queue 1 Label',
                ],
                [
                    'name' => 'Q2',
                    'full_name' => 'Queue 2 Full',
                    // no label
                ],
                [
                    'name' => 'Q3',
                    // no full_name, no label
                ],
            ]);

        $result = $this->service->getSelectableQueuesResult();

        $this->assertNull($result['error']);
        $this->assertEquals('Queue 1 Label', $result['options']['Q1']); // prefers label
        $this->assertEquals('Queue 2 Full', $result['options']['Q2']);  // fallback to full_name
        $this->assertEquals('Q3', $result['options']['Q3']);            // fallback to name
    }

    public function test_find_queue_by_name_returns_correct_shape_when_found(): void
    {
        $this->clientMock->shouldReceive('getQueues')
            ->once()
            ->andReturn($this->getSampleQueues());

        $result = $this->service->findQueueByName('IT::Network');

        $this->assertTrue($result['found']);
        $this->assertEquals(2, $result['id']);
        $this->assertEquals('IT::Network', $result['name']);
        $this->assertEquals('IT::Network', $result['full_name']);
        $this->assertEquals(1, $result['valid_id']);
        $this->assertEquals('IT::Network', $result['label']);
        $this->assertEmpty($result['warnings']);
    }

    public function test_find_queue_by_name_applies_safe_fallbacks(): void
    {
        $this->clientMock->shouldReceive('getQueues')
            ->once()
            ->andReturn([
                [
                    'id' => 99,
                    'name' => 'PartialQueue',
                ],
            ]);

        $result = $this->service->findQueueByName('PartialQueue');

        $this->assertTrue($result['found']);
        $this->assertEquals(99, $result['id']);
        $this->assertEquals('PartialQueue', $result['name']);
        $this->assertEquals('PartialQueue', $result['full_name']); // fallback to name
        $this->assertEquals(1, $result['valid_id']); // fallback to 1
        $this->assertEquals('PartialQueue', $result['label']); // fallback to full_name -> name
        $this->assertEmpty($result['warnings']);
    }

    public function test_find_queue_by_name_is_case_insensitive(): void
    {
        $this->clientMock->shouldReceive('getQueues')
            ->once()
            ->andReturn($this->getSampleQueues());

        $result = $this->service->findQueueByName('it::network');

        $this->assertTrue($result['found']);
        $this->assertEquals('IT::Network', $result['name']);
    }

    public function test_find_queue_by_name_returns_false_when_not_found(): void
    {
        $this->clientMock->shouldReceive('getQueues')
            ->once()
            ->andReturn($this->getSampleQueues());

        $result = $this->service->findQueueByName('Unknown Queue');

        $this->assertFalse($result['found']);
        $this->assertContains('Queue not found.', $result['warnings']);
    }

    public function test_find_queue_by_name_returns_warning_on_api_failure(): void
    {
        $this->clientMock->shouldReceive('getQueues')
            ->once()
            ->andThrow(new \Exception('API Error'));

        $result = $this->service->findQueueByName('Raw');

        $this->assertFalse($result['found']);
        $this->assertContains('Could not load queues from Znuny API.', $result['warnings']);
    }

    public function test_get_selectable_queues_result_on_error(): void
    {
        $this->clientMock->shouldReceive('getQueues')
            ->once()
            ->andThrow(new \Exception('API Error'));

        $result = $this->service->getSelectableQueuesResult();

        $this->assertNotNull($result['error']);
        $this->assertEmpty($result['options']);
    }

    public function test_clear_cache_removes_queue_cache_only(): void
    {
        Cache::put('znuny.queues', 'data');
        Cache::put('unrelated_sentinel', 'safe');
        Cache::put('znuny_active_agents', 'agent_data');

        $this->clientMock->shouldNotReceive('getQueues');

        $this->service->clearCache();

        $this->assertNull(Cache::get('znuny.queues'));
        $this->assertEquals('safe', Cache::get('unrelated_sentinel'));
        $this->assertEquals('agent_data', Cache::get('znuny_active_agents'));
    }
}
