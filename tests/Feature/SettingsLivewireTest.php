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
}
