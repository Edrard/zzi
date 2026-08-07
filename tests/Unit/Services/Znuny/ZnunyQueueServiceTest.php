<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\Cache\ZnunyQueueCacheReadService;
use App\Services\Znuny\ZnunyQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ZnunyQueueServiceTest extends TestCase
{
    use RefreshDatabase;

    private ZnunyQueueService $service;

    private $queueReaderMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queueReaderMock = Mockery::mock(ZnunyQueueCacheReadService::class);
        $this->service = new ZnunyQueueService($this->queueReaderMock);

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

    public function test_get_queues_returns_reader_payload(): void
    {
        $this->queueReaderMock->shouldReceive('getQueues')
            ->once()
            ->andReturn($this->getSampleQueues());

        $queues = $this->service->getQueues();
        $this->assertCount(3, $queues);
        $this->assertEquals('Raw', $queues[0]['name']);
    }

    public function test_get_queues_performs_no_additional_caching(): void
    {
        $payload = $this->getSampleQueues();

        $this->queueReaderMock->shouldReceive('getQueues')
            ->twice()
            ->andReturn($payload);

        $result1 = $this->service->getQueues();
        $result2 = $this->service->getQueues();

        $this->assertSame($payload, $result1);
        $this->assertSame($payload, $result2);
    }

    public function test_reader_cache_miss_returns_empty_with_no_fallback(): void
    {
        $this->queueReaderMock->shouldReceive('getQueues')
            ->once()
            ->andReturn([]);

        $queues = $this->service->getQueues();
        $this->assertEmpty($queues);
    }

    public function test_get_selectable_queues_result_preserves_label_fallback(): void
    {
        $this->queueReaderMock->shouldReceive('getQueues')
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
        $this->queueReaderMock->shouldReceive('getQueues')
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
        $this->queueReaderMock->shouldReceive('getQueues')
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
        $this->queueReaderMock->shouldReceive('getQueues')
            ->once()
            ->andReturn($this->getSampleQueues());

        $result = $this->service->findQueueByName('it::network');

        $this->assertTrue($result['found']);
        $this->assertEquals('IT::Network', $result['name']);
    }

    public function test_find_queue_by_name_returns_false_when_not_found(): void
    {
        $this->queueReaderMock->shouldReceive('getQueues')
            ->once()
            ->andReturn($this->getSampleQueues());

        $result = $this->service->findQueueByName('Unknown Queue');

        $this->assertFalse($result['found']);
        $this->assertContains('Queue not found.', $result['warnings']);
    }

    public function test_find_queue_by_name_returns_warning_on_reader_failure(): void
    {
        $this->queueReaderMock->shouldReceive('getQueues')
            ->once()
            ->andThrow(new \Exception('Reader Error'));

        $result = $this->service->findQueueByName('Raw');

        $this->assertSame([
            'found' => false,
            'warnings' => [
                'Could not load prewarmed Znuny queue reference data.',
            ],
        ], $result);

        $this->assertStringNotContainsString('Znuny API', $result['warnings'][0]);
    }

    public function test_get_selectable_queues_result_on_error(): void
    {
        $this->queueReaderMock->shouldReceive('getQueues')
            ->once()
            ->andThrow(new \Exception('Reader Error'));

        $result = $this->service->getSelectableQueuesResult();

        $this->assertSame([
            'options' => [],
            'error' => 'Could not load prewarmed Znuny queue reference data. You can try again later.',
        ], $result);

        $this->assertStringNotContainsString('Znuny API', $result['error']);
    }
}
