<?php

namespace Tests\Feature\Services\Znuny;

use App\Enums\ScheduledZnunyTicketMarkerLookupStatus;
use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Models\ZnunyTicketCreationAttempt;
use App\Services\Znuny\ScheduledZnunyTicketCreationAttemptReconciliationService;
use App\Services\Znuny\ScheduledZnunyTicketMarkerLookupService;
use App\Services\Znuny\ScheduledZnunyTicketMarkerRefreshLookupService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyTicketWorkspaceCacheReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledZnunyTicketCreationAttemptReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ZnunyTicketWorkspaceCacheReader $cacheReaderMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheReaderMock = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
    }

    private function buildService(?ZnunyClient $clientMock = null, bool $needsClientMock = false, ?array $clientReturn = null): ScheduledZnunyTicketCreationAttemptReconciliationService
    {
        if ($clientMock !== null) {
            $client = $clientMock;
        } elseif ($needsClientMock) {
            $client = $this->createMock(ZnunyClient::class);
            $client->expects($this->once())
                ->method('searchTicketsWithMetadata')
                ->willReturn($clientReturn ?? ['tickets' => []]);
        } else {
            $client = $this->createStub(ZnunyClient::class);
        }

        $lookupService = new ScheduledZnunyTicketMarkerLookupService($this->cacheReaderMock);
        $refreshService = new ScheduledZnunyTicketMarkerRefreshLookupService($lookupService, $client);

        return new ScheduledZnunyTicketCreationAttemptReconciliationService($refreshService);
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

    public function test_missing_attempt_returns_unavailable()
    {
        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->buildService()->reconcile(9999);

        $this->assertFalse($result['resolved']);
        $this->assertFalse($result['transitioned']);
        $this->assertNull($result['attempt_id']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['lookup_status']);
        $this->assertEquals('Scheduled ticket creation attempt was not found.', $result['reason']);
    }

    public function test_wrong_source_type_returns_unavailable()
    {
        $attempt = ZnunyTicketCreationAttempt::forceCreate([
            'source_type' => 'manual',
            'source_id' => '123',
            'marker' => 'MARKER123',
            'subject_original' => 'Subject',
            'subject_sent' => 'Subject',
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
        ]);

        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->buildService()->reconcile($attempt->id);

        $this->assertFalse($result['resolved']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['lookup_status']);
        $this->assertEquals('Automatic reconciliation is only available for scheduled ticket attempts.', $result['reason']);
        $this->assertEquals($attempt->id, $result['attempt_id']);
    }

    public function test_existing_confirmed_attempt_with_valid_ids_returns_resolved()
    {
        $attempt = ZnunyTicketCreationAttempt::forceCreate([
            'source_type' => 'scheduled_run',
            'source_id' => '123',
            'marker' => 'MARKER123',
            'subject_original' => 'Subject',
            'subject_sent' => 'Subject',
            'status' => ZnunyTicketCreationAttemptStatus::Success,
            'ticket_id' => 10,
            'ticket_number' => 'TN10',
        ]);

        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->buildService()->reconcile($attempt->id);

        $this->assertTrue($result['resolved']);
        $this->assertFalse($result['transitioned']);
        $this->assertEquals(10, $result['ticket_id']);
        $this->assertEquals('TN10', $result['ticket_number']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['lookup_status']);
    }

    public function test_existing_recovered_attempt_with_valid_ids_returns_resolved()
    {
        $attempt = ZnunyTicketCreationAttempt::forceCreate([
            'source_type' => 'scheduled_run',
            'source_id' => '123',
            'marker' => 'MARKER123',
            'subject_original' => 'Subject',
            'subject_sent' => 'Subject',
            'status' => ZnunyTicketCreationAttemptStatus::Recovered,
            'ticket_id' => 11,
            'ticket_number' => 'TN11',
        ]);

        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->buildService()->reconcile($attempt->id);

        $this->assertTrue($result['resolved']);
        $this->assertFalse($result['transitioned']);
        $this->assertEquals(11, $result['ticket_id']);
        $this->assertEquals('TN11', $result['ticket_number']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['lookup_status']);
    }

    public function test_existing_manually_linked_attempt_with_valid_ids_returns_resolved()
    {
        $attempt = ZnunyTicketCreationAttempt::forceCreate([
            'source_type' => 'scheduled_run',
            'source_id' => '123',
            'marker' => 'MARKER123',
            'subject_original' => 'Subject',
            'subject_sent' => 'Subject',
            'status' => ZnunyTicketCreationAttemptStatus::ManuallyLinked,
            'ticket_id' => 12,
            'ticket_number' => 'TN12',
        ]);

        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->buildService()->reconcile($attempt->id);

        $this->assertTrue($result['resolved']);
        $this->assertFalse($result['transitioned']);
        $this->assertEquals(12, $result['ticket_id']);
        $this->assertEquals('TN12', $result['ticket_number']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['lookup_status']);
    }

    public function test_existing_confirmed_attempt_with_invalid_ids_returns_unavailable()
    {
        $attempt = ZnunyTicketCreationAttempt::forceCreate([
            'source_type' => 'scheduled_run',
            'source_id' => '123',
            'marker' => 'MARKER123',
            'subject_original' => 'Subject',
            'subject_sent' => 'Subject',
            'status' => ZnunyTicketCreationAttemptStatus::Recovered,
            'ticket_id' => null, // Invalid
        ]);

        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->buildService()->reconcile($attempt->id);

        $this->assertFalse($result['resolved']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['lookup_status']);
        $this->assertEquals('Confirmed ticket attempt has invalid identifiers.', $result['reason']);
    }

    public function test_eligible_non_uncertain_returns_unavailable()
    {
        $attempt = ZnunyTicketCreationAttempt::forceCreate([
            'source_type' => 'scheduled_run',
            'source_id' => '123',
            'marker' => 'MARKER123',
            'subject_original' => 'Subject',
            'subject_sent' => 'Subject',
            'status' => ZnunyTicketCreationAttemptStatus::Orphaned,
        ]);

        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->buildService()->reconcile($attempt->id);

        $this->assertFalse($result['resolved']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['lookup_status']);
        $this->assertEquals('Ticket creation attempt is not eligible for automatic reconciliation.', $result['reason']);
    }

    public function test_uncertain_with_empty_marker_returns_unavailable()
    {
        $attempt = ZnunyTicketCreationAttempt::forceCreate([
            'source_type' => 'scheduled_run',
            'source_id' => '123',
            'marker' => '   ',
            'subject_original' => 'Subject',
            'subject_sent' => 'Subject',
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
        ]);

        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->buildService()->reconcile($attempt->id);

        $this->assertFalse($result['resolved']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['lookup_status']);
        $this->assertEquals('Scheduled ticket creation attempt has no marker.', $result['reason']);
    }

    public function test_lookup_unavailable_updates_bookkeeping_and_returns_unresolved()
    {
        $attempt = ZnunyTicketCreationAttempt::forceCreate([
            'source_type' => 'scheduled_run',
            'source_id' => '123',
            'marker' => 'MARKER123',
            'subject_original' => 'Subject',
            'subject_sent' => 'Subject',
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
            'check_attempts' => 1,
            'ticket_id' => 1,
            'ticket_number' => '1',
            'error_summary' => 'Err',
            'error_details' => 'Details',
        ]);

        $this->cacheReaderMock->expects($this->once())
            ->method('getTickets')
            ->willThrowException(new \Exception('Redis failed'));

        $clientMock = $this->createMock(ZnunyClient::class);
        $clientMock->expects($this->once())
            ->method('searchTicketsWithMetadata')
            ->willThrowException(new \Exception('API failed'));

        $result = $this->buildService($clientMock)->reconcile($attempt->id);

        $this->assertFalse($result['resolved']);
        $this->assertFalse($result['transitioned']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['lookup_status']);
        $this->assertEquals('Direct API fallback threw an exception.', $result['reason']);
        $this->assertTrue($result['refresh_attempted']);

        $attempt->refresh();
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Uncertain, $attempt->status);
        $this->assertEquals(1, $attempt->ticket_id);
        $this->assertEquals('1', $attempt->ticket_number);
        $this->assertEquals('Err', $attempt->error_summary);
        $this->assertEquals('Details', $attempt->error_details);
        $this->assertEquals(2, $attempt->check_attempts);
        $this->assertNotNull($attempt->last_checked_at);
    }

    public function test_lookup_not_found_updates_bookkeeping_and_returns_unresolved()
    {
        $attempt = ZnunyTicketCreationAttempt::forceCreate([
            'source_type' => 'scheduled_run',
            'source_id' => '123',
            'marker' => 'MARKER123',
            'subject_original' => 'Subject',
            'subject_sent' => 'Subject',
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
            'check_attempts' => 1,
            'ticket_id' => 1,
            'ticket_number' => '1',
            'error_summary' => 'Err',
            'error_details' => 'Details',
        ]);

        $this->expectReaderToReturn([]);

        $result = $this->buildService(needsClientMock: true, clientReturn: ['tickets' => []])->reconcile($attempt->id);

        $this->assertFalse($result['resolved']);
        $this->assertFalse($result['transitioned']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::NotFound, $result['lookup_status']);
        $this->assertEquals('No Znuny ticket was found for the scheduled marker in the direct recovery search.', $result['reason']);
        $this->assertTrue($result['refresh_attempted']);

        $attempt->refresh();
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Uncertain, $attempt->status);
        $this->assertEquals(1, $attempt->ticket_id);
        $this->assertEquals('1', $attempt->ticket_number);
        $this->assertEquals('Err', $attempt->error_summary);
        $this->assertEquals('Details', $attempt->error_details);
        $this->assertEquals(2, $attempt->check_attempts);
        $this->assertNotNull($attempt->last_checked_at);
    }

    public function test_lookup_found_recovers_attempt()
    {
        $started = now()->subMinutes(5);
        $finished = now()->subMinutes(4);

        $attempt = ZnunyTicketCreationAttempt::forceCreate([
            'source_type' => 'scheduled_run',
            'source_id' => '123',
            'marker' => 'MARKER123',
            'subject_original' => 'Subject',
            'subject_sent' => 'Subject',
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
            'check_attempts' => 0,
            'error_summary' => 'Some error',
            'error_details' => 'Details',
            'finished_at' => $finished,
            'started_at' => $started,
            'payload_snapshot' => ['key' => 'value'],
            'response_snapshot' => ['res' => 'data'],
        ]);

        $this->expectReaderToReturn([
            [
                'TicketID' => 10,
                'TicketNumber' => 'TN10   ', // Untrimmed
                'Title' => 'Notification for [MARKER123] issue',
                'StateType' => 'open',
            ],
        ]);

        $result = $this->buildService()->reconcile($attempt->id);

        $this->assertTrue($result['resolved']);
        $this->assertTrue($result['transitioned']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Found, $result['lookup_status']);
        $this->assertEquals(10, $result['ticket_id']);
        $this->assertEquals('TN10', $result['ticket_number']);
        $this->assertFalse($result['refresh_attempted']);

        $attempt->refresh();
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Recovered, $attempt->status);
        $this->assertEquals(10, $attempt->ticket_id);
        $this->assertEquals('TN10', $attempt->ticket_number);
        $this->assertEquals(1, $attempt->check_attempts);
        $this->assertNotNull($attempt->last_checked_at);
        $this->assertEquals($finished->toDateTimeString(), $attempt->finished_at->toDateTimeString());
        $this->assertNull($attempt->error_summary);
        $this->assertNull($attempt->error_details);
        $this->assertEquals(['key' => 'value'], $attempt->payload_snapshot);
        $this->assertEquals(['res' => 'data'], $attempt->response_snapshot);
    }

    public function test_lookup_multiple_updates_bookkeeping_and_returns_unresolved()
    {
        $attempt = ZnunyTicketCreationAttempt::forceCreate([
            'source_type' => 'scheduled_run',
            'source_id' => '123',
            'marker' => 'MARKER123',
            'subject_original' => 'Subject',
            'subject_sent' => 'Subject',
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
            'check_attempts' => 5,
            'ticket_id' => 1,
            'ticket_number' => '1',
            'error_summary' => 'Err',
            'error_details' => 'Details',
        ]);

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

        $result = $this->buildService()->reconcile($attempt->id);

        $this->assertFalse($result['resolved']);
        $this->assertFalse($result['transitioned']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Multiple, $result['lookup_status']);
        $this->assertEquals('Multiple open Znuny tickets contain the scheduled marker.', $result['reason']);

        $attempt->refresh();
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Uncertain, $attempt->status);
        $this->assertEquals(1, $attempt->ticket_id);
        $this->assertEquals('1', $attempt->ticket_number);
        $this->assertEquals('Err', $attempt->error_summary);
        $this->assertEquals('Details', $attempt->error_details);
        $this->assertEquals(6, $attempt->check_attempts);
        $this->assertNotNull($attempt->last_checked_at);
    }

    public function test_concurrent_confirmed_row_preserves_identifiers_and_updates_bookkeeping()
    {
        $attempt = ZnunyTicketCreationAttempt::forceCreate([
            'source_type' => 'scheduled_run',
            'source_id' => '123',
            'marker' => 'MARKER123',
            'subject_original' => 'Subject',
            'subject_sent' => 'Subject',
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
            'check_attempts' => 2,
        ]);

        $this->cacheReaderMock->expects($this->once())
            ->method('getTickets')
            ->willReturnCallback(function () use ($attempt) {
                // Update the row concurrently to Success
                ZnunyTicketCreationAttempt::where('id', $attempt->id)->update([
                    'status' => ZnunyTicketCreationAttemptStatus::Success->value,
                    'ticket_id' => 99,
                    'ticket_number' => 'TN99',
                ]);

                return [
                    [
                        'TicketID' => 10, // Lookup finds 10
                        'TicketNumber' => 'TN10',
                        'Title' => 'Notification for [MARKER123]',
                        'StateType' => 'open',
                    ],
                ];
            });

        $result = $this->buildService()->reconcile($attempt->id);

        $this->assertTrue($result['resolved']);
        $this->assertFalse($result['transitioned']);
        $this->assertEquals(99, $result['ticket_id']);
        $this->assertEquals('TN99', $result['ticket_number']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Found, $result['lookup_status']);

        $attempt->refresh();
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Success, $attempt->status);
        $this->assertEquals(99, $attempt->ticket_id);
        $this->assertEquals('TN99', $attempt->ticket_number);
        $this->assertEquals(3, $attempt->check_attempts); // bookkeepping updated exactly once
        $this->assertNotNull($attempt->last_checked_at);
    }

    public function test_concurrent_unsafe_row_does_not_recover_and_updates_bookkeeping()
    {
        $attempt = ZnunyTicketCreationAttempt::forceCreate([
            'source_type' => 'scheduled_run',
            'source_id' => '123',
            'marker' => 'MARKER123',
            'subject_original' => 'Subject',
            'subject_sent' => 'Subject',
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
            'check_attempts' => 2,
            'ticket_id' => null,
            'ticket_number' => null,
        ]);

        $this->cacheReaderMock->expects($this->once())
            ->method('getTickets')
            ->willReturnCallback(function () use ($attempt) {
                // Update the row concurrently to Preparing
                ZnunyTicketCreationAttempt::where('id', $attempt->id)->update([
                    'status' => ZnunyTicketCreationAttemptStatus::Preparing->value,
                ]);

                return [
                    [
                        'TicketID' => 10,
                        'TicketNumber' => 'TN10',
                        'Title' => 'Notification for [MARKER123]',
                        'StateType' => 'open',
                    ],
                ];
            });

        $result = $this->buildService()->reconcile($attempt->id);

        $this->assertFalse($result['resolved']);
        $this->assertFalse($result['transitioned']);
        $this->assertNull($result['ticket_id']);
        $this->assertNull($result['ticket_number']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Found, $result['lookup_status']);
        $this->assertEquals('Attempt status changed concurrently and is no longer eligible.', $result['reason']);

        $attempt->refresh();
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Preparing, $attempt->status);
        $this->assertNull($attempt->ticket_id);
        $this->assertNull($attempt->ticket_number);
        $this->assertEquals(3, $attempt->check_attempts); // bookkeepping updated exactly once
        $this->assertNotNull($attempt->last_checked_at);
    }
}
