<?php

namespace Tests\Feature\Services\Znuny;

use App\Models\Setting;
use App\Services\Znuny\ZnunyUiFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ZnunyUiFilterServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_exclude_trims_and_is_case_insensitive()
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_agent_exclude_logins'],
            ['value' => "  Agent.ONE  \n\n\tuser.two\n", 'type' => 'string']
        );

        $service = new ZnunyUiFilterService;

        $this->assertTrue($service->isAgentLoginExcluded('agent.one'));
        $this->assertTrue($service->isAgentLoginExcluded('AGENT.ONE'));
        $this->assertTrue($service->isAgentLoginExcluded('user.two'));
        $this->assertFalse($service->isAgentLoginExcluded('user.three'));
    }

    public function test_queue_regex_supports_string_list_format()
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_global_queue_exclusion_regexes'],
            ['value' => json_encode([
                'Junk.*',
                'Archive.*',
            ]), 'type' => 'json']
        );

        $service = new ZnunyUiFilterService;

        $this->assertTrue($service->isQueueExcluded('JunkQueue'));
        $this->assertTrue($service->isQueueExcluded('Archive2023'));
        $this->assertFalse($service->isQueueExcluded('Support'));
    }

    public function test_queue_regex_supports_object_format()
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_global_queue_exclusion_regexes'],
            ['value' => json_encode([
                ['regex' => 'Junk.*'],
                ['regex' => 'Archive.*'],
            ]), 'type' => 'json']
        );

        $service = new ZnunyUiFilterService;

        $this->assertTrue($service->isQueueExcluded('JunkQueue'));
        $this->assertTrue($service->isQueueExcluded('Archive2023'));
        $this->assertFalse($service->isQueueExcluded('Support'));
    }

    public function test_blank_regex_entries_are_ignored()
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_global_queue_exclusion_regexes'],
            ['value' => json_encode([
                ['regex' => 'Junk.*'],
                ['regex' => '   '],
                '',
                null,
            ]), 'type' => 'json']
        );

        $service = new ZnunyUiFilterService;

        // Valid ones should work, invalid/blank should not crash
        $this->assertTrue($service->isQueueExcluded('JunkQueue'));
        $this->assertFalse($service->isQueueExcluded(''));
        $this->assertFalse($service->isQueueExcluded('Support'));

        $this->assertCount(1, $service->getQueueExclusionRegexes());
    }

    public function test_invalid_regex_does_not_crash_and_is_logged()
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_global_queue_exclusion_regexes'],
            ['value' => json_encode([
                'Junk.*',
                '*(invalid',
            ]), 'type' => 'json']
        );

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, '*(invalid');
            });

        $service = new ZnunyUiFilterService;

        $this->assertTrue($service->isQueueExcluded('JunkQueue'));

        $regexes = $service->getQueueExclusionRegexes();
        $this->assertCount(1, $regexes);
    }

    public function test_filter_queues_filters_by_key_in_option_array()
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_global_queue_exclusion_regexes'],
            ['value' => json_encode([
                'Junk.*',
            ]), 'type' => 'json']
        );

        $service = new ZnunyUiFilterService;

        $options = [
            'JunkQueue1' => 'Junk Queue Label',
            'Support' => 'Support Label',
        ];

        $filtered = $service->filterQueuesForUi($options);

        $this->assertArrayNotHasKey('JunkQueue1', $filtered);
        $this->assertArrayHasKey('Support', $filtered);
    }

    public function test_filter_queues_filters_by_label_in_option_array()
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_global_queue_exclusion_regexes'],
            ['value' => json_encode([
                'Junk.*',
            ]), 'type' => 'json']
        );

        $service = new ZnunyUiFilterService;

        $options = [
            'QueueA' => 'Junk Queue Label',
            'QueueB' => 'Support Label',
        ];

        $filtered = $service->filterQueuesForUi($options);

        $this->assertArrayNotHasKey('QueueA', $filtered);
        $this->assertArrayHasKey('QueueB', $filtered);
    }
}
