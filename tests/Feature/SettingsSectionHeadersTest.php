<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsSectionHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('app:ensure-settings-defaults');
    }

    public function test_audit_log_tab_structure()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $sectionsOrder = [];
        $fieldsOrder = [];
        $auditLogTabFound = false;

        $search = function ($components, $inTab = false) use (&$search, &$sectionsOrder, &$fieldsOrder, &$auditLogTabFound) {
            foreach ($components as $c) {
                $type = class_basename($c);
                $label = method_exists($c, 'getLabel') ? $c->getLabel() : null;
                $heading = method_exists($c, 'getHeading') ? $c->getHeading() : null;
                $name = method_exists($c, 'getName') ? $c->getName() : null;

                $isThisTab = $inTab || ($type === 'Tab' && $label === 'Audit Log');

                if ($type === 'Tab' && $label === 'Audit Log') {
                    $auditLogTabFound = true;
                }

                if ($isThisTab && $type === 'Section' && $heading) {
                    $sectionsOrder[] = $heading;
                }

                if ($isThisTab && $name) {
                    $fieldsOrder[] = $name;
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents(), $isThisTab);
                }
            }
        };

        $search($schema);

        $this->assertTrue($auditLogTabFound, 'Audit Log tab should exist.');
        $this->assertEquals(['Audit Logging'], $sectionsOrder, 'Audit Logging section should exist inside Audit Log tab.');
        $this->assertEquals([
            'zabbix_problem_sync_audit_enabled',
            'znuny_closed_ticket_sync_audit_auto_enabled',
            'znuny_detailed_sync_audit_enabled',
            'znuny_ticket_workspace_sync_audit_enabled',
        ], $fieldsOrder, 'Fields must be present in exact order.');

        $component->assertSee('Configure which synchronization and Ticket Workspace operations are recorded in the application audit log.');
    }

    public function test_automation_tab_structure()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)->test(Settings::class);
        $schema = $component->instance()->getForm('form')->getComponents();

        $sectionsOrder = [];
        $fieldsOrder = [];
        $automationTabFound = false;

        $search = function ($components, $inTab = false) use (&$search, &$sectionsOrder, &$fieldsOrder, &$automationTabFound) {
            foreach ($components as $c) {
                $type = class_basename($c);
                $label = method_exists($c, 'getLabel') ? $c->getLabel() : null;
                $heading = method_exists($c, 'getHeading') ? $c->getHeading() : null;
                $name = method_exists($c, 'getName') ? $c->getName() : null;

                $isThisTab = $inTab || ($type === 'Tab' && $label === 'Automation');

                if ($type === 'Tab' && $label === 'Automation') {
                    $automationTabFound = true;
                }

                if ($isThisTab && $type === 'Section' && $heading) {
                    $sectionsOrder[] = $heading;
                }

                if ($isThisTab && $name) {
                    $fieldsOrder[] = $name;
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents(), $isThisTab);
                }
            }
        };

        $search($schema);

        $this->assertTrue($automationTabFound, 'Automation tab should exist.');
        $this->assertEquals(['Ticket Automation'], $sectionsOrder, 'Ticket Automation section should exist inside Automation tab.');
        $this->assertEquals([
            'default_close_delay_hours',
            'default_reopen_window_hours',
            'manual_ticket_auto_close_schedule_mode',
            'manual_ticket_extra_flapping_delay_hours',
            'manual_ticket_flap_threshold',
        ], array_values($fieldsOrder), 'Fields must be present in exact order.');

        $component->assertSee('Configure automatic closing, reopening, scheduling, and flapping behavior for manually created Znuny tickets.');
    }
}
