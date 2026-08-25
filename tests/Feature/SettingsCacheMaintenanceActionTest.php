<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\AuditLog;
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
            ['clearTicketArticleCacheAction', 'clearTicketArticleCache', 'settings.znuny_ticket_article_cache.clear', 'znuny_ticket_article', 'Ticket article cache cleared', 'Cached Znuny ticket articles were cleared successfully.'],
        ];

        foreach ($actions as [$actionMethod, $serviceMethod, $auditAction, $cacheScope, $title, $body]) {
            AuditLog::query()->delete();

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

            $this->assertDatabaseCount('audit_logs', 1);
            $log = AuditLog::first();
            $this->assertEquals($auditAction, $log->action);
            $this->assertEquals('settings', $log->entity_type);
            $this->assertNull($log->entity_id);
            $this->assertEquals('settings_cache_tab', $log->context['source'] ?? null);
            $this->assertEquals($cacheScope, $log->context['cache_scope'] ?? null);
            $this->assertEquals('success', $log->context['status'] ?? null);
            $this->assertArrayNotHasKey('exception_class', $log->context);
        }
    }

    public function test_admin_failure()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $actions = [
            ['clearTicketArticleCacheAction', 'clearTicketArticleCache', 'settings.znuny_ticket_article_cache.clear', 'znuny_ticket_article', 'Ticket article cache cleared', 'Cached Znuny ticket articles were cleared successfully.', 'The Ticket Article cache could not be cleared. Review the application logs for details.'],
        ];

        foreach ($actions as [$actionMethod, $serviceMethod, $auditAction, $cacheScope, $successTitle, $successBody, $failureBody]) {
            AuditLog::query()->delete();

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

            $this->assertDatabaseCount('audit_logs', 1);
            $log = AuditLog::first();
            $this->assertEquals($auditAction, $log->action);
            $this->assertEquals('settings', $log->entity_type);
            $this->assertNull($log->entity_id);
            $this->assertEquals('settings_cache_tab', $log->context['source'] ?? null);
            $this->assertEquals($cacheScope, $log->context['cache_scope'] ?? null);
            $this->assertEquals('failed', $log->context['status'] ?? null);
            $this->assertEquals(\Exception::class, $log->context['exception_class'] ?? null);

            $contextJson = json_encode($log->context);
            $this->assertStringNotContainsString('Test exception', $contextJson);
        }
    }

    public function test_direct_non_admin_invocation()
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer);

        $actionMethods = [
            'clearTicketArticleCacheAction',
        ];

        foreach ($actionMethods as $actionMethod) {
            AuditLog::query()->delete();

            $this->mock(RuntimeCacheMaintenanceService::class, function (MockInterface $mock) {
                $mock->shouldReceive('clearTicketArticleCache')->never();
            });

            $page = app(Settings::class);

            try {
                $page->$actionMethod();

                $this->fail("Direct non-admin invocation of $actionMethod should have been rejected.");
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }

            $this->assertDatabaseCount('audit_logs', 0);
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

        $expectedActions = [
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

            $this->actingAs($admin);
            $this->assertTrue($action->isVisible(), "Action {$expected['name']} should be visible to admin");

            $this->actingAs($viewer);
            $this->assertFalse($action->isVisible(), "Action {$expected['name']} should not be visible to viewer");
        }
    }
}
