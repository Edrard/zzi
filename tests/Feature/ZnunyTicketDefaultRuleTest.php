<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Services\Znuny\ZnunyTicketDefaultRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ZnunyTicketDefaultRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::insertOrIgnore([
            ['key' => 'znuny_queue_from_host_regex', 'value' => '^(?<queue>[^\s]+)', 'type' => 'string', 'description' => '...'],
            ['key' => 'znuny_customer_user_from_queue_template', 'value' => '<queue>Clients', 'type' => 'string', 'description' => '...'],
        ]);
    }

    public function test_default_regex_detects_first_word_as_queue()
    {
        $service = new ZnunyTicketDefaultRuleService;
        $this->assertEquals('TestCompany', $service->detectQueueFromHost('TestCompany swiss test01'));
        $this->assertEquals('ExampleCompany', $service->detectQueueFromHost('ExampleCompany swiss ipmi01'));
    }

    public function test_default_template_builds_customer_user()
    {
        $service = new ZnunyTicketDefaultRuleService;
        $this->assertEquals('TestCompanyClients', $service->customerUserFromQueue('TestCompany'));
    }

    public function test_resolve_candidates_for_valid_host()
    {
        $service = new ZnunyTicketDefaultRuleService;
        $result = $service->resolveCandidates('TestCompany swiss test01');

        $this->assertEquals([
            'host_name' => 'TestCompany swiss test01',
            'queue' => 'TestCompany',
            'customer_user' => 'TestCompanyClients',
            'warnings' => [],
        ], $result);
    }

    public function test_resolve_candidates_for_no_match_host()
    {
        Setting::where('key', 'znuny_queue_from_host_regex')->update(['value' => '^(?<queue>[0-9]+)$']); // Force to expect numbers
        $service = new ZnunyTicketDefaultRuleService;
        $result = $service->resolveCandidates('TestCompany swiss test01');

        $this->assertEquals([
            'host_name' => 'TestCompany swiss test01',
            'queue' => null,
            'customer_user' => null,
            'warnings' => ['Queue could not be detected from host name.'],
        ], $result);
    }

    public function test_invalid_regex_handled_gracefully()
    {
        Setting::where('key', 'znuny_queue_from_host_regex')->update(['value' => '^(?<queue>[unclosed']);
        $service = new ZnunyTicketDefaultRuleService;
        $result = $service->resolveCandidates('TestCompany swiss test01');

        $this->assertEquals([
            'host_name' => 'TestCompany swiss test01',
            'queue' => null,
            'customer_user' => null,
            'warnings' => ['Queue could not be detected from host name.'],
        ], $result);
    }

    public function test_settings_validation_rejects_invalid_regex()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm([
                'znuny_queue_from_host_regex' => '^(?<notqueue>[^\s]+)', // Missing named group <queue>
            ])
            ->call('save')
            ->assertHasFormErrors(['znuny_queue_from_host_regex']);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm([
                'znuny_queue_from_host_regex' => '^(?<queue>[unclosed', // Invalid regex syntax
            ])
            ->call('save')
            ->assertHasFormErrors(['znuny_queue_from_host_regex']);
    }

    public function test_settings_validation_rejects_invalid_template()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm([
                'znuny_customer_user_from_queue_template' => 'MissingPlaceholder',
            ])
            ->call('save')
            ->assertHasFormErrors(['znuny_customer_user_from_queue_template']);
    }
}
