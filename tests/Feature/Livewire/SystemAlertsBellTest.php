<?php

namespace Tests\Feature\Livewire;

use App\Livewire\SystemAlertsBell;
use App\Models\SystemAlert;
use App\Models\User;
use App\Services\SystemAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class SystemAlertsBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_acknowledge_alert()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $alert = SystemAlert::create([
            'title' => 'Test Alert',
            'severity' => 'warning',
            'status' => 'active',
            'source' => 'system',
            'message' => 'test',
        ]);

        $this->mock(SystemAlertService::class, function (MockInterface $mock) use ($alert, $admin) {
            $mock->shouldReceive('acknowledge')
                ->once()
                ->withArgs(function ($argAlert, $argUserId) use ($alert, $admin) {
                    return $argAlert->id === $alert->id && $argUserId === $admin->id;
                });
        });

        Livewire::actingAs($admin)
            ->test(SystemAlertsBell::class)
            ->call('acknowledge', $alert->id);
    }

    public function test_operator_cannot_acknowledge_alert()
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $alert = SystemAlert::create([
            'title' => 'Test Alert',
            'severity' => 'warning',
            'status' => 'active',
            'source' => 'system',
            'message' => 'test',
        ]);

        $this->mock(SystemAlertService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('acknowledge');
        });

        Livewire::actingAs($operator)
            ->test(SystemAlertsBell::class)
            ->call('acknowledge', $alert->id)
            ->assertForbidden();

        $this->assertEquals('active', $alert->fresh()->status);
    }

    public function test_viewer_cannot_acknowledge_alert()
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $alert = SystemAlert::create([
            'title' => 'Test Alert',
            'severity' => 'warning',
            'status' => 'active',
            'source' => 'system',
            'message' => 'test',
        ]);

        $this->mock(SystemAlertService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('acknowledge');
        });

        Livewire::actingAs($viewer)
            ->test(SystemAlertsBell::class)
            ->call('acknowledge', $alert->id)
            ->assertForbidden();

        $this->assertEquals('active', $alert->fresh()->status);
    }
}
