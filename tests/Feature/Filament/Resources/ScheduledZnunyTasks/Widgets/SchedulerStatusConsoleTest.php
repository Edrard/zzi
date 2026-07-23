<?php

namespace Tests\Feature\Filament\Resources\ScheduledZnunyTasks\Widgets;

use App\Filament\Resources\ScheduledZnunyTasks\Widgets\SchedulerStatusConsole;
use App\Models\User;
use App\Services\SchedulerSafetyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class SchedulerStatusConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected function getAdmin(): User
    {
        return User::factory()->create(['role' => 'admin', 'show_scheduled_tasks_status_panel' => true]);
    }

    protected function getOperator(): User
    {
        return User::factory()->create(['role' => 'operator', 'show_scheduled_tasks_status_panel' => true]);
    }

    protected function getViewer(): User
    {
        return User::factory()->create(['role' => 'viewer', 'show_scheduled_tasks_status_panel' => true]);
    }

    public function test_admin_livewire_invocation_succeeds(): void
    {
        $admin = $this->getAdmin();

        $this->mock(SchedulerSafetyService::class, function (MockInterface $mock) {
            $mock->shouldReceive('enableScheduler')->once();
            $mock->shouldReceive('disableScheduler')->with('Manually disabled by admin')->once();
            $mock->shouldReceive('pauseScheduler')->with('Manually paused by admin')->once();
            $mock->shouldReceive('clearPause')->once();
        });

        Livewire::actingAs($admin)
            ->test(SchedulerStatusConsole::class)
            ->call('enableScheduler')
            ->assertNotified()
            ->call('disableScheduler')
            ->assertNotified()
            ->call('pauseScheduler')
            ->assertNotified()
            ->call('clearPause')
            ->assertNotified();
    }

    public static function methodProvider(): array
    {
        return [
            ['enableScheduler'],
            ['disableScheduler'],
            ['pauseScheduler'],
            ['clearPause'],
        ];
    }

    #[DataProvider('methodProvider')]
    public function test_admin_direct_invocation_succeeds(string $method): void
    {
        $admin = $this->getAdmin();
        $this->actingAs($admin);

        $this->mock(SchedulerSafetyService::class, function (MockInterface $mock) use ($method) {
            if ($method === 'disableScheduler') {
                $mock->shouldReceive('disableScheduler')->with('Manually disabled by admin')->once();
            } elseif ($method === 'pauseScheduler') {
                $mock->shouldReceive('pauseScheduler')->with('Manually paused by admin')->once();
            } else {
                $mock->shouldReceive($method)->once();
            }
        });

        $widget = new SchedulerStatusConsole();
        $widget->$method();
        // Assertion handled by Mockery expectations.
    }

    #[DataProvider('methodProvider')]
    public function test_operator_direct_invocation_denied(string $method): void
    {
        $operator = $this->getOperator();
        $this->actingAs($operator);

        $this->mock(SchedulerSafetyService::class, function (MockInterface $mock) use ($method) {
            $mock->shouldNotReceive($method);
        });

        $widget = new SchedulerStatusConsole();

        try {
            $widget->$method();
            $this->fail("Expected 403 on $method");
        } catch (HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }
    }

    #[DataProvider('methodProvider')]
    public function test_viewer_direct_invocation_denied(string $method): void
    {
        $viewer = $this->getViewer();
        $this->actingAs($viewer);

        $this->mock(SchedulerSafetyService::class, function (MockInterface $mock) use ($method) {
            $mock->shouldNotReceive($method);
        });

        $widget = new SchedulerStatusConsole();

        try {
            $widget->$method();
            $this->fail("Expected 403 on $method");
        } catch (HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }
    }
}
