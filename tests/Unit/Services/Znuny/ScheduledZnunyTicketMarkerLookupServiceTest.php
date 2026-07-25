<?php

namespace Tests\Unit\Services\Znuny;

use App\Enums\ScheduledZnunyTicketMarkerLookupStatus;
use App\Services\Znuny\ScheduledZnunyTicketMarkerLookupService;
use App\Services\Znuny\ZnunyTicketWorkspaceCacheReader;
use PHPUnit\Framework\TestCase;

class ScheduledZnunyTicketMarkerLookupServiceTest extends TestCase
{
    private ZnunyTicketWorkspaceCacheReader $cacheReaderMock;
    private ScheduledZnunyTicketMarkerLookupService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheReaderMock = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
        $this->service = new ScheduledZnunyTicketMarkerLookupService($this->cacheReaderMock);
    }

    private function expectReaderToReturn(array $tickets): void
    {
        $this->cacheReaderMock->expects($this->once())
            ->method('getTickets')
            ->with([
                'state_types' => [
                    'new',
                    'open',
                    'pending reminder',
                    'pending auto',
                ],
            ])
            ->willReturn($tickets);
    }

    public function test_empty_marker_returns_unavailable()
    {
        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->service->findExactMarker('   ');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertEquals(0, $result['match_count']);
        $this->assertNull($result['ticket_id']);
        $this->assertNull($result['ticket_number']);
        $this->assertEmpty($result['matches']);
        $this->assertEquals('Scheduled ticket marker is empty.', $result['reason']);
    }

    public function test_empty_cache_returns_not_found()
    {
        $this->expectReaderToReturn([]);

        $result = $this->service->findExactMarker('MARKER123');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::NotFound, $result['status']);
        $this->assertEquals(0, $result['match_count']);
        $this->assertNull($result['ticket_id']);
        $this->assertNull($result['ticket_number']);
        $this->assertEmpty($result['matches']);
        $this->assertNull($result['reason']);
    }

    public function test_cache_read_failure_returns_unavailable()
    {
        $this->cacheReaderMock->expects($this->once())
            ->method('getTickets')
            ->with([
                'state_types' => [
                    'new',
                    'open',
                    'pending reminder',
                    'pending auto',
                ],
            ])
            ->willThrowException(new \Exception('redis://admin:secret@example.internal token=abc123'));

        $result = $this->service->findExactMarker('MARKER123');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertEquals(0, $result['match_count']);
        $this->assertNull($result['ticket_id']);
        $this->assertNull($result['ticket_number']);
        $this->assertEmpty($result['matches']);

        $reason = $result['reason'];
        $this->assertEquals('Failed to read Ticket Workspace cache.', $reason);
        $this->assertStringNotContainsString('secret', $reason);
        $this->assertStringNotContainsString('abc123', $reason);
        $this->assertStringNotContainsString("\n", $reason);
        $this->assertLessThan(100, strlen($reason));
    }

    public function test_missing_marker_in_open_tickets_returns_not_found()
    {
        $this->expectReaderToReturn([
            [
                'TicketID' => 1,
                'TicketNumber' => 'TN1',
                'Title' => 'Something else entirely',
                'StateType' => 'open',
            ]
        ]);

        $result = $this->service->findExactMarker('MARKER123');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::NotFound, $result['status']);
    }

    public function test_marker_found_in_closed_ticket_is_ignored()
    {
        $this->expectReaderToReturn([
            [
                'TicketID' => 2,
                'TicketNumber' => 'TN2',
                'Title' => 'Subject containing MARKER123',
                'StateType' => 'closed', // ignored defensively
            ],
            [
                'TicketID' => 3,
                'TicketNumber' => 'TN3',
                'Title' => 'Another closed MARKER123',
                'StateType' => 'merged', // ignored defensively
            ]
        ]);

        $result = $this->service->findExactMarker('MARKER123');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::NotFound, $result['status']);
    }

    public function test_one_open_ticket_with_exact_marker_returns_found()
    {
        $this->expectReaderToReturn([
            [
                'TicketID' => 10,
                'TicketNumber' => 'TN10',
                'Title' => 'Notification for [MARKER123] issue',
                'StateType' => 'open',
            ]
        ]);

        $result = $this->service->findExactMarker('MARKER123');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Found, $result['status']);
        $this->assertEquals(1, $result['match_count']);
        $this->assertEquals(10, $result['ticket_id']);
        $this->assertEquals('TN10', $result['ticket_number']);
        $this->assertEquals([['ticket_id' => 10, 'ticket_number' => 'TN10']], $result['matches']);
        $this->assertNull($result['reason']);
    }

    public function test_open_and_closed_matching_records_together()
    {
        $this->expectReaderToReturn([
            [
                'TicketID' => 10,
                'TicketNumber' => 'TN10',
                'Title' => 'Notification for [MARKER123] issue',
                'StateType' => 'open',
            ],
            [
                'TicketID' => 20,
                'TicketNumber' => 'TN20',
                'Title' => 'Notification for [MARKER123] issue',
                'StateType' => 'closed',
            ]
        ]);

        $result = $this->service->findExactMarker('MARKER123');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Found, $result['status']);
        $this->assertEquals(1, $result['match_count']);
        $this->assertEquals(10, $result['ticket_id']);
        $this->assertEquals('TN10', $result['ticket_number']);
        $this->assertEquals([['ticket_id' => 10, 'ticket_number' => 'TN10']], $result['matches']);
    }

    public function test_ticket_number_trimming()
    {
        $this->expectReaderToReturn([
            [
                'TicketID' => 10,
                'TicketNumber' => '  TN10  ',
                'Title' => 'Notification for [MARKER123] issue',
                'StateType' => 'open',
            ]
        ]);

        $result = $this->service->findExactMarker('MARKER123');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Found, $result['status']);
        $this->assertEquals('TN10', $result['ticket_number']);
        $this->assertEquals('TN10', $result['matches'][0]['ticket_number']);
    }

    public function test_similar_but_different_marker_does_not_match()
    {
        $this->expectReaderToReturn([
            [
                'TicketID' => 10,
                'TicketNumber' => 'TN10',
                'Title' => 'Notification for [MARKER1234] issue', // 1234 != 123
                'StateType' => 'open',
            ]
        ]);

        $result = $this->service->findExactMarker('[MARKER123]');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::NotFound, $result['status']);
    }

    public function test_marker_comparison_is_case_sensitive()
    {
        $this->expectReaderToReturn([
            [
                'TicketID' => 10,
                'TicketNumber' => 'TN10',
                'Title' => 'Subject marker123',
                'StateType' => 'open',
            ]
        ]);

        $result = $this->service->findExactMarker('MARKER123');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::NotFound, $result['status']);
    }

    public function test_multiple_open_matches_sorts_by_ticket_id_asc_and_number()
    {
        $this->expectReaderToReturn([
            [
                'TicketID' => 30,
                'TicketNumber' => 'TN30',
                'Title' => 'Subject MARKER123',
                'StateType' => 'new',
            ],
            [
                'TicketID' => 15,
                'TicketNumber' => 'TN15',
                'Title' => 'Another MARKER123',
                'StateType' => 'open',
            ],
            [
                'TicketID' => 15,
                'TicketNumber' => 'TN15B',
                'Title' => 'Tie breaker MARKER123',
                'StateType' => 'open',
            ],
            [
                'TicketID' => 15,
                'TicketNumber' => 'TN15A',
                'Title' => 'Tie breaker MARKER123',
                'StateType' => 'open',
            ]
        ]);

        $result = $this->service->findExactMarker('MARKER123');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Multiple, $result['status']);
        $this->assertEquals(4, $result['match_count']);
        $this->assertNull($result['ticket_id']);
        $this->assertNull($result['ticket_number']);

        $this->assertEquals(15, $result['matches'][0]['ticket_id']);
        $this->assertEquals('TN15', $result['matches'][0]['ticket_number']);

        $this->assertEquals(15, $result['matches'][1]['ticket_id']);
        $this->assertEquals('TN15A', $result['matches'][1]['ticket_number']);

        $this->assertEquals(15, $result['matches'][2]['ticket_id']);
        $this->assertEquals('TN15B', $result['matches'][2]['ticket_number']);

        $this->assertEquals(30, $result['matches'][3]['ticket_id']);
        $this->assertEquals('TN30', $result['matches'][3]['ticket_number']);

        $this->assertEquals('Multiple open Znuny tickets contain the scheduled marker.', $result['reason']);
    }

    public function test_found_ticket_with_invalid_id_returns_unavailable()
    {
        $this->expectReaderToReturn([
            [
                'TicketID' => 0, // Invalid
                'TicketNumber' => 'TN10',
                'Title' => 'Subject MARKER123',
                'StateType' => 'open',
            ]
        ]);

        $result = $this->service->findExactMarker('MARKER123');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertEquals(1, $result['match_count']);
        $this->assertNull($result['ticket_id']);
        $this->assertNull($result['ticket_number']);
        $this->assertEmpty($result['matches']);
        $this->assertEquals('A matching open Znuny ticket has invalid identifiers.', $result['reason']);
    }

    public function test_found_ticket_with_empty_number_returns_unavailable()
    {
        $this->expectReaderToReturn([
            [
                'TicketID' => 10,
                'TicketNumber' => '   ', // Invalid (empty after trim)
                'Title' => 'Subject MARKER123',
                'StateType' => 'open',
            ]
        ]);

        $result = $this->service->findExactMarker('MARKER123');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertEquals(1, $result['match_count']);
        $this->assertNull($result['ticket_id']);
        $this->assertNull($result['ticket_number']);
        $this->assertEmpty($result['matches']);
        $this->assertEquals('A matching open Znuny ticket has invalid identifiers.', $result['reason']);
    }

    public function test_mixed_valid_and_invalid_matches_returns_unavailable_and_sorted_matches()
    {
        $this->expectReaderToReturn([
            [
                'TicketID' => 30,
                'TicketNumber' => 'TN30', // Valid
                'Title' => 'Subject MARKER123',
                'StateType' => 'open',
            ],
            [
                'TicketID' => null, // Invalid
                'TicketNumber' => 'TN11',
                'Title' => 'Another MARKER123',
                'StateType' => 'new',
            ],
            [
                'TicketID' => 10,
                'TicketNumber' => 'TN10', // Valid
                'Title' => 'Yet another MARKER123',
                'StateType' => 'open',
            ]
        ]);

        $result = $this->service->findExactMarker('MARKER123');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertEquals(3, $result['match_count']);
        $this->assertNull($result['ticket_id']);
        $this->assertNull($result['ticket_number']);
        $this->assertCount(2, $result['matches']); // Only valid ones make it to `matches`

        $this->assertEquals(10, $result['matches'][0]['ticket_id']); // Sorted
        $this->assertEquals(30, $result['matches'][1]['ticket_id']);

        $this->assertEquals('A matching open Znuny ticket has invalid identifiers.', $result['reason']);
    }
}
