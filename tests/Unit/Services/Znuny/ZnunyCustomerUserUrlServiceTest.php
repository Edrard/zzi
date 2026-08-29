<?php

namespace Tests\Unit\Services\Znuny;

use Tests\TestCase;
use App\Services\Znuny\ZnunyCustomerUserUrlService;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ZnunyCustomerUserUrlServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ZnunyCustomerUserUrlService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ZnunyCustomerUserUrlService::class);
    }

    public function test_default_template_is_correct()
    {
        // Assert the default matches the expected format directly from the source of truth
        $defaults = collect(\App\Support\Settings\DefaultSettings::all());
        $setting = $defaults->firstWhere('key', 'znuny_customer_user_url_template');
        $this->assertEquals('https://znuny.example.com/index.pl?Action=AdminCustomerUser;Subaction=Change;ID={customer_user_login}', $setting['value']);
    }

    public function test_url_encoding_for_email_login()
    {
        $defaults = collect(\App\Support\Settings\DefaultSettings::all());
        Setting::create($defaults->firstWhere('key', 'znuny_customer_user_url_template'));
        \App\Services\SettingsService::clearAllCaches();

        $login = 'andrey.arkhipov@credit2u.com.ua';
        $url = $this->service->getEditUrl($login);

        $expected = 'https://znuny.example.com/index.pl?Action=AdminCustomerUser;Subaction=Change;ID=andrey.arkhipov%40credit2u.com.ua';
        $this->assertEquals($expected, $url);
    }

    public function test_url_building_for_non_email_login()
    {
        $defaults = collect(\App\Support\Settings\DefaultSettings::all());
        Setting::create($defaults->firstWhere('key', 'znuny_customer_user_url_template'));
        \App\Services\SettingsService::clearAllCaches();

        $login = 'AksenovaClients';
        $url = $this->service->getEditUrl($login);

        $expected = 'https://znuny.example.com/index.pl?Action=AdminCustomerUser;Subaction=Change;ID=AksenovaClients';
        $this->assertEquals($expected, $url);
    }

    public function test_returns_null_if_login_is_empty()
    {
        $this->assertNull($this->service->getEditUrl(''));
        $this->assertNull($this->service->getEditUrl(null));
    }

    public function test_returns_null_if_template_is_empty()
    {
        Setting::updateOrCreate(['key' => 'znuny_customer_user_url_template'], ['value' => '']);
        \App\Services\SettingsService::clearAllCaches();

        $this->assertNull($this->service->getEditUrl('AksenovaClients'));
    }

    public function test_returns_null_if_placeholder_absent()
    {
        Setting::updateOrCreate(['key' => 'znuny_customer_user_url_template'], ['value' => 'https://znuny.example.com/index.pl']);
        \App\Services\SettingsService::clearAllCaches();

        $this->assertNull($this->service->getEditUrl('AksenovaClients'));
    }

    public function test_whitespace_around_valid_template_is_trimmed()
    {
        Setting::updateOrCreate(['key' => 'znuny_customer_user_url_template'], ['value' => '   https://znuny.example.com/index.pl?Action=AdminCustomerUser;Subaction=Change;ID={customer_user_login}   ']);
        \App\Services\SettingsService::clearAllCaches();

        $url = $this->service->getEditUrl('AksenovaClients');
        $this->assertEquals('https://znuny.example.com/index.pl?Action=AdminCustomerUser;Subaction=Change;ID=AksenovaClients', $url);
    }

    public function test_https_is_accepted()
    {
        Setting::updateOrCreate(['key' => 'znuny_customer_user_url_template'], ['value' => 'https://example.com/{customer_user_login}']);
        \App\Services\SettingsService::clearAllCaches();

        $url = $this->service->getEditUrl('user1');
        $this->assertEquals('https://example.com/user1', $url);
    }

    public function test_http_is_accepted()
    {
        Setting::updateOrCreate(['key' => 'znuny_customer_user_url_template'], ['value' => 'http://example.com/{customer_user_login}']);
        \App\Services\SettingsService::clearAllCaches();

        $url = $this->service->getEditUrl('user1');
        $this->assertEquals('http://example.com/user1', $url);
    }

    public function test_javascript_scheme_is_rejected()
    {
        Setting::updateOrCreate(['key' => 'znuny_customer_user_url_template'], ['value' => 'javascript:alert(1){customer_user_login}']);
        \App\Services\SettingsService::clearAllCaches();

        $this->assertNull($this->service->getEditUrl('user1'));
    }

    public function test_ftp_scheme_is_rejected()
    {
        Setting::updateOrCreate(['key' => 'znuny_customer_user_url_template'], ['value' => 'ftp://example.com/{customer_user_login}']);
        \App\Services\SettingsService::clearAllCaches();

        $this->assertNull($this->service->getEditUrl('user1'));
    }
}
