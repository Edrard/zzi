<?php

namespace App\Console\Commands;

use App\Enums\ScheduledZnunyTicketMarkerLookupStatus;
use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\ZnunyTicketCreationAttempt;
use App\Services\Znuny\ScheduledZnunyTicketCreationAttemptManualReviewService;
use App\Services\Znuny\ZnunyTicketCacheService;
use App\Services\Znuny\ZnunyTicketCreationMarkerBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PrepareScheduledZnunyReviewFixtureCommand extends Command
{
    protected $signature = 'scheduled-znuny:prepare-review-fixture
                            {task_id : The ID of the source scheduled task}
                            {scenario : The scenario to prepare (found, multiple, not_found, unavailable)}
                            {--replace : Safely remove matching fixtures and create a fresh one}
                            {--cleanup : Remove matching fixtures and exit}';

    protected $description = 'Prepares isolated manual-review UI fixtures for Scheduled Znuny Stage 4C.4B.';

    private const FIXTURE_VERSION = 1;

    private const PREFIX = '[STAGE 4C4B UI FIXTURE]';

    public function handle(
        ZnunyTicketCreationMarkerBuilder $markerBuilder,
        ZnunyTicketCacheService $cacheService,
        ScheduledZnunyTicketCreationAttemptManualReviewService $reviewService
    ): int {
        $rawTaskId = $this->argument('task_id');
        $taskId = filter_var($rawTaskId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($taskId === false) {
            $this->error('Task ID must be a positive integer.');

            return self::FAILURE;
        }

        $scenario = $this->argument('scenario');
        $replace = $this->option('replace');
        $cleanup = $this->option('cleanup');

        if ($replace && $cleanup) {
            $this->error('Cannot use --replace and --cleanup together.');

            return self::FAILURE;
        }

        if (! in_array($scenario, ['found', 'multiple', 'not_found', 'unavailable'], true)) {
            $this->error("Invalid scenario '{$scenario}'. Supported scenarios: found, multiple, not_found, unavailable.");

            return self::FAILURE;
        }

        // Cleanup existing fixtures
        $findResult = $this->findExistingFixtures($taskId, $scenario);
        if ($findResult['malformed_found']) {
            $this->error('Malformed fixture metadata found. Cleanup or replacement cannot safely proceed.');
            $this->line('Review the application log for details.');

            return self::FAILURE;
        }
        $existingFixtures = $findResult['fixtures'];

        if ($cleanup) {
            if (empty($existingFixtures)) {
                $this->info('No matching fixture exists to clean up.');

                return self::SUCCESS;
            }
            if (! $this->cleanupFixtures($existingFixtures, $cacheService)) {
                $this->error('Fixture cleanup failed. See the application log for technical details.');

                return self::FAILURE;
            }
            $this->info('Cleaned up '.count($existingFixtures).' existing fixture(s).');

            return self::SUCCESS;
        }

        $sourceTask = ScheduledZnunyTask::withTrashed()->find($taskId);
        if (! $sourceTask) {
            $this->error("Source task ID {$taskId} not found.");

            return self::FAILURE;
        }

        if ($sourceTask->trashed()) {
            $this->error("Source task ID {$taskId} is soft-deleted. Cannot create fixture from a deleted task.");

            return self::FAILURE;
        }

        if (! empty($existingFixtures) && ! $replace) {
            $this->info("Fixture already exists for source task {$taskId} and scenario {$scenario}.");
            $this->printFixtureInfo($existingFixtures[0]);

            return self::SUCCESS;
        }

        if (! empty($existingFixtures) && $replace) {
            if (! $this->cleanupFixtures($existingFixtures, $cacheService)) {
                $this->error('Fixture cleanup failed. See the application log for technical details.');

                return self::FAILURE;
            }
            $this->info('Cleaned up '.count($existingFixtures).' existing fixture(s) for replacement.');
        }

        // Build new fixture
        $fixtureId = strtolower(Str::random(6));
        $taskName = sprintf('%s[source:%d][%s][%s] %s', self::PREFIX, $taskId, $scenario, $fixtureId, $sourceTask->name);

        $syntheticTicketIds = [];
        $marker = '';
        $fixtureRun = null;
        $fixtureAttempt = null;
        $fixtureTask = null;
        $executionGuardField = 'queue_name';
        $executionGuardOriginalValue = $sourceTask->queue_name;

        try {
            DB::beginTransaction();

            // 1. Fixture Task
            $fixtureTask = new ScheduledZnunyTask;
            $fixtureTask->name = Str::limit($taskName, 255);

            // Copy relevant ticket data
            $fieldsToCopy = [
                'queue_id', 'owner_id', 'owner_login', 'customer_user_login',
                'type_id', 'type_name', 'priority_id', 'priority_name',
                'state_id', 'state_name', 'service_id', 'service_name',
                'sla_id', 'sla_name', 'subject', 'body',
            ];
            foreach ($fieldsToCopy as $field) {
                $fixtureTask->$field = $sourceTask->$field;
            }

            // Execution guard
            // queue_name and queue_id are omitted (null)
            $fixtureTask->queue_id = null;
            $fixtureTask->queue_name = null;

            // Set specific constraints
            $fixtureTask->enabled = false;
            $fixtureTask->cron_expression = null;
            $fixtureTask->next_run_at = null;
            $fixtureTask->last_run_at = null;
            $fixtureTask->last_success_at = null;
            $fixtureTask->last_failure_at = null;
            $fixtureTask->last_status = null;
            $fixtureTask->last_ticket_id = null;
            $fixtureTask->last_ticket_number = null;
            $fixtureTask->last_error_summary = null;
            $fixtureTask->lock_name = null;
            $fixtureTask->save();

            // 2. Fixture Run
            $scheduledFor = Carbon::now('UTC')->startOfSecond();
            while (ScheduledZnunyTaskRun::where('scheduled_znuny_task_id', $fixtureTask->id)
                ->where('scheduled_for', $scheduledFor)->exists()) {
                $scheduledFor->addSecond();
            }

            $fixtureRun = new ScheduledZnunyTaskRun;
            $fixtureRun->scheduled_znuny_task_id = $fixtureTask->id;
            $fixtureRun->run_type = 'manual';
            $fixtureRun->status = 'uncertain';
            $fixtureRun->task_name_snapshot = $fixtureTask->name;
            $fixtureRun->scheduled_for = $scheduledFor;
            $fixtureRun->started_at = Carbon::now('UTC');
            $fixtureRun->save();

            // 3. Fixture Attempt
            $marker = $markerBuilder->buildScheduledMarker($fixtureRun->id);
            $subjectSent = $markerBuilder->appendMarker($fixtureTask->subject, $marker);

            $fixtureAttempt = new ZnunyTicketCreationAttempt;
            $fixtureAttempt->source_type = 'scheduled_run';
            $fixtureAttempt->source_id = $fixtureRun->id;
            $fixtureAttempt->marker = $marker;
            $fixtureAttempt->status = ZnunyTicketCreationAttemptStatus::Uncertain->value;
            $fixtureAttempt->subject_original = $fixtureTask->subject;
            $fixtureAttempt->subject_sent = $subjectSent;
            $fixtureAttempt->ticket_id = null;
            $fixtureAttempt->ticket_number = null;
            $fixtureAttempt->check_attempts = 0;
            $fixtureAttempt->last_checked_at = null;
            $fixtureAttempt->finished_at = null;
            $fixtureAttempt->started_at = Carbon::now('UTC');
            $fixtureAttempt->save();

            // 4. Synthetic tickets setup
            $syntheticTicketIds = $this->generateSyntheticTickets($scenario, $marker, $cacheService, $syntheticTicketIds);

            // 5. Metadata
            $metadata = [
                'stage_4c4b_ui_fixture' => [
                    'version' => self::FIXTURE_VERSION,
                    'source_task_id' => $taskId,
                    'fixture_task_id' => $fixtureTask->id,
                    'scenario' => $scenario,
                    'fixture_id' => $fixtureId,
                    'marker' => $marker,
                    'synthetic_ticket_ids' => $syntheticTicketIds,
                    'execution_guard_field' => $executionGuardField,
                    'execution_guard_original_value' => $executionGuardOriginalValue,
                ],
            ];

            $fixtureRun->payload_snapshot = $metadata;
            $fixtureRun->save();

            $fixtureAttempt->payload_snapshot = $metadata;
            $fixtureAttempt->save();

            DB::commit();
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            if ($marker !== '') {
                $cacheCleanupSucceeded = $this->removeSyntheticTickets($syntheticTicketIds, $marker, $scenario, $fixtureId, $cacheService);
                if (! $cacheCleanupSucceeded) {
                    Log::error('PrepareScheduledZnunyReviewFixtureCommand creation compensation failed', [
                        'source_task_id' => $taskId,
                        'scenario' => $scenario,
                        'fixture_id' => $fixtureId,
                        'marker' => $marker,
                        'synthetic_ticket_ids' => $syntheticTicketIds,
                    ]);
                }
            }

            Log::error('PrepareScheduledZnunyReviewFixtureCommand internal exception', [
                'exception' => $e,
                'task_id' => $taskId,
                'scenario' => $scenario,
            ]);
            $this->error('Fixture creation failed. See the application log for technical details.');

            return self::FAILURE;
        }

        // 6. Validation
        $validationResult = $this->validateFixture($reviewService, $fixtureAttempt->id, $scenario, $fixtureRun->id, $fixtureTask->id, $marker);

        if (! $validationResult['valid']) {
            if (isset($validationResult['internal_error'])) {
                $this->error('Fixture validation failed because the inspection service could not be completed.');
            } else {
                $this->error('Fixture validation failed: '.($validationResult['reason'] ?? 'Unknown error'));
            }

            // Compensation
            $cacheCleanupSucceeded = $this->removeSyntheticTickets($syntheticTicketIds, $marker, $scenario, $fixtureId, $cacheService);

            if (! $cacheCleanupSucceeded) {
                Log::error('PrepareScheduledZnunyReviewFixtureCommand post-commit compensation failed', [
                    'task_id' => $taskId,
                    'scenario' => $scenario,
                    'fixture_id' => $fixtureId,
                    'marker' => $marker,
                ]);
                $this->error('Fixture validation failed and automatic cleanup could not be completed safely.');
                $this->line('Run the cleanup command after reviewing the application log.');

                return self::FAILURE;
            }

            if (DB::transactionLevel() === 0) {
                try {
                    DB::transaction(function () use ($fixtureTask, $fixtureRun, $fixtureAttempt) {
                        // Delete retry runs for this attempt
                        ScheduledZnunyTaskRun::where('manual_retry_of_attempt_id', $fixtureAttempt->id)->delete();
                        $fixtureAttempt->delete();
                        $fixtureRun->delete();
                        $fixtureTask->forceDelete();
                    });
                } catch (Throwable $e) {
                    Log::error('PrepareScheduledZnunyReviewFixtureCommand database compensation exception', [
                        'exception' => $e,
                    ]);
                }
            }

            return self::FAILURE;
        }

        // 7. Output
        $this->line('');
        $this->info("Scenario: {$scenario}");
        $this->info("Source task ID: {$taskId}");
        $this->info("Fixture task ID: {$fixtureTask->id}");
        $this->info("Fixture run ID: {$fixtureRun->id}");
        $this->info("Fixture attempt ID: {$fixtureAttempt->id}");
        $this->info("Fixture marker: {$marker}");
        $this->info('Synthetic ticket IDs: '.implode(', ', $syntheticTicketIds));

        $this->info("Lookup status: {$validationResult['lookup_status']}");
        $this->info("Matches: {$validationResult['match_count']}");

        $this->info("Execution guard field: {$executionGuardField}");
        $this->info("Review URL: /admin/scheduled-znuny-task-runs/{$fixtureRun->id}/review");
        $this->line('');

        $this->line('No source task was modified.');
        $this->line('No scheduler execution was performed.');
        $this->line('No real Znuny ticket was created.');
        $this->line('No external Znuny or Zabbix API was called.');

        if ($scenario === 'not_found') {
            $this->line('');
            $this->line('Opening the review page is read-only.');
            $this->line('Confirming Manual Retry will create a normal pending retry run against the isolated fixture task.');
            $this->line('The fixture task execution guard prevents that retry from reaching Znuny.');
            $this->line('Run --cleanup after verification.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int>  &$syntheticTicketIds  Passed by reference for complete compensation
     */
    private function generateSyntheticTickets(string $scenario, string $marker, ZnunyTicketCacheService $cacheService, array &$syntheticTicketIds): array
    {
        if ($scenario === 'found') {
            $ticketId = $this->allocateCollisionFreeId($cacheService, $syntheticTicketIds);
            $syntheticTicketIds[] = $ticketId;
            $this->insertAndVerifySyntheticTicket($cacheService, $ticketId, "UI4C4B-{$ticketId}", "Fixture found {$marker}");
        } elseif ($scenario === 'multiple') {
            $ticketId1 = $this->allocateCollisionFreeId($cacheService, $syntheticTicketIds);
            $syntheticTicketIds[] = $ticketId1;
            $this->insertAndVerifySyntheticTicket($cacheService, $ticketId1, "UI4C4B-{$ticketId1}", "Fixture multi 1 {$marker}");

            $ticketId2 = $this->allocateCollisionFreeId($cacheService, $syntheticTicketIds);
            $syntheticTicketIds[] = $ticketId2;
            $this->insertAndVerifySyntheticTicket($cacheService, $ticketId2, "UI4C4B-{$ticketId2}", "Fixture multi 2 {$marker}");
        } elseif ($scenario === 'not_found') {
            // No tickets created.
        } elseif ($scenario === 'unavailable') {
            $ticketId = $this->allocateCollisionFreeId($cacheService, $syntheticTicketIds);
            $syntheticTicketIds[] = $ticketId;
            $this->insertAndVerifySyntheticTicket($cacheService, $ticketId, '', "Fixture unavailable {$marker}");
        }

        return $syntheticTicketIds;
    }

    private function allocateCollisionFreeId(ZnunyTicketCacheService $cacheService, array $alreadyAllocatedIds): int
    {
        $baseId = 900000000 + random_int(1, 999999);
        $attempts = 0;
        $maxAttempts = 100;

        while ($attempts < $maxAttempts) {
            $candidate = $baseId + $attempts;

            if (! in_array($candidate, $alreadyAllocatedIds, true) && $cacheService->getTicket($candidate) === null) {
                return $candidate;
            }

            $attempts++;
        }

        throw new RuntimeException("Could not allocate a collision-free synthetic TicketID after {$maxAttempts} attempts.");
    }

    private function insertAndVerifySyntheticTicket(ZnunyTicketCacheService $cacheService, int $ticketId, string $ticketNumber, string $title): void
    {
        $ticket = [
            'TicketID' => $ticketId,
            'TicketNumber' => $ticketNumber,
            'Title' => $title,
            'StateID' => 1,
            'State' => 'new',
            'StateType' => 'open',
            'QueueID' => 1,
            'Queue' => 'Raw',
            'OwnerID' => 1,
            'Owner' => 'root@localhost',
            'PriorityID' => 3,
            'Priority' => '3 normal',
            'Changed' => Carbon::now('UTC')->toDateTimeString(),
            'Created' => Carbon::now('UTC')->toDateTimeString(),
        ];

        $cacheService->upsertTicket($ticket);

        $retrieved = $cacheService->getTicket($ticketId);

        if (! $retrieved) {
            throw new RuntimeException("Post-write verification failed: ticket {$ticketId} is missing from cache.");
        }

        if (($retrieved['TicketID'] ?? null) != $ticketId) {
            throw new RuntimeException('Post-write verification failed: TicketID mismatch.');
        }

        if (($retrieved['TicketNumber'] ?? null) !== $ticketNumber) {
            throw new RuntimeException('Post-write verification failed: TicketNumber mismatch.');
        }

        if (($retrieved['Title'] ?? null) !== $title) {
            throw new RuntimeException('Post-write verification failed: Title mismatch.');
        }
    }

    private function validateFixture(
        ScheduledZnunyTicketCreationAttemptManualReviewService $reviewService,
        int $attemptId,
        string $scenario,
        int $fixtureRunId,
        int $fixtureTaskId,
        string $marker
    ): array {
        try {
            $rawContext = $reviewService->inspect($attemptId);

            if (($rawContext['found'] ?? null) !== true) {
                return ['valid' => false, 'reason' => 'found !== true'];
            }
            if (($rawContext['eligible'] ?? null) !== true) {
                return ['valid' => false, 'reason' => 'eligible !== true'];
            }
            if (($rawContext['resolved'] ?? null) !== false) {
                return ['valid' => false, 'reason' => 'resolved !== false'];
            }

            $attemptStatus = $rawContext['attempt_status'] instanceof \BackedEnum ? $rawContext['attempt_status']->value : $rawContext['attempt_status'];
            if ($attemptStatus !== ZnunyTicketCreationAttemptStatus::Uncertain->value) {
                return ['valid' => false, 'reason' => 'attempt status is not uncertain'];
            }

            if (($rawContext['run_id'] ?? null) !== $fixtureRunId) {
                return ['valid' => false, 'reason' => 'run ID mismatch'];
            }
            if (($rawContext['task_id'] ?? null) !== $fixtureTaskId) {
                return ['valid' => false, 'reason' => 'task ID mismatch'];
            }
            if (($rawContext['marker'] ?? null) !== $marker) {
                return ['valid' => false, 'reason' => 'marker mismatch'];
            }

            $lookupStatus = $rawContext['lookup_status'] instanceof \BackedEnum ? $rawContext['lookup_status']->value : $rawContext['lookup_status'];

            $expectedLookupStatus = match ($scenario) {
                'found' => ScheduledZnunyTicketMarkerLookupStatus::Found->value,
                'multiple' => ScheduledZnunyTicketMarkerLookupStatus::Multiple->value,
                'not_found' => ScheduledZnunyTicketMarkerLookupStatus::NotFound->value,
                'unavailable' => ScheduledZnunyTicketMarkerLookupStatus::Unavailable->value,
            };

            if ($lookupStatus !== $expectedLookupStatus) {
                return ['valid' => false, 'reason' => "lookup status mismatch (expected {$expectedLookupStatus}, got {$lookupStatus})"];
            }

            $matches = $rawContext['matches'] ?? [];
            $expectedCount = match ($scenario) {
                'found' => 1,
                'multiple' => 2,
                default => 0,
            };

            if (count($matches) !== $expectedCount) {
                return ['valid' => false, 'reason' => "matches count mismatch (expected {$expectedCount}, got ".count($matches).')'];
            }

            return [
                'valid' => true,
                'lookup_status' => $lookupStatus,
                'match_count' => count($matches),
            ];
        } catch (Throwable $e) {
            Log::error('PrepareScheduledZnunyReviewFixtureCommand inspection exception', [
                'exception' => $e,
                'attempt_id' => $attemptId,
            ]);

            return ['valid' => false, 'internal_error' => true];
        }
    }

    private function findExistingFixtures(int $sourceTaskId, string $scenario): array
    {
        $fixtures = [];
        $runs = ScheduledZnunyTaskRun::where('run_type', 'manual')
            ->whereNotNull('payload_snapshot')
            ->get();

        foreach ($runs as $run) {
            $snapshot = is_string($run->payload_snapshot) ? json_decode($run->payload_snapshot, true) : $run->payload_snapshot;
            $meta = $snapshot['stage_4c4b_ui_fixture'] ?? null;

            if ($meta && ($meta['source_task_id'] ?? null) === $sourceTaskId && ($meta['scenario'] ?? null) === $scenario) {
                $version = $meta['version'] ?? null;
                $metaTaskId = $meta['source_task_id'] ?? null;
                $metaFixtureTaskId = $meta['fixture_task_id'] ?? null;
                $metaScenario = $meta['scenario'] ?? null;
                $fixtureId = $meta['fixture_id'] ?? null;
                $marker = $meta['marker'] ?? null;
                $syntheticTicketIds = $meta['synthetic_ticket_ids'] ?? null;
                $runTaskId = $run->scheduled_znuny_task_id;
                $runId = $run->id;

                $isValid = true;
                if ($version !== self::FIXTURE_VERSION) {
                    $isValid = false;
                }
                if (! is_int($metaTaskId) || $metaTaskId <= 0) {
                    $isValid = false;
                }
                if (! is_int($metaFixtureTaskId) || $metaFixtureTaskId <= 0) {
                    $isValid = false;
                }
                if ($metaFixtureTaskId === $metaTaskId) {
                    $isValid = false;
                }
                if ($metaFixtureTaskId !== $runTaskId) {
                    $isValid = false;
                }
                if (! in_array($metaScenario, ['found', 'multiple', 'not_found', 'unavailable'], true)) {
                    $isValid = false;
                }
                if (! is_string($fixtureId) || $fixtureId === '') {
                    $isValid = false;
                }
                if (! is_string($marker) || $marker === '') {
                    $isValid = false;
                }

                if (! is_array($syntheticTicketIds)) {
                    $isValid = false;
                } else {
                    foreach ($syntheticTicketIds as $sid) {
                        if (! is_int($sid) || $sid <= 0) {
                            $isValid = false;
                            break;
                        }
                    }
                }

                if (! is_int($runTaskId) || $runTaskId <= 0) {
                    $isValid = false;
                }
                if (! is_int($runId) || $runId <= 0) {
                    $isValid = false;
                }

                if ($isValid) {
                    $count = count($syntheticTicketIds);
                    if ($metaScenario === 'found' && $count !== 1) {
                        $isValid = false;
                    }
                    if ($metaScenario === 'multiple') {
                        if ($count !== 2 || $syntheticTicketIds[0] === $syntheticTicketIds[1]) {
                            $isValid = false;
                        }
                    }
                    if ($metaScenario === 'not_found' && $count !== 0) {
                        $isValid = false;
                    }
                    if ($metaScenario === 'unavailable' && $count !== 1) {
                        $isValid = false;
                    }
                }

                if ($isValid) {
                    $fixtureTask = ScheduledZnunyTask::withTrashed()->find($metaFixtureTaskId);
                    if (! $fixtureTask) {
                        $isValid = false;
                    } else {
                        $expectedPrefix = sprintf('%s[source:%d][%s][%s]', self::PREFIX, $metaTaskId, $metaScenario, $fixtureId);
                        if (! str_starts_with($fixtureTask->name, $expectedPrefix)) {
                            $isValid = false;
                        }
                        if ($fixtureTask->queue_id !== null) {
                            $isValid = false;
                        }
                        if ($fixtureTask->queue_name !== null) {
                            $isValid = false;
                        }
                        if ($fixtureTask->enabled !== false) {
                            $isValid = false;
                        }
                    }
                }

                if (! $isValid) {
                    Log::warning('Malformed fixture metadata ignored', [
                        'run_id' => $runId,
                        'source_task_id' => $sourceTaskId,
                        'scenario' => $scenario,
                        'mismatch_category' => 'metadata_or_ownership_mismatch',
                    ]);

                    return ['fixtures' => [], 'malformed_found' => true];
                }

                $attempt = ZnunyTicketCreationAttempt::where('source_type', 'scheduled_run')->where('source_id', $run->id)->first();
                $attemptId = null;

                if ($attempt) {
                    if ($attempt->marker !== $marker) {
                        Log::warning('Malformed fixture metadata ignored', [
                            'run_id' => $runId,
                            'source_task_id' => $sourceTaskId,
                            'scenario' => $scenario,
                            'mismatch_category' => 'attempt_marker_mismatch',
                        ]);

                        return ['fixtures' => [], 'malformed_found' => true];
                    }
                    $attemptId = $attempt->id;
                }

                $fixtures[] = [
                    'task_id' => $metaFixtureTaskId,
                    'run_id' => $runId,
                    'attempt_id' => $attemptId,
                    'scenario' => $scenario,
                    'fixture_id' => $fixtureId,
                    'marker' => $marker,
                    'synthetic_ticket_ids' => $syntheticTicketIds,
                ];
            }
        }

        return ['fixtures' => $fixtures, 'malformed_found' => false];
    }

    private function printFixtureInfo(array $fixture): void
    {
        $this->info("Fixture task ID: {$fixture['task_id']}");
        $this->info("Fixture run ID: {$fixture['run_id']}");
        if ($fixture['attempt_id']) {
            $this->info("Fixture attempt ID: {$fixture['attempt_id']}");
        }
        $this->info("Scenario: {$fixture['scenario']}");
        $this->info("Review URL: /admin/scheduled-znuny-task-runs/{$fixture['run_id']}/review");
    }

    private function cleanupFixtures(array $fixtures, ZnunyTicketCacheService $cacheService): bool
    {
        try {
            foreach ($fixtures as $fixture) {
                // 1. Remove synthetic tickets safely
                if (! $this->removeSyntheticTickets($fixture['synthetic_ticket_ids'], $fixture['marker'], $fixture['scenario'], $fixture['fixture_id'], $cacheService)) {
                    // Ownership verification failed, do not proceed with database cleanup
                    return false;
                }

                DB::transaction(function () use ($fixture) {
                    // 2. Pending/manual retry runs referencing the fixture attempt
                    if ($fixture['attempt_id']) {
                        ScheduledZnunyTaskRun::where('manual_retry_of_attempt_id', $fixture['attempt_id'])->delete();

                        // 3. Fixture attempt
                        ZnunyTicketCreationAttempt::where('id', $fixture['attempt_id'])->delete();
                    }

                    // 4. Fixture run
                    ScheduledZnunyTaskRun::where('id', $fixture['run_id'])->delete();

                    // 5. Fixture task
                    if ($fixture['task_id']) {
                        ScheduledZnunyTask::withTrashed()
                            ->where('id', $fixture['task_id'])
                            ->forceDelete();
                    }
                });
            }

            return true;
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            Log::error('PrepareScheduledZnunyReviewFixtureCommand cleanup exception', [
                'exception' => $e,
            ]);

            return false;
        }
    }

    private function verifyTicketOwnership(array $ticket, string $scenario, string $marker, int $index, int $expectedTicketId): bool
    {
        if (($ticket['TicketID'] ?? null) != $expectedTicketId) {
            return false;
        }

        $title = $ticket['Title'] ?? '';
        if ($marker === '' || strpos($title, $marker) === false) {
            return false;
        }

        $ticketNumber = $ticket['TicketNumber'] ?? null;

        if ($scenario === 'found') {
            if (! str_starts_with($title, 'Fixture found ')) {
                return false;
            }
            // Title ends with or contains exact non-empty marker. We already checked contains above, so this is valid.
            if ($ticketNumber !== "UI4C4B-{$expectedTicketId}") {
                return false;
            }
        } elseif ($scenario === 'multiple') {
            if ($index === 0) {
                if (! str_starts_with($title, 'Fixture multi 1 ')) {
                    return false;
                }
            } else {
                if (! str_starts_with($title, 'Fixture multi 2 ')) {
                    return false;
                }
            }
            if ($ticketNumber !== "UI4C4B-{$expectedTicketId}") {
                return false;
            }
        } elseif ($scenario === 'unavailable') {
            if (! str_starts_with($title, 'Fixture unavailable ')) {
                return false;
            }
            if ($ticketNumber !== '') {
                return false;
            }
        }

        return true;
    }

    private function removeSyntheticTickets(array $ticketIds, string $marker, string $scenario, string $fixtureId, ZnunyTicketCacheService $cacheService): bool
    {
        if ($scenario === 'not_found' || empty($ticketIds)) {
            return true;
        }

        // Initial verification check for all tickets
        foreach ($ticketIds as $index => $ticketId) {
            $cachedTicket = $cacheService->getTicket($ticketId);
            if ($cachedTicket === null) {
                continue; // Already absent
            }

            if (! $this->verifyTicketOwnership($cachedTicket, $scenario, $marker, $index, $ticketId)) {
                Log::warning('Cleanup: Redis ticket ownership verification failed', [
                    'fixture_id' => $fixtureId,
                    'scenario' => $scenario,
                    'marker' => $marker,
                    'ticket_id' => $ticketId,
                    'mismatch_category' => 'initial_ownership_check',
                ]);

                return false;
            }
        }

        // Final verification check immediately before removal
        foreach ($ticketIds as $index => $ticketId) {
            $cachedTicket = $cacheService->getTicket($ticketId);
            if ($cachedTicket === null) {
                continue;
            }

            if (! $this->verifyTicketOwnership($cachedTicket, $scenario, $marker, $index, $ticketId)) {
                Log::warning('Cleanup: Redis ticket ownership recheck failed immediately before forget', [
                    'fixture_id' => $fixtureId,
                    'scenario' => $scenario,
                    'marker' => $marker,
                    'ticket_id' => $ticketId,
                    'mismatch_category' => 'recheck_ownership_check',
                ]);

                return false;
            }

            $cacheService->forgetTicket($ticketId);
        }

        return true;
    }
}
