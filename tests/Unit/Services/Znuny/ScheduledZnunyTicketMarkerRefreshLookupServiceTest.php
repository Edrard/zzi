<?php

namespace Tests\Unit\Services\Znuny;

use App\Enums\ScheduledZnunyTicketMarkerLookupStatus;
use App\Models\Setting;
use App\Models\ZnunyTicketCreationAttempt;
use App\Services\Znuny\ScheduledZnunyTicketMarkerLookupService;
use App\Services\Znuny\ScheduledZnunyTicketMarkerRefreshLookupService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyTicketWorkspaceCacheReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledZnunyTicketMarkerRefreshLookupServiceTest extends TestCase
{
    use RefreshDatabase;

    private ZnunyTicketWorkspaceCacheReader $cacheReaderMock;

    private ZnunyClient $clientMock;

    private ScheduledZnunyTicketMarkerRefreshLookupService $refreshService;

    private ZnunyTicketCreationAttempt $attempt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheReaderMock = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
        $this->clientMock = $this->createMock(ZnunyClient::class);

        $lookupService = new ScheduledZnunyTicketMarkerLookupService($this->cacheReaderMock);
        $this->refreshService = new ScheduledZnunyTicketMarkerRefreshLookupService($lookupService, $this->clientMock);

        $this->attempt = new ZnunyTicketCreationAttempt([
            'marker' => 'MARKER123',
            'started_at' => '2026-07-27 10:00:00',
        ]);
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

    public function test_initial_found_returns_immediately_without_calling_api()
    {
        $this->expectReaderToReturn([
            [
                'TicketID' => 10,
                'TicketNumber' => 'TN10',
                'Title' => 'Notification for [MARKER123] issue',
                'StateType' => 'open',
            ],
        ]);

        $this->clientMock->expects($this->never())->method('searchTicketsWithMetadata');

        $result = $this->refreshService->findExactMarkerWithRefresh($this->attempt);

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Found, $result['status']);
        $this->assertEquals(1, $result['match_count']);
        $this->assertEquals(10, $result['ticket_id']);
        $this->assertFalse($result['refresh_attempted']);
        $this->assertFalse($result['refresh_succeeded']);
        $this->assertNull($result['refresh_exit_code']);
    }

    public function test_initial_multiple_returns_immediately_without_calling_api()
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
            ],
        ]);

        $this->clientMock->expects($this->never())->method('searchTicketsWithMetadata');

        $result = $this->refreshService->findExactMarkerWithRefresh($this->attempt);

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Multiple, $result['status']);
        $this->assertFalse($result['refresh_attempted']);
    }

    public function test_initial_unavailable_empty_marker_does_not_call_api()
    {
        $this->attempt->marker = '   ';

        $this->cacheReaderMock->expects($this->never())->method('getTickets');
        $this->clientMock->expects($this->never())->method('searchTicketsWithMetadata');

        $result = $this->refreshService->findExactMarkerWithRefresh($this->attempt);

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertFalse($result['refresh_attempted']);
        $this->assertFalse($result['refresh_succeeded']);
        $this->assertEquals('Cannot perform API fallback because attempt marker is empty.', $result['reason']);
    }

    public function test_initial_unavailable_reader_exception_calls_api_fallback()
    {
        $this->cacheReaderMock->expects($this->once())
            ->method('getTickets')
            ->willThrowException(new \Exception('Redis connection lost'));

        $this->clientMock->expects($this->once())
            ->method('searchTicketsWithMetadata')
            ->willReturn([
                'tickets' => [
                    [
                        'TicketID' => 99,
                        'TicketNumber' => 'TN99',
                        'Title' => 'Found MARKER123 via API',
                    ],
                ],
            ]);

        $result = $this->refreshService->findExactMarkerWithRefresh($this->attempt);

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Found, $result['status']);
        $this->assertTrue($result['refresh_attempted']);
        $this->assertTrue($result['refresh_succeeded']);
    }

    public function test_initial_not_found_calls_api_fallback_exactly_once()
    {
        $this->expectReaderToReturn([]);

        $this->clientMock->expects($this->once())
            ->method('searchTicketsWithMetadata')
            ->willReturn([
                'tickets' => [
                    [
                        'TicketID' => 100,
                        'TicketNumber' => 'TN100',
                        'Title' => 'Found MARKER123 via API',
                    ],
                ],
            ]);

        $result = $this->refreshService->findExactMarkerWithRefresh($this->attempt);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Found, $result['status']);
        $this->assertTrue($result['refresh_attempted']);
    }

    public function test_api_filter_uses_exact_wildcard_and_24_hour_window_with_timezone()
    {
        Setting::updateOrCreate(['key' => 'app_display_timezone'], ['value' => 'Europe/Kyiv']);

        $this->attempt->marker = '[SHE:123]';
        $this->attempt->started_at = '2026-07-27 10:17:46';

        $this->expectReaderToReturn([]);

        $this->clientMock->expects($this->once())
            ->method('searchTicketsWithMetadata')
            ->with($this->callback(function (array $filters) {
                return $filters['Title'] === '*[SHE:123]*' &&
                       $filters['CreatedFrom'] === '2026-07-26 13:17:46' &&
                       $filters['CreatedTo'] === '2026-07-28 13:17:46' &&
                       $filters['Limit'] === 2 &&
                       $filters['Offset'] === 0 &&
                       ! isset($filters['State']) &&
                       ! isset($filters['StateType']) &&
                       ! isset($filters['Queue']) &&
                       ! isset($filters['QueueID']) &&
                       ! isset($filters['Owner']) &&
                       ! isset($filters['OwnerID']) &&
                       ! isset($filters['CountOnly']) &&
                       ! isset($filters['Page']);
            }))
            ->willReturn(['tickets' => []]);

        $this->refreshService->findExactMarkerWithRefresh($this->attempt);
    }

    public function test_api_fallback_with_invalid_timezone_returns_unavailable()
    {
        Setting::updateOrCreate(['key' => 'app_display_timezone'], ['value' => 'Invalid/Timezone']);

        $this->expectReaderToReturn([]);
        $this->clientMock->expects($this->never())->method('searchTicketsWithMetadata');

        $result = $this->refreshService->findExactMarkerWithRefresh($this->attempt);

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertFalse($result['refresh_attempted']);
        $this->assertFalse($result['refresh_succeeded']);
        $this->assertEquals('Cannot perform API fallback because the configured Znuny timezone is invalid.', $result['reason']);
    }

    public function test_api_fallback_calculates_24_hours_across_dst_boundary()
    {
        Setting::updateOrCreate(['key' => 'app_display_timezone'], ['value' => 'Europe/London']);

        $this->attempt->started_at = '2026-03-29 12:00:00'; // Spring forward in London happens on March 29, 2026, 01:00 UTC.

        $this->expectReaderToReturn([]);

        $this->clientMock->expects($this->once())
            ->method('searchTicketsWithMetadata')
            ->with($this->callback(function (array $filters) {
                // 2026-03-28 12:00:00 UTC is GMT (+0), so 12:00:00.
                // 2026-03-30 12:00:00 UTC is BST (+1), so 13:00:00.
                return $filters['CreatedFrom'] === '2026-03-28 12:00:00' &&
                       $filters['CreatedTo'] === '2026-03-30 13:00:00';
            }))
            ->willReturn(['tickets' => []]);

        $this->refreshService->findExactMarkerWithRefresh($this->attempt);
    }

    public function test_api_fallback_without_tickets_returns_not_found()
    {
        $this->expectReaderToReturn([]);

        $this->clientMock->expects($this->once())
            ->method('searchTicketsWithMetadata')
            ->willReturn(['tickets' => []]);

        $result = $this->refreshService->findExactMarkerWithRefresh($this->attempt);

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::NotFound, $result['status']);
        $this->assertTrue($result['refresh_attempted']);
        $this->assertTrue($result['refresh_succeeded']);
    }

    public function test_api_fallback_with_warnings_returns_unavailable()
    {
        $this->expectReaderToReturn([]);

        $this->clientMock->expects($this->once())
            ->method('searchTicketsWithMetadata')
            ->willReturn([
                'warnings' => ['API deprecated'],
                'tickets' => [
                    ['TicketID' => 10, 'TicketNumber' => 'TN10', 'Title' => 'Title MARKER123'],
                ],
            ]);

        $result = $this->refreshService->findExactMarkerWithRefresh($this->attempt);

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertFalse($result['refresh_succeeded']);
    }

    public function test_api_fallback_with_missing_tickets_array_returns_unavailable()
    {
        $this->expectReaderToReturn([]);

        $this->clientMock->expects($this->once())
            ->method('searchTicketsWithMetadata')
            ->willReturn([
                'other_key' => 'value',
            ]);

        $result = $this->refreshService->findExactMarkerWithRefresh($this->attempt);

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertEquals('Direct API fallback returned malformed ticket data.', $result['reason']);
        $this->assertFalse($result['refresh_succeeded']);
    }

    public function test_api_fallback_with_non_array_tickets_returns_unavailable()
    {
        $this->expectReaderToReturn([]);

        $this->clientMock->expects($this->once())
            ->method('searchTicketsWithMetadata')
            ->willReturn([
                'tickets' => 'not an array',
            ]);

        $result = $this->refreshService->findExactMarkerWithRefresh($this->attempt);

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertEquals('Direct API fallback returned malformed ticket data.', $result['reason']);
        $this->assertFalse($result['refresh_succeeded']);
    }

    public function test_api_fallback_with_invalid_ticket_id_returns_unavailable()
    {
        $this->expectReaderToReturn([]);

        $this->clientMock->expects($this->once())
            ->method('searchTicketsWithMetadata')
            ->willReturn([
                'tickets' => [
                    ['TicketID' => -5, 'TicketNumber' => 'TN10', 'Title' => 'Title MARKER123'],
                ],
            ]);

        $result = $this->refreshService->findExactMarkerWithRefresh($this->attempt);

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertEquals('Direct API fallback returned tickets without valid identifiers or title.', $result['reason']);
        $this->assertFalse($result['refresh_succeeded']);
    }

    public function test_api_fallback_with_empty_ticket_number_returns_unavailable()
    {
        $this->expectReaderToReturn([]);

        $this->clientMock->expects($this->once())
            ->method('searchTicketsWithMetadata')
            ->willReturn([
                'tickets' => [
                    ['TicketID' => 10, 'TicketNumber' => '', 'Title' => 'Title MARKER123'],
                ],
            ]);

        $result = $this->refreshService->findExactMarkerWithRefresh($this->attempt);

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertEquals('Direct API fallback returned tickets without valid identifiers or title.', $result['reason']);
        $this->assertFalse($result['refresh_succeeded']);
    }

    public function test_api_fallback_with_empty_title_returns_unavailable()
    {
        $this->expectReaderToReturn([]);

        $this->clientMock->expects($this->once())
            ->method('searchTicketsWithMetadata')
            ->willReturn([
                'tickets' => [
                    ['TicketID' => 10, 'TicketNumber' => 'TN10', 'Title' => ''],
                ],
            ]);

        $result = $this->refreshService->findExactMarkerWithRefresh($this->attempt);

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertEquals('Direct API fallback returned tickets without valid identifiers or title.', $result['reason']);
        $this->assertFalse($result['refresh_succeeded']);
    }

    public function test_api_fallback_with_exception_returns_unavailable()
    {
        $this->expectReaderToReturn([]);

        $this->clientMock->expects($this->once())
            ->method('searchTicketsWithMetadata')
            ->willThrowException(new \Exception('Connection timeout'));

        $result = $this->refreshService->findExactMarkerWithRefresh($this->attempt);

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertFalse($result['refresh_succeeded']);
    }

    public function test_api_fallback_with_throwable_error_returns_unavailable()
    {
        $this->expectReaderToReturn([]);

        $this->clientMock->expects($this->once())
            ->method('searchTicketsWithMetadata')
            ->willThrowException(new \Error('Fatal error'));

        $result = $this->refreshService->findExactMarkerWithRefresh($this->attempt);

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertEquals('Direct API fallback threw an exception.', $result['reason']);
        $this->assertFalse($result['refresh_succeeded']);
    }

    public function test_api_fallback_with_missing_identifiers_returns_unavailable()
    {
        $this->expectReaderToReturn([]);

        $this->clientMock->expects($this->once())
            ->method('searchTicketsWithMetadata')
            ->willReturn([
                'tickets' => [
                    ['Title' => 'Title MARKER123'],
                ],
            ]);

        $result = $this->refreshService->findExactMarkerWithRefresh($this->attempt);

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertFalse($result['refresh_succeeded']);
    }

    public function test_api_fallback_with_false_wildcard_match_returns_not_found()
    {
        $this->expectReaderToReturn([]);

        $this->attempt->marker = '[MARKER123]';

        $this->clientMock->expects($this->once())
            ->method('searchTicketsWithMetadata')
            ->willReturn([
                'tickets' => [
                    [
                        'TicketID' => 10,
                        'TicketNumber' => 'TN10',
                        'Title' => 'This has MARKER123 without brackets',
                    ],
                ],
            ]);

        $result = $this->refreshService->findExactMarkerWithRefresh($this->attempt);

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::NotFound, $result['status']);
        $this->assertEquals(0, $result['match_count']);
    }

    public function test_api_fallback_with_multiple_matches_returns_multiple()
    {
        $this->expectReaderToReturn([]);

        $this->clientMock->expects($this->once())
            ->method('searchTicketsWithMetadata')
            ->willReturn([
                'tickets' => [
                    [
                        'TicketID' => 10,
                        'TicketNumber' => 'TN10',
                        'Title' => 'Found MARKER123 via API',
                    ],
                    [
                        'TicketID' => 11,
                        'TicketNumber' => 'TN11',
                        'Title' => 'Another MARKER123 via API',
                    ],
                ],
            ]);

        $result = $this->refreshService->findExactMarkerWithRefresh($this->attempt);

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Multiple, $result['status']);
        $this->assertEquals(2, $result['match_count']);
    }

    public function test_force_recheck_skips_cache_and_calls_api()
    {
        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $this->clientMock->expects($this->once())
            ->method('searchTicketsWithMetadata')
            ->willReturn([
                'tickets' => [
                    [
                        'TicketID' => 150,
                        'TicketNumber' => 'TN150',
                        'Title' => 'Found MARKER123 forced',
                    ],
                ],
            ]);

        $result = $this->refreshService->refreshAndFindExactMarker($this->attempt);

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Found, $result['status']);
        $this->assertEquals(150, $result['ticket_id']);
        $this->assertTrue($result['refresh_attempted']);
    }

    public function test_missing_anchor_returns_unavailable()
    {
        $this->expectReaderToReturn([]);
        $this->attempt->started_at = null;
        $this->attempt->created_at = null;
        $this->attempt->source_id = null;

        $this->clientMock->expects($this->never())->method('searchTicketsWithMetadata');

        $result = $this->refreshService->findExactMarkerWithRefresh($this->attempt);

        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['status']);
        $this->assertFalse($result['refresh_attempted']);
        $this->assertEquals('Cannot perform API fallback because attempt lacks a valid anchor time.', $result['reason']);
    }
}
