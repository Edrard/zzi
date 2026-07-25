<?php

namespace Tests\Feature\Services\Znuny;

use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Enums\ScheduledZnunyTicketMarkerLookupStatus;
use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\ZnunyTicketCreationAttempt;
use App\Services\Znuny\ScheduledZnunyTicketCreationAttemptManualReviewService;
use App\Services\Znuny\ScheduledZnunyTicketMarkerLookupService;
use App\Services\Znuny\ScheduledZnunyTicketMarkerRefreshLookupService;
use App\Services\Znuny\ZnunyTicketWorkspaceCacheReader;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledZnunyTicketCreationAttemptManualReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private ScheduledZnunyTask $task;
    private ScheduledZnunyTaskRun $run;
    private ZnunyTicketCreationAttempt $attempt;
    private ScheduledZnunyTicketCreationAttemptManualReviewService $reviewService;
    private ZnunyTicketWorkspaceCacheReader $cacheReaderMock;
    private Kernel $consoleMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->task = ScheduledZnunyTask::create([
            'name' => 'Review Test Task',
            'enabled' => true,
            'cron_expression' => '* * * * *',
            'timezone' => 'UTC',
            'last_status' => 'uncertain',
            'last_ticket_id' => null,
            'last_ticket_number' => null,
        ]);

        $this->run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $this->task->id,
            'task_name_snapshot' => $this->task->name,
            'run_type' => 'scheduled',
            'status' => 'uncertain',
            'scheduled_for' => now(),
        ]);

        $this->attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'scheduled_run',
            'source_id' => $this->run->id,
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
            'marker' => 'marker_123',
            'subject_original' => 'Original Subject',
            'subject_sent' => 'Sent Subject',
            'ticket_id' => null,
            'ticket_number' => null,
            'started_at' => now(),
            'finished_at' => now(),
            'last_checked_at' => now(),
            'check_attempts' => 1,
            'error_summary' => 'init error',
            'error_details' => 'init details',
        ]);

        $this->cacheReaderMock = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
        $this->app->instance(ZnunyTicketWorkspaceCacheReader::class, $this->cacheReaderMock);

        $this->consoleMock = $this->createStub(Kernel::class);
        $this->app->instance(Kernel::class, $this->consoleMock);

        $lookupService = new ScheduledZnunyTicketMarkerLookupService($this->cacheReaderMock);
        $refreshService = new ScheduledZnunyTicketMarkerRefreshLookupService($lookupService, $this->consoleMock);

        $this->reviewService = new ScheduledZnunyTicketCreationAttemptManualReviewService(
            $lookupService,
            $refreshService
        );
    }

    private function getTicketFixture(int $ticketId, string $ticketNumber, string $state, string $title): array
    {
        return [
            'TicketID' => $ticketId,
            'TicketNumber' => $ticketNumber,
            'StateType' => $state,
            'Title' => $title,
        ];
    }

    private function configureActiveKernelMock(): void
    {
        $this->consoleMock = $this->createMock(Kernel::class);
        $this->app->instance(Kernel::class, $this->consoleMock);

        $lookupService = new ScheduledZnunyTicketMarkerLookupService($this->cacheReaderMock);
        $refreshService = new ScheduledZnunyTicketMarkerRefreshLookupService($lookupService, $this->consoleMock);

        $this->reviewService = new ScheduledZnunyTicketCreationAttemptManualReviewService(
            $lookupService,
            $refreshService
        );
    }

    private function assertUnchangedState(): void
    {
        $oldAttempt = $this->attempt;
        $oldRun = $this->run;
        $oldTask = $this->task;

        $this->attempt->refresh();
        $this->run->refresh();
        $this->task->refresh();

        $this->assertEquals($oldAttempt->status->value, $this->attempt->status->value);
        $this->assertEquals($oldAttempt->ticket_id, $this->attempt->ticket_id);
        $this->assertEquals($oldAttempt->ticket_number, $this->attempt->ticket_number);
        $this->assertEquals($oldAttempt->last_checked_at, $this->attempt->last_checked_at);
        $this->assertEquals($oldAttempt->check_attempts, $this->attempt->check_attempts);
        $this->assertEquals($oldAttempt->finished_at, $this->attempt->finished_at);
        $this->assertEquals($oldAttempt->error_summary, $this->attempt->error_summary);
        $this->assertEquals($oldAttempt->error_details, $this->attempt->error_details);

        $this->assertEquals($oldRun->status, $this->run->status);

        $this->assertEquals($oldTask->last_status, $this->task->last_status);
        $this->assertEquals($oldTask->last_ticket_id, $this->task->last_ticket_id);
        $this->assertEquals($oldTask->last_ticket_number, $this->task->last_ticket_number);
    }

    // 1. missing attempt
    public function test_missing_attempt_returns_unavailable()
    {
        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->reviewService->inspect(999999);

        $this->assertFalse($result['found']);
        $this->assertFalse($result['eligible']);
        $this->assertFalse($result['resolved']);
        $this->assertNull($result['attempt_id']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['lookup_status']);
        $this->assertEquals('Scheduled Znuny ticket creation attempt was not found.', $result['reason']);
        $this->assertFalse($result['refresh_attempted']);
    }

    // 2. unsupported source type
    public function test_unsupported_source_type_is_ineligible()
    {
        $this->attempt->update(['source_type' => 'manual', 'status' => ZnunyTicketCreationAttemptStatus::Preparing]);

        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->reviewService->inspect($this->attempt->id);

        $this->assertTrue($result['found']);
        $this->assertFalse($result['eligible']);
        $this->assertFalse($result['resolved']);
        $this->assertEquals('Only scheduled-run ticket creation attempts support manual resolution.', $result['reason']);

        $this->assertUnchangedState();
    }

    // 3. uncertain attempt with missing marker
    public function test_uncertain_with_missing_marker_is_ineligible()
    {
        $this->attempt->update(['marker' => '']);

        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->reviewService->inspect($this->attempt->id);

        $this->assertFalse($result['eligible']);
        $this->assertFalse($result['resolved']);
        $this->assertEquals('The scheduled ticket creation attempt has no marker.', $result['reason']);

        $this->assertUnchangedState();
    }

    // 4. uncertain attempt with missing run
    public function test_uncertain_with_missing_run_is_ineligible()
    {
        $this->run->delete();

        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->reviewService->inspect($this->attempt->id);

        $this->assertFalse($result['eligible']);
        $this->assertFalse($result['resolved']);
        $this->assertEquals('The Scheduled Znuny task run linked to this attempt was not found.', $result['reason']);
    }

    // 5. uncertain attempt with missing task
    public function test_uncertain_with_missing_task_is_ineligible()
    {
        $this->task->delete();

        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->reviewService->inspect($this->attempt->id);

        $this->assertFalse($result['eligible']);
        $this->assertFalse($result['resolved']);
        $this->assertEquals($this->run->id, $result['run_id']);
        $this->assertEquals('The Scheduled Znuny task linked to this attempt was not found.', $result['reason']);
    }

    // 6. Success with valid identifiers
    public function test_success_with_valid_identifiers_is_already_resolved()
    {
        $this->attempt->update([
            'status' => ZnunyTicketCreationAttemptStatus::Success,
            'ticket_id' => 123,
            'ticket_number' => 'TN123',
        ]);

        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->reviewService->inspect($this->attempt->id);

        $this->assertFalse($result['eligible']);
        $this->assertTrue($result['resolved']);
        $this->assertEquals('This attempt is already safely resolved.', $result['reason']);
    }

    // 7. Recovered with valid identifiers
    public function test_recovered_with_valid_identifiers_is_already_resolved()
    {
        $this->attempt->update([
            'status' => ZnunyTicketCreationAttemptStatus::Recovered,
            'ticket_id' => 123,
            'ticket_number' => 'TN123',
        ]);

        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->reviewService->inspect($this->attempt->id);

        $this->assertFalse($result['eligible']);
        $this->assertTrue($result['resolved']);
        $this->assertEquals('This attempt is already safely resolved.', $result['reason']);
    }

    // 8. ManuallyLinked with valid identifiers
    public function test_manually_linked_with_valid_identifiers_is_already_resolved()
    {
        $this->attempt->update([
            'status' => ZnunyTicketCreationAttemptStatus::ManuallyLinked,
            'ticket_id' => 123,
            'ticket_number' => 'TN123',
        ]);

        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->reviewService->inspect($this->attempt->id);

        $this->assertFalse($result['eligible']);
        $this->assertTrue($result['resolved']);
        $this->assertEquals('This attempt is already safely resolved.', $result['reason']);
    }

    // 9. resolved status with invalid identifiers
    public function test_resolved_with_invalid_identifiers_is_ineligible_and_not_resolved()
    {
        $this->attempt->update([
            'status' => ZnunyTicketCreationAttemptStatus::Success,
            'ticket_id' => null,
            'ticket_number' => null,
        ]);

        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->reviewService->inspect($this->attempt->id);

        $this->assertFalse($result['eligible']);
        $this->assertFalse($result['resolved']);
        $this->assertEquals('Attempt status is resolved but it lacks valid ticket identifiers.', $result['reason']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['lookup_status']);
    }

    // 10. Preparing is ineligible
    public function test_preparing_is_ineligible()
    {
        $this->attempt->update(['status' => ZnunyTicketCreationAttemptStatus::Preparing]);

        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->reviewService->inspect($this->attempt->id);

        $this->assertFalse($result['eligible']);
        $this->assertFalse($result['resolved']);
        $this->assertEquals('Attempt status is not eligible for manual resolution.', $result['reason']);
    }

    // 11. Sending is ineligible
    public function test_sending_is_ineligible()
    {
        $this->attempt->update(['status' => ZnunyTicketCreationAttemptStatus::Sending]);

        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->reviewService->inspect($this->attempt->id);

        $this->assertFalse($result['eligible']);
        $this->assertFalse($result['resolved']);
        $this->assertEquals('Attempt status is not eligible for manual resolution.', $result['reason']);
    }

    // 12. ConfirmedFailed is ineligible
    public function test_confirmed_failed_is_ineligible()
    {
        $this->attempt->update(['status' => ZnunyTicketCreationAttemptStatus::ConfirmedFailed]);

        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->reviewService->inspect($this->attempt->id);

        $this->assertFalse($result['eligible']);
        $this->assertFalse($result['resolved']);
        $this->assertEquals('Attempt status is not eligible for manual resolution.', $result['reason']);
    }

    public function test_resolved_without_ticket_is_ineligible_but_resolved()
    {
        $this->attempt->update(['status' => ZnunyTicketCreationAttemptStatus::ResolvedWithoutTicket]);

        $this->cacheReaderMock->expects($this->never())->method('getTickets');

        $result = $this->reviewService->inspect($this->attempt->id);

        $this->assertFalse($result['eligible']);
        $this->assertTrue($result['resolved']);
        $this->assertEquals('Attempt status is not eligible for manual resolution.', $result['reason']);
    }

    // 13. local inspect() returns NotFound without cache warming
    public function test_local_inspect_not_found_without_warming()
    {
        $this->cacheReaderMock->expects($this->once())
            ->method('getTickets')
            ->willReturn([]);

        $result = $this->reviewService->inspect($this->attempt->id);

        $this->assertTrue($result['eligible']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::NotFound, $result['lookup_status']);
        $this->assertFalse($result['refresh_attempted']);
        $this->assertFalse($result['refresh_succeeded']);

        $this->assertUnchangedState();
    }

    // 14. local inspect() returns one Found match
    public function test_local_inspect_found_one_match()
    {
        $this->cacheReaderMock->expects($this->once())
            ->method('getTickets')
            ->willReturn([
                $this->getTicketFixture(1, 'TN1', 'open', 'Title with marker_123 inside'),
            ]);

        $result = $this->reviewService->inspect($this->attempt->id);

        $this->assertTrue($result['eligible']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Found, $result['lookup_status']);
        $this->assertCount(1, $result['matches']);
        $this->assertEquals(1, $result['matches'][0]['ticket_id']);
        $this->assertEquals('TN1', $result['matches'][0]['ticket_number']);
        $this->assertFalse($result['refresh_attempted']);
        $this->assertFalse($result['refresh_succeeded']);

        $this->assertUnchangedState();
    }

    // 15. local inspect() returns Multiple
    public function test_local_inspect_returns_multiple()
    {
        $this->cacheReaderMock->expects($this->once())
            ->method('getTickets')
            ->willReturn([
                $this->getTicketFixture(1, 'TN1', 'open', 'Title with marker_123 inside'),
                $this->getTicketFixture(2, 'TN2', 'open', 'Another title with marker_123 inside'),
            ]);

        $result = $this->reviewService->inspect($this->attempt->id);

        $this->assertTrue($result['eligible']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Multiple, $result['lookup_status']);
        $this->assertCount(2, $result['matches']);
        $this->assertFalse($result['refresh_attempted']);
        $this->assertFalse($result['refresh_succeeded']);
    }

    // 16. local inspect() returns Unavailable
    public function test_local_inspect_returns_unavailable()
    {
        $this->cacheReaderMock->expects($this->once())
            ->method('getTickets')
            ->willThrowException(new \Exception('Cache error'));

        $result = $this->reviewService->inspect($this->attempt->id);

        $this->assertTrue($result['eligible']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['lookup_status']);
        $this->assertFalse($result['refresh_attempted']);
        $this->assertFalse($result['refresh_succeeded']);
    }

    // 17. recheck() with immediate Found does not warm
    public function test_recheck_immediate_found_does_not_warm()
    {
        $this->cacheReaderMock->expects($this->once())
            ->method('getTickets')
            ->willReturn([
                $this->getTicketFixture(1, 'TN1', 'open', 'Title with marker_123 inside'),
            ]);

        $result = $this->reviewService->recheck($this->attempt->id);

        $this->assertTrue($result['eligible']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Found, $result['lookup_status']);
        $this->assertFalse($result['refresh_attempted']);
        $this->assertFalse($result['refresh_succeeded']);

        $this->assertUnchangedState();
    }

    // 18. recheck() with NotFound warms once and finds a ticket
    public function test_recheck_not_found_warms_and_finds_ticket()
    {
        $this->configureActiveKernelMock();

        $this->cacheReaderMock->expects($this->exactly(2))
            ->method('getTickets')
            ->willReturnOnConsecutiveCalls(
                [], // first call: not found
                [$this->getTicketFixture(2, 'TN2', 'new', 'Has marker_123 yes')] // second call: found
            );

        $this->consoleMock->expects($this->once())
            ->method('call')
            ->with('znuny:warm-ticket-workspace-cache', ['--manual' => true])
            ->willReturn(0);

        $result = $this->reviewService->recheck($this->attempt->id);

        $this->assertTrue($result['eligible']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Found, $result['lookup_status']);
        $this->assertTrue($result['refresh_attempted']);
        $this->assertTrue($result['refresh_succeeded']);
        $this->assertEquals(0, $result['refresh_exit_code']);

        $this->assertUnchangedState();
    }

    // 19. recheck() with NotFound warms once and remains NotFound
    public function test_recheck_not_found_warms_and_remains_not_found()
    {
        $this->configureActiveKernelMock();

        $this->cacheReaderMock->expects($this->exactly(2))
            ->method('getTickets')
            ->willReturnOnConsecutiveCalls([], []);

        $this->consoleMock->expects($this->once())
            ->method('call')
            ->with('znuny:warm-ticket-workspace-cache', ['--manual' => true])
            ->willReturn(0);

        $result = $this->reviewService->recheck($this->attempt->id);

        $this->assertTrue($result['eligible']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::NotFound, $result['lookup_status']);
        $this->assertTrue($result['refresh_attempted']);
        $this->assertTrue($result['refresh_succeeded']);
        $this->assertEquals(0, $result['refresh_exit_code']);
    }

    // 20. recheck() with refresh failure returns Unavailable
    public function test_recheck_with_refresh_failure_returns_unavailable()
    {
        $this->configureActiveKernelMock();

        $this->cacheReaderMock->expects($this->once())
            ->method('getTickets')
            ->willReturn([]);

        $this->consoleMock->expects($this->once())
            ->method('call')
            ->with('znuny:warm-ticket-workspace-cache', ['--manual' => true])
            ->willReturn(1); // non-zero exit code

        $result = $this->reviewService->recheck($this->attempt->id);

        $this->assertTrue($result['eligible']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::Unavailable, $result['lookup_status']);
        $this->assertTrue($result['refresh_attempted']);
        $this->assertFalse($result['refresh_succeeded']);
        $this->assertEquals(1, $result['refresh_exit_code']);
    }

    // 21 & 22 are verified via assertUnchangedState in various tests above.
}
