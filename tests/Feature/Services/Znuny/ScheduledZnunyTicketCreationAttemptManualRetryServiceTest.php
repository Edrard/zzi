<?php

namespace Tests\Feature\Services\Znuny;

use App\Enums\ScheduledZnunyTicketMarkerLookupStatus;
use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Models\AuditLog;
use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\User;
use App\Models\ZnunyTicketCreationAttempt;
use App\Services\AuditLogger;
use App\Services\Znuny\ScheduledZnunyTicketCreationAttemptManualRetryService;
use App\Services\Znuny\ScheduledZnunyTicketCreationAttemptManualReviewService;
use App\Services\Znuny\ScheduledZnunyTicketMarkerLookupService;
use App\Services\Znuny\ScheduledZnunyTicketMarkerRefreshLookupService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyTicketWorkspaceCacheReader;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class ScheduledZnunyTicketCreationAttemptManualRetryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function registerDependenciesAndGetService(ZnunyTicketWorkspaceCacheReader $reader, ?ZnunyClient $client = null, ?AuditLogger $auditLogger = null): ScheduledZnunyTicketCreationAttemptManualRetryService
    {
        $audit = $auditLogger ?? new AuditLogger;

        $this->app->instance(ZnunyTicketWorkspaceCacheReader::class, $reader);
        $this->app->instance(ZnunyClient::class, $client ?? $this->createStub(ZnunyClient::class));
        $this->app->instance(AuditLogger::class, $audit);

        foreach ([
            ScheduledZnunyTicketMarkerLookupService::class,
            ScheduledZnunyTicketMarkerRefreshLookupService::class,
            ScheduledZnunyTicketCreationAttemptManualReviewService::class,
            ScheduledZnunyTicketCreationAttemptManualRetryService::class,
        ] as $service) {
            $this->app->forgetInstance($service);
        }

        $this->app->instance(
            ScheduledZnunyTicketCreationAttemptManualRetryService::class,
            new ScheduledZnunyTicketCreationAttemptManualRetryService(
                $this->app->make(ScheduledZnunyTicketCreationAttemptManualReviewService::class),
                $audit,
            )
        );

        return $this->app->make(ScheduledZnunyTicketCreationAttemptManualRetryService::class);
    }

    private function createFixture(string $marker, ZnunyTicketCreationAttemptStatus $status = ZnunyTicketCreationAttemptStatus::Uncertain): array
    {
        $task = ScheduledZnunyTask::create([
            'name' => 'Manual retry test',
            'enabled' => true,
            'cron_expression' => '0 0 * * *',
            'timezone' => 'UTC',
        ]);

        $run = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $task->id,
            'task_name_snapshot' => $task->name,
            'run_type' => 'scheduled',
            'status' => 'uncertain',
            'scheduled_for' => now(),
        ]);

        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'scheduled_run',
            'source_id' => $run->id,
            'status' => $status,
            'marker' => $marker,
            'subject_original' => 'Original subject',
            'subject_sent' => 'Original subject ['.$marker.']',
            'started_at' => now(),
            'check_attempts' => 1,
            'created_by' => null,
        ]);

        return [$task, $run, $attempt];
    }

    public function test_not_found_creates_pending_manual_retry(): void
    {
        $marker = 'MARKER123';
        [$task, $run, $attempt] = $this->createFixture($marker);
        $user = User::factory()->create();

        $reader = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->expects($this->once())
            ->method('getTickets')
            ->willReturn([]);

        $client = $this->createMock(ZnunyClient::class);
        $client->expects($this->once())
            ->method('searchTicketsWithMetadata')
            ->willReturn(['tickets' => []]);

        $retryService = $this->registerDependenciesAndGetService($reader, $client);

        $result = $retryService->retry($attempt->id, $user);

        $this->assertTrue($result['created']);
        $this->assertFalse($result['existing']);
        $this->assertEquals($attempt->id, $result['attempt_id']);
        $this->assertEquals($run->id, $result['original_run_id']);
        $this->assertNotNull($result['retry_run_id']);
        $this->assertEquals($task->id, $result['task_id']);
        $this->assertEquals(ScheduledZnunyTicketMarkerLookupStatus::NotFound, $result['lookup_status']);
        $this->assertNull($result['reason']);

        $retryRun = ScheduledZnunyTaskRun::find($result['retry_run_id']);
        $this->assertNotNull($retryRun);
        $this->assertEquals('manual_retry', $retryRun->run_type);
        $this->assertEquals('pending', $retryRun->status);
        $this->assertEquals($attempt->id, $retryRun->manual_retry_of_attempt_id);
        $this->assertEquals($task->id, $retryRun->scheduled_znuny_task_id);
        $this->assertEquals($run->task_name_snapshot, $retryRun->task_name_snapshot);
        $this->assertEquals($user->id, $retryRun->created_by);

        $attempt->refresh();
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Uncertain, $attempt->status);
        $this->assertNull($attempt->ticket_id);
        $this->assertNull($attempt->ticket_number);

        $run->refresh();
        $this->assertEquals('uncertain', $run->status);

        $log = AuditLog::where('action', 'scheduled_znuny_attempt_manual_retry_created')
            ->where('entity_id', $attempt->id)
            ->first();
        $this->assertNotNull($log);
        $this->assertEquals($user->id, $log->user_id);
    }

    public function test_repeat_submission_returns_existing_retry_idempotently(): void
    {
        $marker = 'MARKER123';
        [$task, $run, $attempt] = $this->createFixture($marker);
        $user = User::factory()->create();

        $reader = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->expects($this->exactly(2))
            ->method('getTickets')
            ->willReturn([]);

        $client = $this->createMock(ZnunyClient::class);
        $client->expects($this->exactly(2))
            ->method('searchTicketsWithMetadata')
            ->willReturn(['tickets' => []]);

        $retryService = $this->registerDependenciesAndGetService($reader, $client);

        $result1 = $retryService->retry($attempt->id, $user);
        $this->assertTrue($result1['created']);

        $result2 = $retryService->retry($attempt->id, $user);

        $this->assertFalse($result2['created']);
        $this->assertTrue($result2['existing']);
        $this->assertEquals($result1['retry_run_id'], $result2['retry_run_id']);

        $count = ScheduledZnunyTaskRun::where('manual_retry_of_attempt_id', $attempt->id)->count();
        $this->assertEquals(1, $count);

        $logCount = AuditLog::where('action', 'scheduled_znuny_attempt_manual_retry_created')
            ->where('entity_id', $attempt->id)
            ->count();
        $this->assertEquals(1, $logCount);
    }

    public function test_concurrent_attempt_resolution_blocks_retry(): void
    {
        $marker = 'MARKER123';
        [$task, $run, $attempt] = $this->createFixture($marker);
        $user = User::factory()->create();

        $reader = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->expects($this->once())
            ->method('getTickets')
            ->willReturnCallback(function () use ($attempt) {
                $attempt->update([
                    'status' => ZnunyTicketCreationAttemptStatus::Recovered,
                    'ticket_id' => 999,
                    'ticket_number' => 'TN999',
                ]);

                return [];
            });

        $client = $this->createMock(ZnunyClient::class);
        $client->expects($this->once())
            ->method('searchTicketsWithMetadata')
            ->willReturn(['tickets' => []]);

        $retryService = $this->registerDependenciesAndGetService($reader, $client);

        $result = $retryService->retry($attempt->id, $user);

        $this->assertFalse($result['created']);
        $this->assertFalse($result['existing']);
        $this->assertNull($result['retry_run_id']);
        $this->assertNotNull($result['reason']);

        $count = ScheduledZnunyTaskRun::where('manual_retry_of_attempt_id', $attempt->id)->count();
        $this->assertEquals(0, $count);

        $attempt->refresh();
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Recovered, $attempt->status);

        $logCount = AuditLog::where('action', 'scheduled_znuny_attempt_manual_retry_created')
            ->where('entity_id', $attempt->id)
            ->count();
        $this->assertEquals(0, $logCount);
    }

    public static function nonNotFoundLookupProvider(): array
    {
        return [
            'Found' => [
                'tickets' => [[
                    'TicketID' => 20,
                    'TicketNumber' => 'TN20',
                    'Title' => 'Notification for [MARKER] issue',
                    'StateType' => 'open',
                ]],
                'throws' => false,
            ],
            'Multiple' => [
                'tickets' => [
                    [
                        'TicketID' => 20,
                        'TicketNumber' => 'TN20',
                        'Title' => 'Notification for [MARKER] issue',
                        'StateType' => 'open',
                    ],
                    [
                        'TicketID' => 21,
                        'TicketNumber' => 'TN21',
                        'Title' => 'Notification for [MARKER] issue',
                        'StateType' => 'open',
                    ],
                ],
                'throws' => false,
            ],
            'Unavailable' => [
                'tickets' => [],
                'throws' => true,
            ],
        ];
    }

    #[DataProvider('nonNotFoundLookupProvider')]
    public function test_non_not_found_lookup_results_are_rejected(array $tickets, bool $throws): void
    {
        $marker = 'MARKER';
        [$task, $run, $attempt] = $this->createFixture($marker);
        $user = User::factory()->create();

        $reader = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
        if ($throws) {
            // Unavailable throws Exception inside getTickets
            $reader->expects($this->once())
                ->method('getTickets')
                ->willThrowException(new \Exception('Cache read error'));
        } else {
            // Found or Multiple should not run secondary lookup or console command
            $reader->expects($this->once())
                ->method('getTickets')
                ->willReturn($tickets);
        }

        $client = $this->createMock(ZnunyClient::class);
        if ($throws) {
            $client->expects($this->once())
                ->method('searchTicketsWithMetadata')
                ->willThrowException(new \Exception('API Error'));
        } else {
            $client->expects($this->never())->method('searchTicketsWithMetadata');
        }

        $retryService = $this->registerDependenciesAndGetService($reader, $client);

        $result = $retryService->retry($attempt->id, $user);

        $this->assertFalse($result['created']);
        $this->assertFalse($result['existing']);
        $this->assertNull($result['retry_run_id']);

        $count = ScheduledZnunyTaskRun::where('manual_retry_of_attempt_id', $attempt->id)->count();
        $this->assertEquals(0, $count);

        $logCount = AuditLog::where('action', 'scheduled_znuny_attempt_manual_retry_created')
            ->where('entity_id', $attempt->id)
            ->count();
        $this->assertEquals(0, $logCount);
    }

    public function test_same_task_retries_created_in_same_second_receive_distinct_scheduled_times(): void
    {
        Carbon::setTestNow('2026-07-26 12:00:00');

        try {
            $task = ScheduledZnunyTask::create([
                'name' => 'Same second test',
                'enabled' => true,
                'cron_expression' => '0 0 * * *',
                'timezone' => 'UTC',
            ]);

            $run1 = ScheduledZnunyTaskRun::create([
                'scheduled_znuny_task_id' => $task->id,
                'task_name_snapshot' => $task->name,
                'run_type' => 'scheduled',
                'status' => 'uncertain',
                'scheduled_for' => now(),
            ]);

            $run2 = ScheduledZnunyTaskRun::create([
                'scheduled_znuny_task_id' => $task->id,
                'task_name_snapshot' => $task->name,
                'run_type' => 'scheduled',
                'status' => 'uncertain',
                'scheduled_for' => now()->addMinute(),
            ]);

            $attempt1 = ZnunyTicketCreationAttempt::create([
                'source_type' => 'scheduled_run',
                'source_id' => $run1->id,
                'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
                'marker' => 'MARKER1',
                'subject_original' => 'Original subject',
                'subject_sent' => 'Original subject [MARKER1]',
                'started_at' => now(),
                'check_attempts' => 1,
                'created_by' => null,
            ]);

            $attempt2 = ZnunyTicketCreationAttempt::create([
                'source_type' => 'scheduled_run',
                'source_id' => $run2->id,
                'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
                'marker' => 'MARKER2',
                'subject_original' => 'Original subject',
                'subject_sent' => 'Original subject [MARKER2]',
                'started_at' => now(),
                'check_attempts' => 1,
                'created_by' => null,
            ]);

            $user = User::factory()->create();

            $reader = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
            $reader->expects($this->exactly(2))
                ->method('getTickets')
                ->willReturn([]);

            $client = $this->createMock(ZnunyClient::class);
            $client->expects($this->exactly(2))
                ->method('searchTicketsWithMetadata')
                ->willReturn(['tickets' => []]);

            $retryService = $this->registerDependenciesAndGetService($reader, $client);

            $result1 = $retryService->retry($attempt1->id, $user);
            $result2 = $retryService->retry($attempt2->id, $user);

            $this->assertTrue($result1['created']);
            $this->assertTrue($result2['created']);

            $count = ScheduledZnunyTaskRun::where('run_type', 'manual_retry')->count();
            $this->assertEquals(2, $count);

            $retry1 = ScheduledZnunyTaskRun::find($result1['retry_run_id']);
            $retry2 = ScheduledZnunyTaskRun::find($result2['retry_run_id']);

            $this->assertEquals($attempt1->id, $retry1->manual_retry_of_attempt_id);
            $this->assertEquals($attempt2->id, $retry2->manual_retry_of_attempt_id);

            $this->assertEquals($task->id, $retry1->scheduled_znuny_task_id);
            $this->assertEquals($task->id, $retry2->scheduled_znuny_task_id);

            $this->assertNotEquals($retry1->scheduled_for, $retry2->scheduled_for);

            $this->assertEquals('pending', $retry1->status);
            $this->assertEquals('pending', $retry2->status);

            $attempt1->refresh();
            $attempt2->refresh();
            $run1->refresh();
            $run2->refresh();

            $this->assertEquals(ZnunyTicketCreationAttemptStatus::Uncertain, $attempt1->status);
            $this->assertEquals(ZnunyTicketCreationAttemptStatus::Uncertain, $attempt2->status);
            $this->assertEquals('uncertain', $run1->status);
            $this->assertEquals('uncertain', $run2->status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public static function duplicateExceptionProvider(): array
    {
        return [
            'Accepted' => [
                'driverCode' => 1062,
                'message' => 'Duplicate entry for key szt_runs_manual_retry_attempt_unique',
                'expected' => true,
            ],
            'Rejected: another duplicate index' => [
                'driverCode' => 1062,
                'message' => 'Duplicate entry for key szt_runs_task_id_scheduled_for_unique',
                'expected' => false,
            ],
            'Rejected: NOT NULL violation' => [
                'driverCode' => 1048,
                'message' => 'Column manual_retry_of_attempt_id cannot be null szt_runs_manual_retry_attempt_unique',
                'expected' => false,
            ],
            'Rejected: generic integrity violation' => [
                'driverCode' => 1452,
                'message' => 'Cannot add or update a child row szt_runs_manual_retry_attempt_unique',
                'expected' => false,
            ],
        ];
    }

    #[DataProvider('duplicateExceptionProvider')]
    public function test_only_manual_retry_unique_violation_is_classified_as_idempotent_duplicate(int $driverCode, string $message, bool $expected): void
    {
        $pdoException = new PDOException('SQLSTATE[23000]: Integrity constraint violation: '.$message);
        $pdoException->errorInfo = ['23000', $driverCode, $message];
        $queryException = new QueryException('', '', [], $pdoException);

        $method = new ReflectionMethod(ScheduledZnunyTicketCreationAttemptManualRetryService::class, 'isDuplicateManualRetryException');

        $audit = new AuditLogger;
        $retryService = new ScheduledZnunyTicketCreationAttemptManualRetryService(
            $this->createStub(ScheduledZnunyTicketCreationAttemptManualReviewService::class),
            $audit
        );

        $result = $method->invoke($retryService, $queryException);

        $this->assertSame($expected, $result);
    }

    public function test_audit_failure_does_not_roll_back_created_retry(): void
    {
        $marker = 'MARKER123';
        [$task, $run, $attempt] = $this->createFixture($marker);
        $user = User::factory()->create();

        $reader = $this->createMock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->expects($this->once())
            ->method('getTickets')
            ->willReturn([]);

        $client = $this->createMock(ZnunyClient::class);
        $client->expects($this->once())
            ->method('searchTicketsWithMetadata')
            ->willReturn(['tickets' => []]);

        $failingLogger = new class extends AuditLogger
        {
            public static function log(string $action, ?string $entityType = null, int|string|null $entityId = null, array $context = [], ?User $user = null): AuditLog
            {
                throw new RuntimeException('Forced audit failure');
            }
        };

        Log::spy();

        $retryService = $this->registerDependenciesAndGetService($reader, $client, $failingLogger);

        $result = $retryService->retry($attempt->id, $user);

        $this->assertTrue($result['created']);
        $this->assertFalse($result['existing']);
        $this->assertNotNull($result['retry_run_id']);

        $retryRun = ScheduledZnunyTaskRun::find($result['retry_run_id']);
        $this->assertNotNull($retryRun);
        $this->assertEquals('manual_retry', $retryRun->run_type);
        $this->assertEquals('pending', $retryRun->status);
        $this->assertEquals($attempt->id, $retryRun->manual_retry_of_attempt_id);

        $attempt->refresh();
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Uncertain, $attempt->status);

        $run->refresh();
        $this->assertEquals('uncertain', $run->status);

        $logCount = AuditLog::where('action', 'scheduled_znuny_attempt_manual_retry_created')
            ->where('entity_id', $attempt->id)
            ->count();
        $this->assertEquals(0, $logCount);

        Log::shouldHaveReceived('error')
            ->once()
            ->with('Audit log creation failed after manual retry creation.', \Mockery::on(function ($context) use ($attempt) {
                return ($context['attempt ID'] ?? null) === $attempt->id
                    && str_contains($context['error'] ?? '', 'Forced audit failure');
            }));
    }
}
