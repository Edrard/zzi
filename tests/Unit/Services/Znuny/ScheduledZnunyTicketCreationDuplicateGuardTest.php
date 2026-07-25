<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Znuny;

use App\Enums\ScheduledZnunyTicketCreationDispatchDecision;
use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Models\ZnunyTicketCreationAttempt;
use App\Services\Znuny\ScheduledZnunyTicketCreationDuplicateGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledZnunyTicketCreationDuplicateGuardTest extends TestCase
{
    use RefreshDatabase;

    private ScheduledZnunyTicketCreationDuplicateGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = app(ScheduledZnunyTicketCreationDuplicateGuard::class);
    }

    private function createAttempt(string|int $runId, ZnunyTicketCreationAttemptStatus $status, int|string|null $ticketId = null, ?string $ticketNumber = null, string $sourceType = 'scheduled_run', ?string $createdAt = null): ZnunyTicketCreationAttempt
    {
        $data = [
            'source_type' => $sourceType,
            'source_id' => (string) $runId,
            'marker' => 'test-marker',
            'subject_original' => 'Subject',
            'subject_sent' => 'Subject',
            'status' => $status,
            'ticket_id' => $ticketId,
            'ticket_number' => $ticketNumber,
        ];

        if ($createdAt) {
            $data['created_at'] = $createdAt;
            $data['updated_at'] = $createdAt;
        }

        return ZnunyTicketCreationAttempt::create($data);
    }

    // 1. no attempt
    public function test_no_previous_attempt_returns_proceed()
    {
        $result = $this->guard->determineDispatchDecision(123);
        $this->assertEquals(ScheduledZnunyTicketCreationDispatchDecision::Proceed, $result['decision']);
        $this->assertNull($result['attempt']);
    }

    // 2. preparing
    public function test_preparing_returns_proceed()
    {
        $this->createAttempt(123, ZnunyTicketCreationAttemptStatus::Preparing);
        $result = $this->guard->determineDispatchDecision(123);
        $this->assertEquals(ScheduledZnunyTicketCreationDispatchDecision::Proceed, $result['decision']);
    }

    // 3. confirmed failed
    public function test_confirmed_failed_returns_proceed()
    {
        $this->createAttempt(123, ZnunyTicketCreationAttemptStatus::ConfirmedFailed);
        $result = $this->guard->determineDispatchDecision(123);
        $this->assertEquals(ScheduledZnunyTicketCreationDispatchDecision::Proceed, $result['decision']);
    }

    // 4. resolved without ticket
    public function test_resolved_without_ticket_returns_proceed()
    {
        $this->createAttempt(123, ZnunyTicketCreationAttemptStatus::ResolvedWithoutTicket);
        $result = $this->guard->determineDispatchDecision(123);
        $this->assertEquals(ScheduledZnunyTicketCreationDispatchDecision::Proceed, $result['decision']);
    }

    // 5. sending
    public function test_sending_returns_block_uncertain()
    {
        $this->createAttempt(123, ZnunyTicketCreationAttemptStatus::Sending);
        $result = $this->guard->determineDispatchDecision(123);
        $this->assertEquals(ScheduledZnunyTicketCreationDispatchDecision::BlockUncertain, $result['decision']);
    }

    // 6. uncertain
    public function test_uncertain_returns_block_uncertain()
    {
        $this->createAttempt(123, ZnunyTicketCreationAttemptStatus::Uncertain);
        $result = $this->guard->determineDispatchDecision(123);
        $this->assertEquals(ScheduledZnunyTicketCreationDispatchDecision::BlockUncertain, $result['decision']);
    }

    // 7. success with valid identifiers
    public function test_success_with_valid_identifiers_returns_reuse_confirmed()
    {
        $this->createAttempt(123, ZnunyTicketCreationAttemptStatus::Success, 42, 'TN42');
        $result = $this->guard->determineDispatchDecision(123);
        $this->assertEquals(ScheduledZnunyTicketCreationDispatchDecision::ReuseConfirmed, $result['decision']);
        $this->assertEquals(42, $result['ticket_id']);
        $this->assertEquals('TN42', $result['ticket_number']);
    }

    // 8. recovered with valid identifiers
    public function test_recovered_with_valid_identifiers_returns_reuse_confirmed()
    {
        $this->createAttempt(123, ZnunyTicketCreationAttemptStatus::Recovered, 42, 'TN42');
        $result = $this->guard->determineDispatchDecision(123);
        $this->assertEquals(ScheduledZnunyTicketCreationDispatchDecision::ReuseConfirmed, $result['decision']);
    }

    // 9. manually linked with valid identifiers
    public function test_manually_linked_with_valid_identifiers_returns_reuse_confirmed()
    {
        $this->createAttempt(123, ZnunyTicketCreationAttemptStatus::ManuallyLinked, 42, 'TN42');
        $result = $this->guard->determineDispatchDecision(123);
        $this->assertEquals(ScheduledZnunyTicketCreationDispatchDecision::ReuseConfirmed, $result['decision']);
    }

    // 10. orphaned with valid identifiers
    public function test_orphaned_with_valid_identifiers_returns_reuse_confirmed()
    {
        $this->createAttempt(123, ZnunyTicketCreationAttemptStatus::Orphaned, 42, 'TN42');
        $result = $this->guard->determineDispatchDecision(123);
        $this->assertEquals(ScheduledZnunyTicketCreationDispatchDecision::ReuseConfirmed, $result['decision']);
    }

    // 11. invalid TicketID
    public function test_success_with_invalid_ticket_id_returns_block_uncertain()
    {
        $this->createAttempt(123, ZnunyTicketCreationAttemptStatus::Success, 0, 'TN42');
        $result = $this->guard->determineDispatchDecision(123);
        $this->assertEquals(ScheduledZnunyTicketCreationDispatchDecision::BlockUncertain, $result['decision']);
    }

    // 11b. non-numeric TicketID
    public function test_success_with_non_numeric_ticket_id_returns_block_uncertain()
    {
        $this->createAttempt(123, ZnunyTicketCreationAttemptStatus::Success, 'invalid-id', 'TN42');
        $result = $this->guard->determineDispatchDecision(123);
        $this->assertEquals(ScheduledZnunyTicketCreationDispatchDecision::BlockUncertain, $result['decision']);
    }

    // 12. missing TicketNumber
    public function test_success_with_missing_ticket_number_returns_block_uncertain()
    {
        // empty string
        $this->createAttempt(123, ZnunyTicketCreationAttemptStatus::Success, 42, ' ');
        $result = $this->guard->determineDispatchDecision(123);
        $this->assertEquals(ScheduledZnunyTicketCreationDispatchDecision::BlockUncertain, $result['decision']);
    }

    // 13. invalid orphaned identifiers
    public function test_orphaned_with_invalid_identifiers_returns_block_uncertain()
    {
        $this->createAttempt(123, ZnunyTicketCreationAttemptStatus::Orphaned, null, 'TN42');
        $result = $this->guard->determineDispatchDecision(123);
        $this->assertEquals(ScheduledZnunyTicketCreationDispatchDecision::BlockUncertain, $result['decision']);
    }

    // 14. another Scheduled run is ignored
    public function test_another_scheduled_run_is_ignored()
    {
        $this->createAttempt(999, ZnunyTicketCreationAttemptStatus::Success, 42, 'TN42');
        $result = $this->guard->determineDispatchDecision(123);
        $this->assertEquals(ScheduledZnunyTicketCreationDispatchDecision::Proceed, $result['decision']);
    }

    // 15. another source type is ignored
    public function test_another_source_type_is_ignored()
    {
        $this->createAttempt(123, ZnunyTicketCreationAttemptStatus::Success, 42, 'TN42', 'zabbix_problem');
        $result = $this->guard->determineDispatchDecision(123);
        $this->assertEquals(ScheduledZnunyTicketCreationDispatchDecision::Proceed, $result['decision']);
    }

    // 16. newest attempt wins
    public function test_newest_attempt_wins()
    {
        $now = now();
        $this->createAttempt(123, ZnunyTicketCreationAttemptStatus::ConfirmedFailed, null, null, 'scheduled_run', $now->copy()->subDay()->toDateTimeString());
        $this->createAttempt(123, ZnunyTicketCreationAttemptStatus::Success, 42, 'TN42', 'scheduled_run', $now->toDateTimeString());

        $result = $this->guard->determineDispatchDecision(123);
        $this->assertEquals(ScheduledZnunyTicketCreationDispatchDecision::ReuseConfirmed, $result['decision']);
    }

    // 17. equal created_at values use highest id
    public function test_equal_created_at_values_use_highest_id()
    {
        $now = now()->toDateTimeString();

        // Older ID
        $this->createAttempt(123, ZnunyTicketCreationAttemptStatus::Success, 42, 'TN42', 'scheduled_run', $now);
        // Newer ID, same created_at
        $this->createAttempt(123, ZnunyTicketCreationAttemptStatus::Sending, null, null, 'scheduled_run', $now);

        $result = $this->guard->determineDispatchDecision(123);
        $this->assertEquals(ScheduledZnunyTicketCreationDispatchDecision::BlockUncertain, $result['decision']);
    }
}
