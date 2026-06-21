<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Services\Znuny\ZnunyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsLivewireTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_save_normalizes_znuny_queue_host_mappings()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Create the settings needed to pass validation
        Setting::updateOrCreate(['key' => 'zabbix_api_url'], ['type' => 'string', 'value' => 'http://example.com']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['type' => 'string', 'value' => 'user']);
        Setting::updateOrCreate(['key' => 'znuny_queue_host_mappings'], ['type' => 'json', 'value' => json_encode([])]);

        $mockClient = \Mockery::mock(ZnunyClient::class);
        $mockClient->shouldReceive('getQueues')->andReturn([
            ['id' => 1, 'name' => 'Queue1', 'full_name' => 'Queue1'],
            ['id' => 2, 'name' => 'Queue2', 'full_name' => 'Queue2'],
        ]);
        $this->app->instance(ZnunyClient::class, $mockClient);

        $rawMappings = [
            'item-1' => ['host_prefix' => '  Prefix1  ', 'queue_name' => 'Queue1', 'note' => '  Note  '],
            'item-2' => ['host_prefix' => '', 'queue_name' => 'Queue2', 'note' => ''], // empty prefix dropped
            'item-3' => ['host_prefix' => 'Prefix3', 'queue_name' => '', 'note' => ''], // empty queue dropped
        ];

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm([
                'zabbix_api_url' => 'http://example.com',
                'znuny_username' => 'user',
                'znuny_queue_host_mappings' => $rawMappings,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $setting = Setting::where('key', 'znuny_queue_host_mappings')->first();
        $this->assertNotNull($setting);
        $val = json_decode($setting->value, true);

        $this->assertCount(1, $val);
        $this->assertEquals('Prefix1', $val[0]['host_prefix']);
        $this->assertEquals('Queue1', $val[0]['queue_name']);
        $this->assertEquals('Note', $val[0]['note']);
    }

    public function test_manual_ticket_auto_close_schedule_mode_is_in_automation_tab_and_no_other_tab()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)->test(Settings::class);

        $form = $component->instance()->getForm('form');
        $schema = $form->getComponents();

        // Recursively search for components
        $foundMode = false;
        $foundEnabled = false;
        $foundOtherTab = false;
        $automationTabModeFound = false;

        $search = function ($components, $parentGroupName = null) use (&$search, &$foundMode, &$foundEnabled, &$foundOtherTab, &$automationTabModeFound) {
            foreach ($components as $c) {
                $type = class_basename($c);
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                $label = method_exists($c, 'getLabel') ? $c->getLabel() : null;

                if ($type === 'Tab' && $label === 'Other') {
                    $foundOtherTab = true;
                }

                if ($type === 'Tab' && $label === 'Manual') {
                    $parentGroupName = 'Manual';
                }

                if ($name === 'manual_ticket_auto_close_enabled') {
                    $foundEnabled = true;
                }

                if ($name === 'manual_ticket_auto_close_schedule_mode') {
                    $foundMode = true;
                    if ($parentGroupName === 'Manual') {
                        $automationTabModeFound = true;
                    }
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents(), $parentGroupName);
                }
            }
        };

        $search($schema);

        $this->assertTrue($foundMode, 'manual_ticket_auto_close_schedule_mode should be rendered');
        $this->assertFalse($foundEnabled, 'manual_ticket_auto_close_enabled should not be rendered');
        $this->assertFalse($foundOtherTab, 'Other tab should not be rendered when empty');
        $this->assertTrue($automationTabModeFound, 'manual_ticket_auto_close_schedule_mode should be in Automation -> Manual tab');
    }

    public function test_app_display_timezone_is_in_general_tab()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $component = Livewire::actingAs($admin)->test(Settings::class);

        $form = $component->instance()->getForm('form');
        $schema = $form->getComponents();

        $foundTimezone = false;
        $generalTabTimezoneFound = false;
        $foundOtherTab = false;

        $search = function ($components, $parentGroupName = null) use (&$search, &$foundTimezone, &$generalTabTimezoneFound, &$foundOtherTab) {
            foreach ($components as $c) {
                $type = class_basename($c);
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                $label = method_exists($c, 'getLabel') ? $c->getLabel() : null;

                if ($type === 'Tab' && $label === 'Other') {
                    $foundOtherTab = true;
                }

                if ($type === 'Tab' && $label === 'General') {
                    $parentGroupName = 'General';
                }

                if ($name === 'app_display_timezone') {
                    $foundTimezone = true;
                    if ($parentGroupName === 'General') {
                        $generalTabTimezoneFound = true;
                    }
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents(), $parentGroupName);
                }
            }
        };

        $search($schema);

        $this->assertTrue($foundTimezone, 'app_display_timezone should be rendered');
        $this->assertTrue($generalTabTimezoneFound, 'app_display_timezone should be in General tab');
        $this->assertFalse($foundOtherTab, 'Other tab should not be rendered when empty');
    }
}
