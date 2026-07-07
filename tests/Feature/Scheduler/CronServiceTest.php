<?php

namespace Tests\Feature\Scheduler;

use App\Services\Cron\CronService;
use Carbon\Carbon;
use Tests\TestCase;

class CronServiceTest extends TestCase
{
    public function test_valid_cron_calculates_next_run_at()
    {
        $service = new CronService;
        $this->assertTrue($service->isValid('* * * * *'));

        $nextRun = $service->calculateNextRun('* * * * *', 'UTC');
        $this->assertInstanceOf(Carbon::class, $nextRun);
        $this->assertTrue($nextRun->isFuture());
    }

    public function test_invalid_cron_is_rejected()
    {
        $service = new CronService;
        $this->assertFalse($service->isValid('invalid cron'));
        $this->assertNull($service->calculateNextRun('invalid cron', 'UTC'));
    }

    public function test_invalid_6_field_cron_is_rejected()
    {
        $service = new CronService;
        // Standard cron has 5 fields. dragonmantank/cron-expression strictly requires 5 fields by default unless configured otherwise.
        $this->assertFalse($service->isValid('* * * * * *'));
        $this->assertNull($service->calculateNextRun('* * * * * *', 'UTC'));
    }
}
