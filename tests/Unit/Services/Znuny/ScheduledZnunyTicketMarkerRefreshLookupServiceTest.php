<?php

namespace Tests\Unit\Services\Znuny;

use App\Enums\ScheduledZnunyTicketMarkerLookupStatus;
use App\Services\Znuny\ScheduledZnunyTicketMarkerLookupService;
use App\Services\Znuny\ScheduledZnunyTicketMarkerRefreshLookupService;
use App\Services\Znuny\ZnunyTicketWorkspaceCacheReader;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\TestCase;

class ScheduledZnunyTicketMarkerRefreshLookupServiceTest extends TestCase
{
    private ZnunyTicketWorkspaceCacheReader $cacheReaderMock;
    private Kernel $consoleMock;
    private ScheduledZnunyTicketMarkerRefreshLookupService $refreshService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheReaderMock = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
        $this->consoleMock = $this->createMock(Kernel::class);

        $lookupService = new ScheduledZnunyTicketMarkerLookupService($this->cacheReaderMock);
        $this->refreshService = new ScheduledZnunyTicketMarkerRefreshLookupService($lookupService, $this->consoleMock);
    }

    private function expectReaderToReturn(array $tickets, int $callCount = 1): void
    {
        $this->cacheReaderMock->expects($this->exactly($callCount))
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

    private function expectReaderToReturnInSequence(array $firstTickets, array $secondTickets): void
    {
        $this->cacheReaderMock->expects($this->exactly(2))
            ->method('getTickets')
            ->with([
                'state_types' => [
                    'new',
                    'open',
                    'pending reminder',
                    'pending auto',
                ],
            ])
            ->willReturnOnConsecutiveCalls($firstTickets, $secondTickets);
    }

    public function test_initial_found_returns_immediately_without_refreshing()
    {
        $this->expectReaderToReturn([
            [
                'TicketID' => 10,
                'TicketNumber' => 'TN10',
                'Title' => 'Notification for [MARKER123] issue',
                'StateType' => 'open',
            ]
        ]);

        $this->consoleMock->expects($this->never())->method('call');

        $result = $this->refreshService->findExactMarkerWithRefresh('MARKER123');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Found, $result['status']);
        $this->assertEquals(1, $result['match_count']);
        $this->assertEquals(10, $result['ticket_id']);
        $this->assertFalse($result['refresh_attempted']);
        $this->assertFalse($result['refresh_succeeded']);
        $this->assertNull($result['refresh_exit_code']);
    }

    public function test_initial_multiple_returns_immediately_without_refreshing()
    {
        $this->expectReaderToReturn([
            [
                'TicketID' => 10,
                'TicketNumber' => 'TN10',
                'Title' => 'Notification for [MARKER123] issue',
                'StateType' => 'open',
            ],
            [
                'TicketID' => 11,
                'TicketNumber' => 'TN11',
                'Title' => 'Notification for [MARKER123] issue',
                'StateType' => 'open',
            ]
        ]);

        $this->consoleMock->expects($this->never())->method('call');

        $result = $this->refreshService->findExactMarkerWithRefresh('MARKER123');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Multiple, $result['status']);
        $this->assertFalse($result['refresh_attempted']);
    }

    public function test_initial_unavailable_empty_marker_does_not_refresh()
    {
        $this->cacheReaderMock->expects($this->never())->method('getTickets');
        $this->consoleMock->expects($this->never())->method('call');

        $result = $this->refreshService->findExactMarkerWithRefresh('   ');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertFalse($result['refresh_attempted']);
    }

    public function test_initial_unavailable_reader_exception_does_not_refresh()
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
            ->willThrowException(new \Exception('Redis connection lost'));

        $this->consoleMock->expects($this->never())->method('call');

        $result = $this->refreshService->findExactMarkerWithRefresh('MARKER123');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertEquals('Failed to read Ticket Workspace cache.', $result['reason']);
        $this->assertFalse($result['refresh_attempted']);
    }

    public function test_initial_not_found_calls_warming_command_exactly_once()
    {
        $this->expectReaderToReturnInSequence([], []);

        $this->consoleMock->expects($this->once())
            ->method('call')
            ->with('znuny:warm-ticket-workspace-cache', ['--manual' => true])
            ->willReturn(0);

        $result = $this->refreshService->findExactMarkerWithRefresh('MARKER123');
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::NotFound, $result['status']);
        $this->assertTrue($result['refresh_attempted']);
    }

    public function test_successful_refresh_performs_exactly_one_second_lookup()
    {
        $this->expectReaderToReturnInSequence([], []);
        $this->consoleMock->expects($this->once())
            ->method('call')
            ->willReturn(0);

        $this->refreshService->findExactMarkerWithRefresh('MARKER123');
    }

    public function test_successful_refresh_followed_by_found_returns_second_ticket_identifiers()
    {
        $this->expectReaderToReturnInSequence(
            [], // First call: NotFound
            [   // Second call: Found
                [
                    'TicketID' => 20,
                    'TicketNumber' => 'TN20',
                    'Title' => 'New notification for [MARKER123]',
                    'StateType' => 'open',
                ]
            ]
        );

        $this->consoleMock->expects($this->once())
            ->method('call')
            ->with('znuny:warm-ticket-workspace-cache', ['--manual' => true])
            ->willReturn(0);

        $result = $this->refreshService->findExactMarkerWithRefresh('MARKER123');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Found, $result['status']);
        $this->assertEquals(1, $result['match_count']);
        $this->assertEquals(20, $result['ticket_id']);
        $this->assertEquals('TN20', $result['ticket_number']);
        $this->assertTrue($result['refresh_attempted']);
        $this->assertTrue($result['refresh_succeeded']);
        $this->assertSame(0, $result['refresh_exit_code']);
    }

    public function test_successful_refresh_followed_by_not_found_remains_not_found()
    {
        $this->expectReaderToReturnInSequence([], []);

        $this->consoleMock->expects($this->once())
            ->method('call')
            ->willReturn(0);

        $result = $this->refreshService->findExactMarkerWithRefresh('MARKER123');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::NotFound, $result['status']);
        $this->assertTrue($result['refresh_attempted']);
        $this->assertTrue($result['refresh_succeeded']);
    }

    public function test_successful_refresh_followed_by_multiple_preserves_matches()
    {
        $this->expectReaderToReturnInSequence(
            [],
            [
                [
                    'TicketID' => 20,
                    'TicketNumber' => 'TN20',
                    'Title' => 'New notification for [MARKER123]',
                    'StateType' => 'open',
                ],
                [
                    'TicketID' => 21,
                    'TicketNumber' => 'TN21',
                    'Title' => 'Another notification for [MARKER123]',
                    'StateType' => 'open',
                ]
            ]
        );

        $this->consoleMock->expects($this->once())
            ->method('call')
            ->willReturn(0);

        $result = $this->refreshService->findExactMarkerWithRefresh('MARKER123');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Multiple, $result['status']);
        $this->assertEquals(2, $result['match_count']);
        $this->assertCount(2, $result['matches']);
        $this->assertTrue($result['refresh_attempted']);
        $this->assertTrue($result['refresh_succeeded']);
    }

    public function test_successful_refresh_followed_by_unavailable_preserves_bounded_reason()
    {
        $this->cacheReaderMock->expects($this->exactly(2))
            ->method('getTickets')
            ->with([
                'state_types' => [
                    'new',
                    'open',
                    'pending reminder',
                    'pending auto',
                ],
            ])
            ->willReturnOnConsecutiveCalls(
                [], // First call: returns empty array -> NotFound
                $this->throwException(new \Exception('Redis connection lost again')) // Second call: throws -> Unavailable
            );

        $this->consoleMock->expects($this->once())
            ->method('call')
            ->willReturn(0);

        $result = $this->refreshService->findExactMarkerWithRefresh('MARKER123');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertEquals('Failed to read Ticket Workspace cache.', $result['reason']);
        $this->assertTrue($result['refresh_attempted']);
        $this->assertTrue($result['refresh_succeeded']);
    }

    public function test_non_zero_exit_code_returns_unavailable_and_preserves_exit_code()
    {
        $this->expectReaderToReturn([]);

        $this->consoleMock->expects($this->once())
            ->method('call')
            ->willReturn(123);

        $result = $this->refreshService->findExactMarkerWithRefresh('MARKER123');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertTrue($result['refresh_attempted']);
        $this->assertFalse($result['refresh_succeeded']);
        $this->assertSame(123, $result['refresh_exit_code']);
        $this->assertEquals('Failed to refresh the active Znuny Ticket Workspace cache.', $result['reason']);
    }

    public function test_non_zero_exit_code_does_not_perform_second_lookup()
    {
        // First call is asserted by expectReaderToReturn. There should be only one call.
        $this->expectReaderToReturn([]);

        $this->consoleMock->expects($this->once())
            ->method('call')
            ->willReturn(1);

        $this->refreshService->findExactMarkerWithRefresh('MARKER123');
    }

    public function test_command_exception_returns_unavailable_and_does_not_perform_second_lookup()
    {
        $this->expectReaderToReturn([]);

        $this->consoleMock->expects($this->once())
            ->method('call')
            ->willThrowException(new \Exception('Command failed'));

        $result = $this->refreshService->findExactMarkerWithRefresh('MARKER123');

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertTrue($result['refresh_attempted']);
        $this->assertFalse($result['refresh_succeeded']);
        $this->assertNull($result['refresh_exit_code']);
    }

    public function test_command_exception_does_not_expose_sensitive_exception_text()
    {
        $this->expectReaderToReturn([]);

        $this->consoleMock->expects($this->once())
            ->method('call')
            ->willThrowException(new \Exception('redis://admin:secret@example.internal token=abc123'));

        $result = $this->refreshService->findExactMarkerWithRefresh('MARKER123');

        $reason = $result['reason'];
        $this->assertEquals('Failed to refresh the active Znuny Ticket Workspace cache.', $reason);
        $this->assertStringNotContainsString('secret', $reason);
        $this->assertStringNotContainsString('abc123', $reason);
    }

    public function test_no_refresh_path_calls_marker_lookup_exactly_once()
    {
        $this->expectReaderToReturn([
            [
                'TicketID' => 10,
                'TicketNumber' => 'TN10',
                'Title' => 'Notification for [MARKER123] issue',
                'StateType' => 'open',
            ]
        ], 1); // Exactly 1 call

        $this->consoleMock->expects($this->never())->method('call');

        $this->refreshService->findExactMarkerWithRefresh('MARKER123');
    }

    public function test_successful_refresh_path_calls_marker_lookup_exactly_twice()
    {
        $this->expectReaderToReturnInSequence([], []);

        $this->consoleMock->expects($this->once())
            ->method('call')
            ->willReturn(0);

        $this->refreshService->findExactMarkerWithRefresh('MARKER123');
    }

    public function test_no_path_executes_warming_command_more_than_once()
    {
        $this->expectReaderToReturnInSequence([], []);

        $this->consoleMock->expects($this->once())
            ->method('call')
            ->willReturn(0);

        $this->refreshService->findExactMarkerWithRefresh('MARKER123');
    }
}
