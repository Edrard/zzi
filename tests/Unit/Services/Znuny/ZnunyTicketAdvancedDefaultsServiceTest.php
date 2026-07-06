<?php

namespace Tests\Unit\Services\Znuny;

use App\Models\Setting;
use App\Services\Znuny\ZnunyTicketAdvancedDefaultsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZnunyTicketAdvancedDefaultsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_defaults_returns_configured_values()
    {
        Setting::updateOrCreate(['key' => 'znuny_ticket_default_priority'], ['value' => '4 high', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_default_state'], ['value' => 'open', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_default_lock'], ['value' => 'unlock', 'type' => 'string']);

        $service = app(ZnunyTicketAdvancedDefaultsService::class);
        $defaults = $service->getDefaults();

        $this->assertEquals('4 high', $defaults['priority']);
        $this->assertEquals('open', $defaults['state']);
        $this->assertEquals('unlock', $defaults['lock']);
    }

    public function test_get_defaults_falls_back_when_blank_or_invalid()
    {
        Setting::updateOrCreate(['key' => 'znuny_ticket_default_priority'], ['value' => '   ', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_default_state'], ['value' => '', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'znuny_ticket_default_lock'], ['value' => 'invalid_lock_state', 'type' => 'string']);

        $service = app(ZnunyTicketAdvancedDefaultsService::class);
        $defaults = $service->getDefaults();

        $this->assertEquals('3 normal', $defaults['priority']);
        $this->assertEquals('new', $defaults['state']);
        $this->assertEquals('lock', $defaults['lock']);
    }

    public function test_get_defaults_falls_back_when_missing()
    {
        $service = app(ZnunyTicketAdvancedDefaultsService::class);
        $defaults = $service->getDefaults();

        $this->assertEquals('3 normal', $defaults['priority']);
        $this->assertEquals('new', $defaults['state']);
        $this->assertEquals('lock', $defaults['lock']);
    }
}
