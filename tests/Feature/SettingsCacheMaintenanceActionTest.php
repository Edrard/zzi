<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\User;
use App\Services\RuntimeCacheMaintenanceService;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SettingsCacheMaintenanceActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('app:ensure-settings-defaults');
    }

    public function test_admin_success()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mock(RuntimeCacheMaintenanceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('clearSettingsCache')->once();
        });

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->call('clearSettingsCacheAction')
            ->assertNotified(
                Notification::make()
                    ->title('Settings cache cleared')
                    ->body('Cached application settings were cleared successfully.')
                    ->success()
            );
    }

    public function test_admin_failure()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mock(RuntimeCacheMaintenanceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('clearSettingsCache')
                ->once()
                ->andThrow(new \Exception('Test exception'));
        });

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->call('clearSettingsCacheAction')
            ->assertNotified(
                Notification::make()
                    ->title('Cache clearing failed')
                    ->body('The Settings cache could not be cleared. Review the application logs for details.')
                    ->danger()
            )
            ->assertNotNotified(
                Notification::make()
                    ->title('Settings cache cleared')
                    ->body('Cached application settings were cleared successfully.')
                    ->success()
            );
    }

    public function test_direct_non_admin_invocation()
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->mock(RuntimeCacheMaintenanceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('clearSettingsCache')->never();
        });

        $this->actingAs($viewer);

        $page = app(Settings::class);

        try {
            $page->clearSettingsCacheAction();

            $this->fail('Direct non-admin invocation should have been rejected.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_action_schema_metadata()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $maintenanceSection = null;

        $searchSection = function ($components) use (&$searchSection, &$maintenanceSection) {
            foreach ($components as $c) {
                if (class_basename($c) === 'Section' && method_exists($c, 'getHeading') && $c->getHeading() === 'Runtime Cache Maintenance') {
                    $maintenanceSection = $c;
                }
                if (method_exists($c, 'getChildComponents')) {
                    $searchSection($c->getChildComponents());
                }
            }
        };

        $searchSection($schema);

        $this->assertNotNull($maintenanceSection, 'Runtime Cache Maintenance section not found');
        $this->assertEquals('Clear individual application runtime caches without changing saved settings or clearing unrelated cache scopes.', $maintenanceSection->getDescription());

        $maintenanceActions = [];

        $searchActions = function ($components) use (&$searchActions, &$maintenanceActions) {
            foreach ($components as $c) {
                if (class_basename($c) === 'Action' && method_exists($c, 'getName')) {
                    $maintenanceActions[] = $c;
                }
                if (method_exists($c, 'getChildComponents')) {
                    $searchActions($c->getChildComponents());
                }
            }
        };

        $searchActions($maintenanceSection->getChildComponents());

        $this->assertCount(1, $maintenanceActions);

        $clearSettingsAction = $maintenanceActions[0];

        $this->assertEquals('clearSettingsCache', $clearSettingsAction->getName());
        $this->assertEquals('Clear Settings Cache', $clearSettingsAction->getLabel());
        $this->assertTrue($clearSettingsAction->isConfirmationRequired(), 'Action should require confirmation');

        $this->actingAs($admin);
        $this->assertTrue($clearSettingsAction->isVisible());

        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer);
        $this->assertFalse($clearSettingsAction->isVisible());
    }
}
