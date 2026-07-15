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

        $actions = [
            ['clearSettingsCacheAction', 'clearSettingsCache', 'Settings cache cleared', 'Cached application settings were cleared successfully.'],
            ['clearZnunyAgentCacheAction', 'clearZnunyAgentCache', 'Znuny agent cache cleared', 'Cached Znuny agent data was cleared successfully.'],
            ['clearZnunyQueueCacheAction', 'clearZnunyQueueCache', 'Znuny queue cache cleared', 'Cached Znuny queue data was cleared successfully.'],
            ['clearZnunyLookupCacheAction', 'clearZnunyLookupCache', 'Znuny lookup cache cleared', 'Cached Znuny lookup data was invalidated successfully.'],
            ['clearTicketArticleCacheAction', 'clearTicketArticleCache', 'Ticket article cache cleared', 'Cached Znuny ticket article data was invalidated successfully.'],
        ];

        foreach ($actions as [$actionMethod, $serviceMethod, $title, $body]) {
            $this->mock(RuntimeCacheMaintenanceService::class, function (MockInterface $mock) use ($serviceMethod) {
                $mock->shouldReceive($serviceMethod)->once();
            });

            Livewire::actingAs($admin)
                ->test(Settings::class)
                ->call($actionMethod)
                ->assertNotified(
                    Notification::make()
                        ->title($title)
                        ->body($body)
                        ->success()
                );
        }
    }

    public function test_admin_failure()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $actions = [
            ['clearSettingsCacheAction', 'clearSettingsCache', 'Settings cache cleared', 'Cached application settings were cleared successfully.', 'The Settings cache could not be cleared. Review the application logs for details.'],
            ['clearZnunyAgentCacheAction', 'clearZnunyAgentCache', 'Znuny agent cache cleared', 'Cached Znuny agent data was cleared successfully.', 'The Znuny Agent cache could not be cleared. Review the application logs for details.'],
            ['clearZnunyQueueCacheAction', 'clearZnunyQueueCache', 'Znuny queue cache cleared', 'Cached Znuny queue data was cleared successfully.', 'The Znuny Queue cache could not be cleared. Review the application logs for details.'],
            ['clearZnunyLookupCacheAction', 'clearZnunyLookupCache', 'Znuny lookup cache cleared', 'Cached Znuny lookup data was invalidated successfully.', 'The Znuny Lookup cache could not be cleared. Review the application logs for details.'],
            ['clearTicketArticleCacheAction', 'clearTicketArticleCache', 'Ticket article cache cleared', 'Cached Znuny ticket article data was invalidated successfully.', 'The Ticket Article cache could not be cleared. Review the application logs for details.'],
        ];

        foreach ($actions as [$actionMethod, $serviceMethod, $successTitle, $successBody, $failureBody]) {
            $this->mock(RuntimeCacheMaintenanceService::class, function (MockInterface $mock) use ($serviceMethod) {
                $mock->shouldReceive($serviceMethod)
                    ->once()
                    ->andThrow(new \Exception('Test exception'));
            });

            Livewire::actingAs($admin)
                ->test(Settings::class)
                ->call($actionMethod)
                ->assertNotified(
                    Notification::make()
                        ->title('Cache clearing failed')
                        ->body($failureBody)
                        ->danger()
                )
                ->assertNotNotified(
                    Notification::make()
                        ->title($successTitle)
                        ->body($successBody)
                        ->success()
                );
        }
    }

    public function test_direct_non_admin_invocation()
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer);

        $actionMethods = [
            'clearSettingsCacheAction',
            'clearZnunyAgentCacheAction',
            'clearZnunyQueueCacheAction',
            'clearZnunyLookupCacheAction',
            'clearTicketArticleCacheAction',
        ];

        foreach ($actionMethods as $actionMethod) {
            $this->mock(RuntimeCacheMaintenanceService::class, function (MockInterface $mock) {
                // Ensure no maintenance method is called
                $mock->shouldReceive('clearSettingsCache')->never();
                $mock->shouldReceive('clearZnunyAgentCache')->never();
                $mock->shouldReceive('clearZnunyQueueCache')->never();
                $mock->shouldReceive('clearZnunyLookupCache')->never();
                $mock->shouldReceive('clearTicketArticleCache')->never();
            });

            $page = app(Settings::class);

            try {
                $page->$actionMethod();

                $this->fail("Direct non-admin invocation of $actionMethod should have been rejected.");
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
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

        $this->assertCount(5, $maintenanceActions);

        $expectedActions = [
            [
                'name' => 'clearSettingsCache',
                'label' => 'Clear Settings Cache',
                'modalHeading' => 'Clear Settings Cache?',
                'modalDescription' => 'This clears the cached application settings. Saved settings remain unchanged and will be loaded again when needed.',
                'modalSubmit' => 'Clear Settings Cache',
            ],
            [
                'name' => 'clearZnunyAgentCache',
                'label' => 'Clear Znuny Agent Cache',
                'modalHeading' => 'Clear Znuny Agent Cache?',
                'modalDescription' => 'This clears the cached active Znuny agent list. The next agent request may contact Znuny again.',
                'modalSubmit' => 'Clear Agent Cache',
            ],
            [
                'name' => 'clearZnunyQueueCache',
                'label' => 'Clear Znuny Queue Cache',
                'modalHeading' => 'Clear Znuny Queue Cache?',
                'modalDescription' => 'This clears the cached Znuny queue list. The next queue request may contact Znuny again.',
                'modalSubmit' => 'Clear Queue Cache',
            ],
            [
                'name' => 'clearZnunyLookupCache',
                'label' => 'Clear Znuny Lookup Cache',
                'modalHeading' => 'Clear Znuny Lookup Cache?',
                'modalDescription' => 'This invalidates reusable Znuny lookup data such as owners, CustomerUsers, states, priorities, types, queues, and search candidates.',
                'modalSubmit' => 'Clear Lookup Cache',
            ],
            [
                'name' => 'clearTicketArticleCache',
                'label' => 'Clear Ticket Article Cache',
                'modalHeading' => 'Clear Ticket Article Cache?',
                'modalDescription' => 'This invalidates cached Znuny ticket articles used by linked-ticket views. The next article request may contact Znuny again.',
                'modalSubmit' => 'Clear Article Cache',
            ],
        ];

        $viewer = User::factory()->create(['role' => 'viewer']);

        foreach ($maintenanceActions as $index => $action) {
            $expected = $expectedActions[$index];

            $this->assertEquals($expected['name'], $action->getName());
            $this->assertEquals($expected['label'], $action->getLabel());
            $this->assertTrue($action->isConfirmationRequired(), "Action {$expected['name']} should require confirmation");

            if (method_exists($action, 'getModalHeading')) {
                $this->assertEquals($expected['modalHeading'], $action->getModalHeading());
            }
            if (method_exists($action, 'getModalDescription')) {
                $this->assertEquals($expected['modalDescription'], $action->getModalDescription());
            }
            if (method_exists($action, 'getModalSubmitActionLabel')) {
                $this->assertEquals($expected['modalSubmit'], $action->getModalSubmitActionLabel());
            }

            $this->assertNotEquals('Clear All Runtime Caches', $action->getName());
            $this->assertNotEquals('Clear All Runtime Caches', $action->getLabel());

            $this->actingAs($admin);
            $this->assertTrue($action->isVisible(), "Action {$expected['name']} should be visible to admin");

            $this->actingAs($viewer);
            $this->assertFalse($action->isVisible(), "Action {$expected['name']} should not be visible to viewer");
        }
    }
}
