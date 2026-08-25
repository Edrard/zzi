<?php

namespace Tests\Unit\Services\Zabbix;

use App\Services\Zabbix\ZabbixProblemFormatter;
use PHPUnit\Framework\TestCase;

class ZabbixProblemFormatterTest extends TestCase
{
    private ZabbixProblemFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new ZabbixProblemFormatter;
    }

    public function test_format_age(): void
    {
        $this->assertEquals('<1m', $this->formatter->formatAge(30));
        $this->assertEquals('1m', $this->formatter->formatAge(60));
        $this->assertEquals('1h 5m', $this->formatter->formatAge(3900));
        $this->assertEquals('1d 2h', $this->formatter->formatAge(93600));
        $this->assertEquals('2d', $this->formatter->formatAge(172800));
    }

    public function test_get_severity_color(): void
    {
        $this->assertEquals('info', $this->formatter->getSeverityColor(1));
        $this->assertEquals('danger', $this->formatter->getSeverityColor(5));
        $this->assertEquals('gray', $this->formatter->getSeverityColor(99));
    }

    public function test_get_severity_fallback(): void
    {
        $this->assertEquals('Information', $this->formatter->getSeverityFallback(1));
        $this->assertEquals('Disaster', $this->formatter->getSeverityFallback(5));
        $this->assertEquals('Unknown', $this->formatter->getSeverityFallback(99));
    }

    public function test_get_problem_age_seconds(): void
    {
        $clock = time() - 100;
        $problem = ['clock' => $clock];
        $this->assertEquals(100, $this->formatter->getProblemAgeSeconds($problem));

        $problem = ['age_seconds' => 500];
        $this->assertEquals(500, $this->formatter->getProblemAgeSeconds($problem));
    }
}
