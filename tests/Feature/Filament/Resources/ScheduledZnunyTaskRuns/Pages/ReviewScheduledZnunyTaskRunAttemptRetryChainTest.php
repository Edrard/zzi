<?php

namespace Tests\Feature\Filament\Resources\ScheduledZnunyTaskRuns\Pages;

use App\Enums\ScheduledZnunyTicketMarkerLookupStatus;
use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Filament\Resources\ScheduledZnunyTaskRuns\Pages\ReviewScheduledZnunyTaskRunAttempt;
use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\User;
use App\Models\ZnunyTicketCreationAttempt;
use App\Services\Znuny\ScheduledZnunyTicketCreationAttemptManualReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class ReviewScheduledZnunyTaskRunAttemptRetryChainTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ScheduledZnunyTask $task;

    public array $mockState = [
        'lookup_status' => ScheduledZnunyTicketMarkerLookupStatus::NotFound->value,
        'attempt_status' => ZnunyTicketCreationAttemptStatus::Uncertain->value,
        'eligible' => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'admin']);
        $this->task = ScheduledZnunyTask::create(['name' => 'T', 'enabled' => true, 'queue_name' => 'Q1', 'subject' => 'S', 'body' => 'B']);

        $this->mock(ScheduledZnunyTicketCreationAttemptManualReviewService::class, function (MockInterface $mock) {
            $handler = function ($attemptId) {
                $attempt = ZnunyTicketCreationAttempt::find($attemptId);

                return [
                    'attempt_id' => $attemptId,
                    'run_id' => $attempt ? $attempt->source_id : null,
                    'task_id' => $this->task->id,
                    'lookup_status' => $this->mockState['lookup_status'],
                    'attempt_status' => $this->mockState['attempt_status'],
                    'matches' => [],
                    'found' => false,
                    'eligible' => $this->mockState['eligible'],
                    'resolved' => false,
                ];
            };
            $mock->shouldReceive('inspect')->andReturnUsing($handler);
            $mock->shouldReceive('forceRecheck')->andReturnUsing($handler);
        });
    }

    private function createAttemptForRun(ScheduledZnunyTaskRun $run): void
    {
        ZnunyTicketCreationAttempt::create([
            'source_type' => 'scheduled_run',
            'source_id' => $run->id,
            'marker' => 'm_'.$run->id,
            'subject_original' => 's',
            'subject_sent' => 's',
            'status' => 'uncertain',
        ]);
    }

    private function createThreeRunChain(): array
    {
        $root = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $this->task->id,
            'task_name_snapshot' => 'T',
            'run_type' => 'scheduled',
            'status' => 'failed',
            'scheduled_for' => now(),
            'root_run_id' => null,
            'parent_run_id' => null,
            'retry_sequence' => 0,
            'resolved_at' => now(),
            'resolution_type' => 'retry_created',
        ]);
        $child = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $this->task->id,
            'task_name_snapshot' => 'T',
            'run_type' => 'manual_retry',
            'status' => 'failed',
            'scheduled_for' => now()->addSeconds(1),
            'root_run_id' => $root->id,
            'parent_run_id' => $root->id,
            'retry_sequence' => 1,
            'resolved_at' => now(),
            'resolution_type' => 'unknown_reason',
        ]);
        $leaf = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $this->task->id,
            'task_name_snapshot' => 'T',
            'run_type' => 'manual_retry',
            'status' => 'failed',
            'scheduled_for' => now()->addSeconds(2),
            'root_run_id' => $root->id,
            'parent_run_id' => $child->id,
            'retry_sequence' => 2,
        ]);

        $this->createAttemptForRun($root);
        $this->createAttemptForRun($child);
        $this->createAttemptForRun($leaf);

        return [$root, $child, $leaf];
    }

    public function test_complete_three_run_chain_from_root()
    {
        [$root, $child, $leaf] = $this->createThreeRunChain();

        Livewire::actingAs($this->user)
            ->test(ReviewScheduledZnunyTaskRunAttempt::class, ['record' => $root->id])
            ->assertSeeHtmlInOrder([
                'data-run-id="'.$root->id.'"',
                'data-run-id="'.$child->id.'"',
                'data-run-id="'.$leaf->id.'"',
            ])
            ->assertSeeHtml('data-run-id="'.$leaf->id.'"')
            ->assertSeeHtml('data-retry-sequence="'.$leaf->retry_sequence.'"')
            ->assertSeeHtml('data-current-leaf="true"')
            ->assertSeeHtml('data-run-id="'.$root->id.'"')
            ->assertSeeHtml('data-retry-sequence="'.$root->retry_sequence.'"')
            ->assertSeeHtml('data-current-leaf="false"')
            ->assertSeeHtml('data-technical-status="failed"')
            ->assertActionVisible('manual_retry')
            ->assertActionDoesNotExist('retry_chain');
    }

    public function test_same_complete_chain_from_intermediate_context()
    {
        [$root, $child, $leaf] = $this->createThreeRunChain();

        Livewire::actingAs($this->user)
            ->test(ReviewScheduledZnunyTaskRunAttempt::class, ['record' => $child->id])
            ->assertSeeHtmlInOrder([
                'data-run-id="'.$root->id.'"',
                'data-run-id="'.$child->id.'"',
                'data-run-id="'.$leaf->id.'"',
            ])
            ->assertSeeHtml('data-run-id="'.$leaf->id.'"')
            ->assertSeeHtml('data-retry-sequence="'.$leaf->retry_sequence.'"')
            ->assertSeeHtml('data-current-leaf="true"')
            ->assertSeeHtml('data-run-id="'.$child->id.'"')
            ->assertSeeHtml('data-retry-sequence="'.$child->retry_sequence.'"')
            ->assertSeeHtml('data-current-leaf="false"');
    }

    public function test_resolved_presentation_and_fallback()
    {
        config()->set('app.locale', 'uk');
        app()->setLocale('uk');

        [$root, $child, $leaf] = $this->createThreeRunChain();

        Livewire::actingAs($this->user)
            ->test(ReviewScheduledZnunyTaskRunAttempt::class, ['record' => $root->id])
            ->assertSeeHtml('data-run-id="'.$root->id.'"')
            ->assertSeeHtml('data-retry-sequence="0"')
            ->assertSeeHtml('data-current-leaf="false"')
            ->assertSeeHtml('data-resolved="true"')
            ->assertSeeHtml('data-run-id="'.$child->id.'"')
            ->assertSeeHtml('data-retry-sequence="1"')
            ->assertSeeHtml('data-current-leaf="false"')
            ->assertSeeHtml('data-resolved="true"')
            ->assertSeeHtml('data-run-id="'.$leaf->id.'"')
            ->assertSeeHtml('data-retry-sequence="2"')
            ->assertSeeHtml('data-current-leaf="true"')
            ->assertSeeHtml('data-resolved="false"')
            ->assertSeeHtml('bg-gray-100') // Muted gray styling for resolved rows
            ->assertDontSeeHtml('opacity-75')
            ->assertSeeHtml('data-technical-status="failed"')
            ->assertSeeHtml($root->resolved_at->toDateTimeString())
            ->assertSee(__('scheduled_znuny_task_runs.resolution_types.retry_created'))
            ->assertSee('unknown_reason')
            ->assertDontSee('scheduled_znuny_task_runs.resolution_types.retry_created');
    }

    public function test_stale_root_retry_extends_current_failed_leaf()
    {
        $root = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $this->task->id,
            'task_name_snapshot' => 'T',
            'run_type' => 'scheduled',
            'status' => 'failed',
            'scheduled_for' => now(),
            'root_run_id' => null,
            'parent_run_id' => null,
            'retry_sequence' => 0,
            'resolved_at' => now(),
            'resolution_type' => 'manual',
        ]);
        $leaf = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $this->task->id,
            'task_name_snapshot' => 'T',
            'run_type' => 'manual_retry',
            'status' => 'failed',
            'scheduled_for' => now()->addSeconds(1),
            'root_run_id' => $root->id,
            'parent_run_id' => $root->id,
            'retry_sequence' => 1,
        ]);

        $this->createAttemptForRun($root);
        $this->createAttemptForRun($leaf);

        $initialCount = ScheduledZnunyTaskRun::count();

        Livewire::actingAs($this->user)
            ->test(ReviewScheduledZnunyTaskRunAttempt::class, ['record' => $root->id])
            ->callAction('manual_retry')
            ->assertNotified(__('scheduled_znuny_task_runs.review.notifications.manual_retry_success.title'))
            ->assertSeeHtml('data-retry-sequence="2"')
            ->assertSeeHtml('data-current-leaf="true"')
            ->assertSeeHtml('data-resolved="false"');

        $this->assertEquals($initialCount + 1, ScheduledZnunyTaskRun::count());
        $this->assertEquals(1, ScheduledZnunyTaskRun::where('parent_run_id', $root->id)->count());

        $newRun = ScheduledZnunyTaskRun::where('retry_sequence', 2)->first();
        $this->assertEquals($leaf->id, $newRun->parent_run_id);
        $this->assertEquals($root->id, $newRun->root_run_id);
        $this->assertEquals('pending', $newRun->status);
        $this->assertEquals($this->user->id, $newRun->created_by);

        $leaf->refresh();
        $this->assertNotNull($leaf->resolved_at);
        $this->assertEquals('retry_created', $leaf->resolution_type);
    }

    public function test_active_closed_malformed_and_duplicate_protection()
    {
        $root = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $this->task->id,
            'task_name_snapshot' => 'T',
            'run_type' => 'scheduled',
            'status' => 'failed',
            'scheduled_for' => now(),
            'root_run_id' => null,
            'parent_run_id' => null,
            'retry_sequence' => 0,
        ]);
        $this->createAttemptForRun($root);

        // 1. Pending leaf (Hidden)
        $root->update(['status' => 'pending']);
        Livewire::actingAs($this->user)
            ->test(ReviewScheduledZnunyTaskRunAttempt::class, ['record' => $root->id])
            ->assertActionHidden('manual_retry')
            ->assertActionDoesNotExist('retry_chain');

        // 2. Running leaf (Hidden)
        $root->update(['status' => 'running']);
        Livewire::actingAs($this->user)
            ->test(ReviewScheduledZnunyTaskRunAttempt::class, ['record' => $root->id])
            ->assertActionHidden('manual_retry');

        // 3. Success leaf (Hidden)
        $root->update(['status' => 'success']);
        Livewire::actingAs($this->user)
            ->test(ReviewScheduledZnunyTaskRunAttempt::class, ['record' => $root->id])
            ->assertActionHidden('manual_retry');

        // 4. Resolved leaf (Hidden)
        $root->update(['status' => 'failed', 'resolved_at' => now(), 'resolution_type' => 'manual']);
        Livewire::actingAs($this->user)
            ->test(ReviewScheduledZnunyTaskRunAttempt::class, ['record' => $root->id])
            ->assertActionHidden('manual_retry');

        // 5. Malformed Lineage (Hidden, throws error)
        $root->update(['status' => 'failed', 'resolved_at' => null, 'resolution_type' => null]);
        $malformed = ScheduledZnunyTaskRun::create([
            'scheduled_znuny_task_id' => $this->task->id,
            'task_name_snapshot' => 'T',
            'run_type' => 'manual_retry',
            'status' => 'failed',
            'scheduled_for' => now()->addSeconds(10),
            'root_run_id' => $root->id,
            'parent_run_id' => $root->id,
            'retry_sequence' => 0, // Invalid retry sequence! Duplicate sequence 0
        ]);
        $this->createAttemptForRun($malformed);

        Livewire::actingAs($this->user)
            ->test(ReviewScheduledZnunyTaskRunAttempt::class, ['record' => $malformed->id])
            ->assertNotified(__('scheduled_znuny_task_runs.review.notifications.malformed_lineage.title'))
            ->assertActionHidden('manual_retry')
            ->assertSee(__('scheduled_znuny_task_runs.review.notifications.malformed_lineage.body'));

        $malformed->delete();

        // 6. Eligibility state checks
        $this->mockState = [
            'lookup_status' => ScheduledZnunyTicketMarkerLookupStatus::NotFound->value,
            'attempt_status' => ZnunyTicketCreationAttemptStatus::Uncertain->value,
            'eligible' => true,
        ];
        $component = Livewire::actingAs($this->user)
            ->test(ReviewScheduledZnunyTaskRunAttempt::class, ['record' => $root->id])
            ->assertActionVisible('manual_retry');

        // lookup found
        $this->mockState['lookup_status'] = ScheduledZnunyTicketMarkerLookupStatus::Found->value;
        Livewire::actingAs($this->user)->test(ReviewScheduledZnunyTaskRunAttempt::class, ['record' => $root->id])
            ->assertActionHidden('manual_retry');

        // lookup multiple
        $this->mockState['lookup_status'] = ScheduledZnunyTicketMarkerLookupStatus::Multiple->value;
        Livewire::actingAs($this->user)->test(ReviewScheduledZnunyTaskRunAttempt::class, ['record' => $root->id])
            ->assertActionHidden('manual_retry');

        // eligible false
        $this->mockState['lookup_status'] = ScheduledZnunyTicketMarkerLookupStatus::NotFound->value;
        $this->mockState['eligible'] = false;

        Livewire::actingAs($this->user)->test(ReviewScheduledZnunyTaskRunAttempt::class, ['record' => $root->id])
            ->assertActionHidden('manual_retry');

        // attempt status not uncertain
        $this->mockState['eligible'] = true;
        $this->mockState['attempt_status'] = ZnunyTicketCreationAttemptStatus::Success->value;

        Livewire::actingAs($this->user)->test(ReviewScheduledZnunyTaskRunAttempt::class, ['record' => $root->id])
            ->assertActionHidden('manual_retry');

        // 7. Stale invocation safety
        $this->mockState = [
            'lookup_status' => ScheduledZnunyTicketMarkerLookupStatus::NotFound->value,
            'attempt_status' => ZnunyTicketCreationAttemptStatus::Uncertain->value,
            'eligible' => true,
        ];
        // Render while eligible
        $staleComponent = Livewire::actingAs($this->user)
            ->test(ReviewScheduledZnunyTaskRunAttempt::class, ['record' => $root->id])
            ->assertActionVisible('manual_retry');

        // Change state before invoke
        $this->mockState['eligible'] = false;
        $initialRunCount = ScheduledZnunyTaskRun::count();

        $staleComponent->callAction('manual_retry')
            ->assertNotified(__('scheduled_znuny_task_runs.review.notifications.changed.title'));

        $this->assertEquals($initialRunCount, ScheduledZnunyTaskRun::count());
    }
}
