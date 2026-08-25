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

    private ?string $originalTimezone = null;

    private ?string $originalLocale = null;

    protected function setUp(): void
    {
        parent::setUp();

        $setting = Setting::where('key', 'app_display_timezone')->first();
        $this->originalTimezone = $setting ? $setting->value : null;
        $this->originalLocale = app()->getLocale();

        $this->service = app(DateTimeDisplayService::class);
    }

    protected function tearDown(): void
    {
        if ($this->originalTimezone === null) {
            Setting::where('key', 'app_display_timezone')->first()?->delete();
        } else {
            Setting::updateOrCreate(['key' => 'app_display_timezone'], ['value' => $this->originalTimezone]);
        }

        app()->setLocale($this->originalLocale);

        parent::tearDown();
    }

    public function test_it_uses_configured_timezone()
    {
        Setting::updateOrCreate(['key' => 'app_display_timezone'], ['value' => 'America/New_York']);
        $this->assertEquals('America/New_York', $this->service->timezone());
    }

    public function test_it_falls_back_to_utc_if_missing()
    {
        Setting::where('key', 'app_display_timezone')->first()?->delete();
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
        $this->assertNull($this->service->formatLocalizedDateTime(null));
        $this->assertNull($this->service->diffForHumans(null));
    }

    public function test_it_formats_localized_datetime()
    {
        Setting::updateOrCreate(['key' => 'app_display_timezone'], ['value' => 'Europe/Kyiv']);

        $utcTime = '2026-06-21 12:00:00'; // UTC
        $expectedEn = '21 June 2026, 15:00:00';
        $expectedUk = '21 червня 2026, 15:00:00';

        $originalLocale = app()->getLocale();
        try {
            app()->setLocale('en');
            $this->assertEquals($expectedEn, $this->service->formatLocalizedDateTime(Carbon::parse($utcTime, 'UTC')));

            app()->setLocale('uk');
            $this->assertEquals($expectedUk, $this->service->formatLocalizedDateTime(Carbon::parse($utcTime, 'UTC')));
        } finally {
            app()->setLocale($originalLocale);
        }
    }
}
