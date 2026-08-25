<?php

namespace Tests\Unit\Services\Znuny;

use App\Models\Setting;
use App\Services\Zabbix\ZabbixProblemFormatter;
use App\Services\Znuny\ZnunyTicketTextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZnunyTicketTextBuilderTest extends TestCase
{
    use RefreshDatabase;

    private ZnunyTicketTextBuilder $builder;

    private $fixedTime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new ZnunyTicketTextBuilder(new ZabbixProblemFormatter);
        $this->fixedTime = time();
    }

    public function test_builds_title_from_problem_name_only()
    {
        $result = $this->builder->build(['name' => 'High CPU load']);
        $this->assertEquals('High CPU load', $result['title']);
        $this->assertEquals('Zabbix problem details', $result['article_subject']);
    }

    public function test_builds_article_body_with_mandatory_fields()
    {
        $problem = [
            'name' => 'Disk space low',
            'severity' => '2',
            'clock' => $this->fixedTime - 3600,
            'hosts' => [
                [
                    'name' => 'Server B Display',
                    'host' => 'server_b',
                ],
            ],
        ];

        $result = $this->builder->build($problem);

        $expectedStartedAt = date('Y-m-d H:i:s', $this->fixedTime - 3600);
        $expectedBody = <<<TEXT
Problem: Disk space low
Display Name: Server B Display
Host Name: server_b
Severity: Warning
Started At: {$expectedStartedAt}
Current Age: 1h
TEXT;

        $this->assertStringContainsString($expectedBody, $result['article_body']);
        $this->assertStringContainsString('Created manually by Zabbix Znuny Integration.', $result['article_body']);
    }

    public function test_includes_ip_address_when_exists()
    {
        $problem = [
            'name' => 'Ping failure',
            'host_ip' => '198.51.100.5',
        ];

        $result = $this->builder->build($problem);

        $this->assertStringContainsString("Host Name: Unknown host\nIP Address: 198.51.100.5\nSeverity:", $result['article_body']);
    }

    public function test_omits_ip_address_when_missing_or_empty()
    {
        $problem1 = ['name' => 'Problem 1', 'host_ip' => null];
        $result1 = $this->builder->build($problem1);
        $this->assertStringNotContainsString('IP Address', $result1['article_body']);

        $problem2 = ['name' => 'Problem 2', 'host_ip' => ''];
        $result2 = $this->builder->build($problem2);
        $this->assertStringNotContainsString('IP Address', $result2['article_body']);
    }

    public function test_includes_operational_data_when_available()
    {
        $problem = [
            'name' => 'Problem',
            'opdata' => 'Load avg: 5.5',
        ];

        $result = $this->builder->build($problem);
        $this->assertStringContainsString("Operational Data: Load avg: 5.5\n\nCreated manually", $result['article_body']);
    }

    public function test_includes_tags_when_available()
    {
        $problem = [
            'name' => 'Problem',
            'tags' => [
                ['tag' => 'service', 'value' => 'web'],
                ['tag' => 'criticality', 'value' => 'high'],
                ['tag' => 'emptyval', 'value' => ''],
            ],
        ];

        $result = $this->builder->build($problem);
        $this->assertStringContainsString("Tags: service=web, criticality=high, emptyval\n\nCreated manually", $result['article_body']);
    }

    public function test_does_not_include_internal_fields()
    {
        $problem = [
            'name' => 'Problem',
            'eventid' => '1001',
            'objectid' => '2001',
            'r_eventid' => '1002',
        ];

        $result = $this->builder->build($problem);
        $this->assertStringNotContainsString('1001', $result['article_body']);
        $this->assertStringNotContainsString('2001', $result['article_body']);
        $this->assertStringNotContainsString('1002', $result['article_body']);
        $this->assertStringNotContainsString('Queue', $result['article_body']);
        $this->assertStringNotContainsString('Owner', $result['article_body']);
    }

    public function test_handles_missing_optional_fields_safely()
    {
        $result = $this->builder->build([]);

        $expectedBody = <<<'TEXT'
Problem: Unknown Problem
Display Name: Unknown host
Host Name: Unknown host
Severity: Not classified
Started At: N/A
Current Age: <1m

Created manually by Zabbix Znuny Integration.
TEXT;

        $this->assertEquals($expectedBody, $result['article_body']);
    }

    public function test_appends_custom_footer()
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_manual_ticket_footer'],
            ['value' => 'This is a custom footer for our org.', 'type' => 'string']
        );

        $result = $this->builder->build(['name' => 'Problem']);

        $this->assertStringContainsString('This is a custom footer for our org.', $result['article_body']);
        $this->assertStringNotContainsString('Created manually by Zabbix Znuny Integration.', $result['article_body']);
    }

    public function test_appends_no_footer_if_empty()
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_manual_ticket_footer'],
            ['value' => '', 'type' => 'string']
        );

        $result = $this->builder->build(['name' => 'Problem']);

        $this->assertStringNotContainsString('Created manually by Zabbix Znuny Integration.', $result['article_body']);
        $this->assertStringNotContainsString("\n\n\n", $result['article_body']); // Should not leave trailing spaces
    }

    public function test_appends_no_footer_if_whitespace_only()
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_manual_ticket_footer'],
            ['value' => '   ', 'type' => 'string']
        );

        $result = $this->builder->build(['name' => 'Problem']);

        $this->assertStringNotContainsString('Created manually by Zabbix Znuny Integration.', $result['article_body']);
    }
}
