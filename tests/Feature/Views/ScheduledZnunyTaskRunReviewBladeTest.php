<?php

namespace Tests\Feature\Views;

use App\Enums\ScheduledZnunyTicketMarkerLookupStatus;
use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\ZnunyTicketCreationAttempt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ScheduledZnunyTaskRunReviewBladeTest extends TestCase
{
    /**
     * Renders the Blade template by manually removing the <x-filament-panels::page>
     * wrapper so the content can be executed without the Filament/Livewire page testing harness.
     */
    private function renderBladeView(array $data): string
    {
        $content = file_get_contents(resource_path('views/filament/resources/scheduled-znuny-task-runs/pages/review-scheduled-znuny-task-run-attempt.blade.php'));
        $content = str_replace(['<x-filament-panels::page>', '</x-filament-panels::page>'], '', $content);

        return Blade::render($content, $data);
    }

    public function test_renders_root_with_failed_current_leaf_and_no_attempt(): void
    {
        $task = (new ScheduledZnunyTask)->forceFill([
            'id' => 1,
            'name' => 'Test Task',
            'enabled' => true,
        ]);

        $rootRun = (new ScheduledZnunyTaskRun)->forceFill([
            'id' => 10,
            'retry_sequence' => 0,
            'run_type' => 'creation',
            'status' => 'failed',
            'scheduled_for' => Carbon::parse('2026-07-31 10:00:00'),
            'started_at' => Carbon::parse('2026-07-31 10:00:01'),
            'finished_at' => Carbon::parse('2026-07-31 10:00:05'),
            'resolution_type' => 'retry_created',
        ]);
        $rootRun->resolved_at = Carbon::parse('2026-07-31 10:01:00');

        $activeRun = (new ScheduledZnunyTaskRun)->forceFill([
            'id' => 11,
            'retry_sequence' => 1,
            'run_type' => 'retry',
            'status' => 'failed',
            'scheduled_for' => Carbon::parse('2026-07-31 10:05:00'),
            'started_at' => Carbon::parse('2026-07-31 10:05:01'),
            'finished_at' => Carbon::parse('2026-07-31 10:05:05'),
            'resolution_type' => null,
            'root_run_id' => 10,
        ]);
        $activeRun->resolved_at = null;

        $activeRun->setRelation('task', $task);
        $activeRun->setRelation('latestZnunyTicketCreationAttempt', null);

        $retryChain = collect([$rootRun, $activeRun]);

        $data = [
            'retryChain' => $retryChain,
            'effectiveRootId' => 10,
            'currentLeafId' => 11,
            'isMalformedLineage' => false,
            'activeRun' => $activeRun,
            'reviewContext' => [],
            'lookupStatus' => null,
            'lookupMatches' => [],
            'lastRecheckedAt' => null,
        ];

        $html = $this->renderBladeView($data);

        $this->assertStringContainsString('data-run-id="10"', $html);
        $this->assertStringContainsString('data-run-id="11"', $html);
        $this->assertStringContainsString('data-current-leaf="true"', $html);
        $this->assertStringContainsString(__('scheduled_znuny_task_runs.review.empty.no_attempt'), $html);
    }

    public function test_renders_current_leaf_with_attempt_and_resolved_historical_root(): void
    {
        $task = (new ScheduledZnunyTask)->forceFill([
            'id' => 1,
            'name' => 'Test Task',
            'enabled' => true,
        ]);

        $rootRun = (new ScheduledZnunyTaskRun)->forceFill([
            'id' => 10,
            'retry_sequence' => 0,
            'run_type' => 'creation',
            'status' => 'failed',
            'scheduled_for' => Carbon::parse('2026-07-31 10:00:00'),
            'started_at' => Carbon::parse('2026-07-31 10:00:01'),
            'finished_at' => Carbon::parse('2026-07-31 10:00:05'),
            'resolution_type' => 'manual_link',
        ]);
        $rootRun->resolved_at = Carbon::parse('2026-07-31 10:01:00');

        $activeRun = (new ScheduledZnunyTaskRun)->forceFill([
            'id' => 11,
            'retry_sequence' => 1,
            'run_type' => 'retry',
            'status' => 'uncertain',
            'scheduled_for' => Carbon::parse('2026-07-31 10:05:00'),
            'started_at' => Carbon::parse('2026-07-31 10:05:01'),
            'finished_at' => Carbon::parse('2026-07-31 10:05:05'),
            'resolution_type' => null,
            'root_run_id' => 10,
        ]);
        $activeRun->resolved_at = null;

        $attempt = (new ZnunyTicketCreationAttempt)->forceFill([
            'id' => 100,
            'source_type' => 'scheduled_run',
            'source_id' => $activeRun->id,
            'subject_original' => 'Original Subject',
            'subject_sent' => 'Sent Subject',
            'check_attempts' => 3,
            'started_at' => Carbon::parse('2026-07-31 10:05:01'),
            'last_checked_at' => Carbon::parse('2026-07-31 10:06:00'),
        ]);

        $activeRun->setRelation('task', $task);
        $activeRun->setRelation('latestZnunyTicketCreationAttempt', $attempt);

        $retryChain = collect([$rootRun, $activeRun]);

        $data = [
            'retryChain' => $retryChain,
            'effectiveRootId' => 10,
            'currentLeafId' => 11,
            'isMalformedLineage' => false,
            'activeRun' => $activeRun,
            'reviewContext' => [
                'attempt_id' => 100,
                'attempt_status' => ZnunyTicketCreationAttemptStatus::Uncertain->value,
                'source_type' => 'scheduled_run',
                'marker' => 'ZBX-12345',
                'stored_ticket_id' => null,
                'stored_ticket_number' => null,
            ],
            'lookupStatus' => ScheduledZnunyTicketMarkerLookupStatus::NotFound->value,
            'lookupMatches' => [],
            'lastRecheckedAt' => null,
        ];

        $html = $this->renderBladeView($data);

        $this->assertStringContainsString('data-run-id="11"', $html);
        $this->assertStringContainsString('Original Subject', $html);
        $this->assertStringContainsString('Sent Subject', $html);
        $this->assertStringContainsString('ZBX-12345', $html);
        $this->assertStringContainsString('<p class="mt-1">3</p>', $html);
        $this->assertStringContainsString(__('scheduled_znuny_task_runs.resolution_types.manual_link'), $html);
    }
}
