<?php

namespace Tests\Unit\Services\Support;

use App\Models\Setting;
use App\Services\Support\DateTimeDisplayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DateTimeDisplayServiceTest extends TestCase
{
    use RefreshDatabase;

    private DateTimeDisplayService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DateTimeDisplayService::class);
    }

    public function test_it_uses_configured_timezone()
    {
        Setting::updateOrCreate(['key' => 'app_display_timezone'], ['value' => 'America/New_York']);
        $this->assertEquals('America/New_York', $this->service->timezone());
    }

    public function test_it_falls_back_to_utc_if_missing()
    {
        $service = new DateTimeDisplayService;
        $this->assertEquals('UTC', $service->timezone());
    }

    public function test_it_falls_back_to_utc_if_invalid_timezone()
    {
        Setting::updateOrCreate(['key' => 'app_display_timezone'], ['value' => 'Invalid/Timezone']);
        $this->assertEquals('UTC', $this->service->timezone());
    }

    public function test_it_formats_datetime()
    {
        Setting::updateOrCreate(['key' => 'app_display_timezone'], ['value' => 'Europe/Kyiv']);

        $utcTime = '2026-06-21 12:00:00'; // UTC
        // Kyiv is UTC+3 in June
        $expected = 'Jun 21, 2026 15:00:00';

        $this->assertEquals($expected, $this->service->formatDateTime(Carbon::parse($utcTime, 'UTC')));
    }

    public function test_it_formats_datetime_with_timezone()
    {
        Setting::updateOrCreate(['key' => 'app_display_timezone'], ['value' => 'Europe/Kyiv']);

        $utcTime = '2026-06-21 12:00:00'; // UTC
        $expected = 'Jun 21, 2026 15:00:00 Europe/Kyiv';

        $this->assertEquals($expected, $this->service->formatDateTimeWithTimezone(Carbon::parse($utcTime, 'UTC')));
    }

    public function test_it_handles_null_safely()
    {
        $this->assertNull($this->service->formatDateTime(null));
        $this->assertNull($this->service->formatDateTimeWithTimezone(null));
        $this->assertNull($this->service->diffForHumans(null));
    }
}
