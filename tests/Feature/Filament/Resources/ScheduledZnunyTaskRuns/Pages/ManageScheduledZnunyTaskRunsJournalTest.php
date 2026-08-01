<?php

namespace Tests\Feature\Filament\Resources\ScheduledZnunyTaskRuns\Pages;

use App\Filament\Resources\ScheduledZnunyTaskRuns\Pages\ManageScheduledZnunyTaskRuns;
use App\Models\ScheduledZnunyTask;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ManageScheduledZnunyTaskRunsJournalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ScheduledZnunyTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'admin']);

        $this->task = ScheduledZnunyTask::create([
            'name' => 'Journal test task',
            'enabled' => true,
            'queue_name' => 'Q1',
            'subject' => 'Subject',
            'body' => 'Body',
        ]);
    }

    public function test_hidden_view_action_can_be_mounted_without_rendering_a_visible_trigger(): void
    {
        $run = $this->createRun([
            'task_name_snapshot' => 'Standalone run',
            'status' => 'success',
            'scheduled_for' => now()->startOfSecond(),
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(ManageScheduledZnunyTaskRuns::class)
            ->assertSuccessful()
            ->assertTableActionExists('view')
            ->assertTableActionHidden('view', $run)
            ->mountTableAction('view', $run)
            ->assertHasNoTableActionErrors()
            ->assertSuccessful();

        $this->assertSame('view', $component->instance()->getMountedAction()?->getName());
    }

    public function test_compact_columns_and_retry_presentation_are_rendered(): void
    {
        $originalLocale = app()->getLocale();

        try {
            app()->setLocale('uk');

            $baseTime = now()->startOfSecond();

            $standalone = $this->createRun([
                'task_name_snapshot' => 'Standalone',
                'status' => 'success',
                'scheduled_for' => $baseTime->copy()->addSeconds(4),
            ]);

            $root = $this->createRun([
                'task_name_snapshot' => 'Retry chain',
                'status' => 'failed',
                'scheduled_for' => $baseTime->copy()->addSeconds(3),
                'resolved_at' => $baseTime->copy()->addSeconds(3),
                'resolution_type' => ScheduledZnunyTaskRun::RESOLUTION_TYPE_RETRY_CREATED,
            ]);

            $child = $this->createRun([
                'task_name_snapshot' => 'Retry chain',
                'run_type' => 'manual_retry',
                'status' => 'failed',
                'scheduled_for' => $baseTime->copy()->addSeconds(2),
                'root_run_id' => $root->id,
                'parent_run_id' => $root->id,
                'retry_sequence' => 1,
                'resolved_at' => $baseTime->copy()->addSeconds(2),
                'resolution_type' => ScheduledZnunyTaskRun::RESOLUTION_TYPE_RETRY_CREATED,
            ]);

            $leaf = $this->createRun([
                'task_name_snapshot' => 'Retry chain',
                'run_type' => 'manual_retry',
                'status' => 'failed',
                'scheduled_for' => $baseTime->copy()->addSecond(),
                'root_run_id' => $root->id,
                'parent_run_id' => $child->id,
                'retry_sequence' => 2,
            ]);

            $component = Livewire::actingAs($this->user)
                ->test(ManageScheduledZnunyTaskRuns::class)
                ->assertSuccessful()
                ->assertCanSeeTableRecords([$standalone, $root, $child, $leaf])
                ->assertCanRenderTableColumn('task_name_snapshot')
                ->assertCanRenderTableColumn('scheduled_for')
                ->assertCanRenderTableColumn('status')
                ->assertCanRenderTableColumn('retries')
                ->assertCanRenderTableColumn('ticket_number')
                ->assertTableColumnDoesNotExist('created_at')
                ->assertTableColumnDoesNotExist('run_type')
                ->assertTableColumnDoesNotExist('duration_ms')
                ->assertTableColumnDoesNotExist('chain_state')
                ->assertSee('Основний · 2 повтори')
                ->assertSee('1 з 2')
                ->assertSee('2 з 2')
                ->assertSee('Поточний')
                ->assertSee('—');

            $this->assertSame(0, $component->instance()->getRunChainState($root->id)['position']);
            $this->assertSame(2, $component->instance()->getRunChainState($root->id)['total_retries']);
            $this->assertFalse($component->instance()->getRunChainState($root->id)['current_leaf']);

            $this->assertSame(1, $component->instance()->getRunChainState($child->id)['position']);
            $this->assertFalse($component->instance()->getRunChainState($child->id)['current_leaf']);

            $this->assertSame(2, $component->instance()->getRunChainState($leaf->id)['position']);
            $this->assertTrue($component->instance()->getRunChainState($leaf->id)['current_leaf']);

            $this->assertSame(0, $component->instance()->getRunChainState($standalone->id)['total_retries']);
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_wrong_root_child_reference_marks_the_parent_chain_malformed(): void
    {
        $baseTime = now()->startOfSecond();

        $firstRoot = $this->createRun([
            'task_name_snapshot' => 'First root',
            'status' => 'failed',
            'scheduled_for' => $baseTime->copy()->addSeconds(3),
        ]);

        $secondRoot = $this->createRun([
            'task_name_snapshot' => 'Second root',
            'status' => 'failed',
            'scheduled_for' => $baseTime->copy()->addSeconds(2),
        ]);

        $wrongRootChild = $this->createRun([
            'task_name_snapshot' => 'Wrong-root child',
            'run_type' => 'manual_retry',
            'status' => 'failed',
            'scheduled_for' => $baseTime->copy()->addSecond(),
            'root_run_id' => $secondRoot->id,
            'parent_run_id' => $firstRoot->id,
            'retry_sequence' => 1,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(ManageScheduledZnunyTaskRuns::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$firstRoot, $secondRoot, $wrongRootChild]);

        $firstRootState = $component->instance()->getRunChainState($firstRoot->id);
        $secondRootState = $component->instance()->getRunChainState($secondRoot->id);

        $this->assertTrue($firstRootState['malformed_chain']);
        $this->assertFalse($firstRootState['valid_chain']);

        $this->assertTrue($secondRootState['malformed_chain']);
        $this->assertFalse($secondRootState['valid_chain']);
    }

    private function createRun(array $overrides = []): ScheduledZnunyTaskRun
    {
        return ScheduledZnunyTaskRun::create(array_merge([
            'scheduled_znuny_task_id' => $this->task->id,
            'task_name_snapshot' => 'Journal run',
            'run_type' => 'scheduled',
            'status' => 'failed',
            'scheduled_for' => now()->startOfSecond(),
            'root_run_id' => null,
            'parent_run_id' => null,
            'retry_sequence' => 0,
        ], $overrides));
    }
}
