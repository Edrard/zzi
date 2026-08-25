<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserLandingPageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class LandingPageRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_hitting_admin_redirects_to_resolved_default_landing_page()
    {
        $admin = User::factory()->create(['role' => 'admin', 'default_landing_page' => 'current-zabbix-problems']);

        $response = $this->actingAs($admin)->get('/admin');

        // It should redirect to Current Problems
        $response->assertRedirect(route('filament.admin.pages.current-zabbix-problems'));
    }

    public function test_authenticated_operator_hitting_admin_redirects_to_resolved_default_landing_page()
    {
        $operator = User::factory()->create(['role' => 'operator', 'default_landing_page' => 'create-ticket']);

        $response = $this->actingAs($operator)->get('/admin');

        $response->assertRedirect(route('filament.admin.pages.create-ticket'));
    }

    public function test_with_no_user_preference_set_fallback_is_current_problems()
    {
        $viewer = User::factory()->create(['role' => 'viewer']); // default is current-zabbix-problems from migration

        $response = $this->actingAs($viewer)->get('/admin');

        $response->assertRedirect(route('filament.admin.pages.current-zabbix-problems'));
    }

    public function test_if_default_landing_page_is_invalid_fallback_is_current_problems()
    {
        $admin = User::factory()->create(['role' => 'admin', 'default_landing_page' => 'some-invalid-page']);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertRedirect(route('filament.admin.pages.current-zabbix-problems'));
    }

    public function test_viewer_with_default_landing_page_create_ticket_falls_back_to_current_problems()
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'default_landing_page' => 'create-ticket']);

        $response = $this->actingAs($viewer)->get('/admin');

        $response->assertRedirect(route('filament.admin.pages.current-zabbix-problems'));
    }

    public function test_unknown_keys_in_env_are_ignored()
    {
        Config::set('app.available_landing_pages', 'current-zabbix-problems,invalid-url,unknown-slug');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $service = app(UserLandingPageService::class);
        $options = $service->availableOptionsForUser($admin);

        $this->assertArrayHasKey('current-zabbix-problems', $options);
        $this->assertArrayNotHasKey('invalid-url', $options);
        $this->assertArrayNotHasKey('unknown-slug', $options);
    }

    public function test_raw_urls_are_ignored()
    {
        Config::set('app.available_landing_pages', 'current-zabbix-problems,http://example.com,https://example.com,/admin/settings');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $service = app(UserLandingPageService::class);
        $options = $service->availableOptionsForUser($admin);

        $this->assertArrayHasKey('current-zabbix-problems', $options);
        $this->assertArrayNotHasKey('http://example.com', $options);
        $this->assertArrayNotHasKey('https://example.com', $options);
        $this->assertArrayNotHasKey('/admin/settings', $options);
    }

    public function test_service_falls_back_to_base_list_when_config_is_empty()
    {
        Config::set('app.available_landing_pages', '');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $service = app(UserLandingPageService::class);
        $options = $service->availableOptionsForUser($admin);

        $this->assertArrayHasKey('current-zabbix-problems', $options);
        $this->assertArrayHasKey('znuny-ticket-workspace', $options);
        $this->assertArrayHasKey('zabbix-tickets', $options);
        $this->assertArrayHasKey('create-ticket', $options);
    }

    public function test_discovered_page_not_listed_in_available_landing_pages_does_not_appear()
    {
        // my-settings is a registered page but not in the default config allow-list.
        Config::set('app.available_landing_pages', 'current-zabbix-problems');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $service = app(UserLandingPageService::class);
        $options = $service->availableOptionsForUser($admin);

        $this->assertArrayHasKey('current-zabbix-problems', $options);
        $this->assertArrayNotHasKey('my-settings', $options);
    }

    public function test_discovered_page_listed_in_available_landing_pages_appears()
    {
        // Add my-settings to the config allow-list.
        Config::set('app.available_landing_pages', 'current-zabbix-problems,my-settings');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $service = app(UserLandingPageService::class);
        $options = $service->availableOptionsForUser($admin);

        $this->assertArrayHasKey('current-zabbix-problems', $options);
        $this->assertArrayHasKey('my-settings', $options);
    }
}
