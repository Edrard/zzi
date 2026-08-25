<?php

namespace Tests\Feature\Scheduler;

use App\Support\Settings\DefaultSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledTaskSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_defaults_exist()
    {
        $defaults = collect(DefaultSettings::all())->pluck('value', 'key');

        $this->assertEquals('180', $defaults['scheduled_task_logs_retention_days']);
        $this->assertEquals('30', $defaults['scheduled_tasks_missed_run_max_age_days']);
    }
}
