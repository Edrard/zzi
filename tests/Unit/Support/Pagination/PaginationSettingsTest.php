<?php

namespace Tests\Unit\Support\Pagination;

use App\Models\Setting;
use App\Support\Pagination\PaginationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaginationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_correct_options_for_100()
    {
        Setting::updateOrCreate(['key' => 'pagination_per_page_base'], ['value' => '100', 'type' => 'integer']);

        $settings = new PaginationSettings;

        $this->assertEquals(100, $settings->basePerPage());
        $this->assertEquals(100, $settings->defaultPerPage());

        $options = $settings->perPageOptions();
        $this->assertEquals([50, 100, 200, 300], array_values($options));
    }

    public function test_generates_correct_options_for_75()
    {
        Setting::updateOrCreate(['key' => 'pagination_per_page_base'], ['value' => '75', 'type' => 'integer']);

        $settings = new PaginationSettings;

        $this->assertEquals(75, $settings->basePerPage());
        $this->assertEquals(75, $settings->defaultPerPage());

        $options = $settings->perPageOptions();
        $this->assertEquals([40, 75, 150, 225], array_values($options));
    }

    public function test_invalid_stored_value_falls_back_to_100()
    {
        $invalidValues = [
            '',
            'abc',
            '75.5',
            '75abc',
            '-20',
            '0',
            '10',
            null,
        ];

        foreach ($invalidValues as $value) {
            if ($value === null) {
                Setting::where('key', 'pagination_per_page_base')->delete();
            } else {
                Setting::updateOrCreate(['key' => 'pagination_per_page_base'], ['value' => $value, 'type' => 'string']);
            }

            $settings = new PaginationSettings;
            $this->assertEquals(100, $settings->basePerPage(), 'Failed asserting fallback for value: '.var_export($value, true));
            $this->assertEquals([50, 100, 200, 300], array_values($settings->perPageOptions()));
        }
    }

    public function test_missing_stored_value_falls_back_to_100()
    {
        // Not setting it
        $settings = new PaginationSettings;

        $this->assertEquals(100, $settings->basePerPage());
        $this->assertEquals([50, 100, 200, 300], array_values($settings->perPageOptions()));
    }

    public function test_normalize_per_page_accepts_valid_options_and_rejects_invalid()
    {
        Setting::updateOrCreate(['key' => 'pagination_per_page_base'], ['value' => '100', 'type' => 'integer']);

        $settings = new PaginationSettings;

        // Valid values
        $this->assertEquals(50, $settings->normalizePerPage(50));
        $this->assertEquals(100, $settings->normalizePerPage(100));
        $this->assertEquals(200, $settings->normalizePerPage(200));
        $this->assertEquals(300, $settings->normalizePerPage(300));
        $this->assertEquals(50, $settings->normalizePerPage('50')); // numeric string

        // Invalid fall back to N (100)
        $invalidInputs = [
            '50abc',
            '50.5',
            '-50',
            '0',
            0,
            '',
            null,
            25,
            99,
        ];

        foreach ($invalidInputs as $input) {
            $this->assertEquals(100, $settings->normalizePerPage($input), 'Failed asserting fallback for input: '.var_export($input, true));
        }
    }
}
