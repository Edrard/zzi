<?php

namespace Tests\Unit\Support\Polling;

use App\Support\Polling\UiPollInterval;
use Tests\TestCase;

class UiPollIntervalTest extends TestCase
{
    public function test_default_is_60_seconds()
    {
        config(['app.ui_poll_interval_seconds' => null]);
        // Also ensure env is clear? PHPUnit env might persist. We can mock config.
        $this->assertEquals(60, UiPollInterval::getSeconds());
        $this->assertEquals('60s', UiPollInterval::getLivewireString());
    }

    public function test_invalid_config_falls_back_to_60()
    {
        config(['app.ui_poll_interval_seconds' => 'invalid']);
        $this->assertEquals(60, UiPollInterval::getSeconds());

        config(['app.ui_poll_interval_seconds' => -10]);
        $this->assertEquals(60, UiPollInterval::getSeconds());

        config(['app.ui_poll_interval_seconds' => 0]);
        $this->assertEquals(60, UiPollInterval::getSeconds());
    }

    public function test_valid_config_returns_value()
    {
        config(['app.ui_poll_interval_seconds' => 120]);
        $this->assertEquals(120, UiPollInterval::getSeconds());
        $this->assertEquals('120s', UiPollInterval::getLivewireString());

        config(['app.ui_poll_interval_seconds' => '30']);
        $this->assertEquals(30, UiPollInterval::getSeconds());
        $this->assertEquals('30s', UiPollInterval::getLivewireString());
    }
}
