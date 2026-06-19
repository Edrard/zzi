<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\ZnunyTicketSnapshotNormalizer;
use PHPUnit\Framework\TestCase;

class ZnunyTicketSnapshotNormalizerTest extends TestCase
{
    private ZnunyTicketSnapshotNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new ZnunyTicketSnapshotNormalizer;
    }

    public function test_it_normalizes_ticket_data()
    {
        $raw = [
            'TicketID' => 123,
            'TicketNumber' => '202310101010',
            'QueueID' => 5,
            'Queue' => 'TestQueue',
            'OwnerID' => 10,
            'Owner' => 'TestOwner',
            'StateID' => 2,
            'State' => 'open',
            'StateType' => 'open',
            'PriorityID' => 3,
            'Priority' => '3 normal',
            'Changed' => '2023-10-10 12:00:00',
        ];

        $normalized = $this->normalizer->normalize($raw);

        $this->assertEquals('202310101010', $normalized['znuny_ticket_number']);
        $this->assertEquals(5, $normalized['znuny_queue_id']);
        $this->assertEquals('TestQueue', $normalized['znuny_queue_name']);
        $this->assertEquals(10, $normalized['znuny_owner_id']);
        $this->assertEquals('TestOwner', $normalized['znuny_owner_name']);
        $this->assertEquals(2, $normalized['znuny_state_id']);
        $this->assertEquals('open', $normalized['znuny_state_name']);
        $this->assertEquals('open', $normalized['znuny_ticket_state_type']);
        $this->assertEquals(3, $normalized['znuny_priority_id']);
        $this->assertEquals('3 normal', $normalized['znuny_priority']);
        $this->assertEquals('2023-10-10 12:00:00', $normalized['znuny_ticket_changed_at']);
    }

    public function test_it_handles_missing_optional_fields()
    {
        $raw = [
            'TicketNumber' => '202310101010',
        ];

        $normalized = $this->normalizer->normalize($raw);

        $this->assertEquals('202310101010', $normalized['znuny_ticket_number']);
        $this->assertNull($normalized['znuny_queue_id']);
        $this->assertNull($normalized['znuny_ticket_changed_at']);
    }

    public function test_hash_is_stable()
    {
        $raw1 = ['TicketNumber' => '123', 'QueueID' => 5];
        $raw2 = ['QueueID' => 5, 'TicketNumber' => '123'];

        $norm1 = $this->normalizer->normalize($raw1);
        $norm2 = $this->normalizer->normalize($raw2);

        $this->assertEquals($this->normalizer->hash($norm1), $this->normalizer->hash($norm2));
    }

    public function test_hash_changes_for_meaningful_data()
    {
        $norm1 = $this->normalizer->normalize(['TicketNumber' => '123', 'State' => 'open']);
        $norm2 = $this->normalizer->normalize(['TicketNumber' => '123', 'State' => 'closed']);

        $this->assertNotEquals($this->normalizer->hash($norm1), $this->normalizer->hash($norm2));
    }
}
