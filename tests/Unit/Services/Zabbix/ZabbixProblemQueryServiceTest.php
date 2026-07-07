<?php

namespace Tests\Unit\Services\Zabbix;

use App\Services\Zabbix\ZabbixProblemCache;
use App\Services\Zabbix\ZabbixProblemFormatter;
use App\Services\Zabbix\ZabbixProblemQueryService;
use Mockery;
use PHPUnit\Framework\TestCase;

class ZabbixProblemQueryServiceTest extends TestCase
{
    private ZabbixProblemQueryService $service;

    private $cacheMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheMock = Mockery::mock(ZabbixProblemCache::class);
        $formatter = new ZabbixProblemFormatter;

        $this->service = new ZabbixProblemQueryService($this->cacheMock, $formatter);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function getSampleProblems(): array
    {
        return [
            [
                'eventid' => '1',
                'name' => 'High CPU load',
                'host_name' => 'Server A',
                'severity' => 4,
                'age_seconds' => 300,
            ],
            [
                'eventid' => '2',
                'name' => 'Disk space low',
                'host_name' => 'server b',
                'severity' => 2,
                'age_seconds' => 600,
            ],
            [
                'eventid' => '3',
                'name' => 'Network down',
                'host_name' => 'Switch 1',
                'severity' => 5,
                'age_seconds' => 150,
            ],
            [
                'eventid' => '4',
                'name' => 'Memory usage high',
                'host_name' => 'Server A',
                'severity' => 4,
                'age_seconds' => 150,
            ],
        ];
    }

    public function test_query_returns_all_when_no_search()
    {
        $this->cacheMock->shouldReceive('all')->once()->andReturn($this->getSampleProblems());

        $result = $this->service->query('', 'eventid', 'asc');

        $this->assertCount(4, $result['problems']);
        $this->assertEquals(4, $result['total_cached_count']);
    }

    public function test_query_filters_by_host_name()
    {
        $this->cacheMock->shouldReceive('all')->once()->andReturn($this->getSampleProblems());

        $result = $this->service->query('server A', 'eventid', 'asc');

        $this->assertCount(2, $result['problems']);
        $this->assertEquals(4, $result['total_cached_count']);

        $problems = array_values($result['problems']);
        $this->assertEquals('Server A', $problems[0]['host_name']);
        $this->assertEquals('Server A', $problems[1]['host_name']);
    }

    public function test_query_filters_by_problem_name()
    {
        $this->cacheMock->shouldReceive('all')->once()->andReturn($this->getSampleProblems());

        $result = $this->service->query('disk', 'eventid', 'asc');

        $this->assertCount(1, $result['problems']);
        $this->assertEquals(4, $result['total_cached_count']);

        $problems = array_values($result['problems']);
        $this->assertEquals('Disk space low', $problems[0]['name']);
    }

    public function test_query_search_is_case_insensitive()
    {
        $this->cacheMock->shouldReceive('all')->once()->andReturn($this->getSampleProblems());

        $result = $this->service->query('SERVER B', 'eventid', 'asc');

        $this->assertCount(1, $result['problems']);
        $this->assertEquals(4, $result['total_cached_count']);

        $problems = array_values($result['problems']);
        $this->assertEquals('server b', $problems[0]['host_name']);
    }

    public function test_sorting_by_severity_preserves_fallback()
    {
        $this->cacheMock->shouldReceive('all')->once()->andReturn($this->getSampleProblems());

        // severity asc
        $result = $this->service->query('', 'severity', 'asc');
        $problems = array_values($result['problems']);

        // Expected order for severity asc: 2, 4, 4, 5
        // Within 4, fallback is age desc
        $this->assertEquals(2, $problems[0]['severity']); // event 2
        $this->assertEquals(4, $problems[1]['severity']);
        $this->assertEquals(300, $problems[1]['age_seconds']); // older age first (age desc fallback)
        $this->assertEquals(4, $problems[2]['severity']);
        $this->assertEquals(150, $problems[2]['age_seconds']); // newer age second
        $this->assertEquals(5, $problems[3]['severity']); // event 3
    }

    public function test_sorting_by_host_preserves_fallback()
    {
        $this->cacheMock->shouldReceive('all')->once()->andReturn($this->getSampleProblems());

        // host asc
        $result = $this->service->query('', 'host', 'asc');
        $problems = array_values($result['problems']);

        // Expected order: Server A, Server A, server b, Switch 1
        // Within Server A, fallback is severity desc, then eventid asc
        $this->assertEquals('Server A', $problems[0]['host_name']);
        $this->assertEquals(4, $problems[0]['severity']);
        $this->assertEquals('1', $problems[0]['eventid']);

        $this->assertEquals('Server A', $problems[1]['host_name']);
        $this->assertEquals(4, $problems[1]['severity']);
        $this->assertEquals('4', $problems[1]['eventid']);

        $this->assertEquals('server b', $problems[2]['host_name']);
        $this->assertEquals('Switch 1', $problems[3]['host_name']);
    }

    public function test_sorting_by_age_preserves_fallback()
    {
        $this->cacheMock->shouldReceive('all')->once()->andReturn($this->getSampleProblems());

        // age asc
        $result = $this->service->query('', 'age', 'asc');
        $problems = array_values($result['problems']);

        // Expected order: 150 (sev 5), 150 (sev 4), 300 (sev 4), 600 (sev 2)
        // Within 150, fallback is sev desc
        $this->assertEquals(150, $problems[0]['age_seconds']);
        $this->assertEquals(5, $problems[0]['severity']);

        $this->assertEquals(150, $problems[1]['age_seconds']);
        $this->assertEquals(4, $problems[1]['severity']);

        $this->assertEquals(300, $problems[2]['age_seconds']);
        $this->assertEquals(600, $problems[3]['age_seconds']);
    }

    public function test_empty_cache_returns_empty_problems()
    {
        $this->cacheMock->shouldReceive('all')->once()->andReturn([]);

        $result = $this->service->query('', 'severity', 'asc');

        $this->assertCount(0, $result['problems']);
        $this->assertEquals(0, $result['total_cached_count']);
    }

    public function test_exact_duplicate_eventid_is_skipped()
    {
        $problems = [
            [
                'eventid' => '1',
                'name' => 'Test',
                'host_name' => 'Host',
                'hostid' => '100',
                'objectid' => '200',
            ],
            [
                'eventid' => '1',
                'name' => 'Test',
                'host_name' => 'Host',
                'hostid' => '100',
                'objectid' => '200',
            ],
        ];

        $this->cacheMock->shouldReceive('all')->once()->andReturn($problems);
        $result = $this->service->query('', 'eventid', 'asc');

        $this->assertCount(1, $result['problems']);
        $this->assertEquals(1, $result['problems'][0]['grouped_event_count']);
        $this->assertEquals(['1'], $result['problems'][0]['related_eventids']);
    }

    public function test_same_host_same_trigger_different_eventids_are_grouped()
    {
        $problems = [
            [
                'eventid' => '1',
                'name' => 'Test',
                'host_name' => 'Host',
                'hosts' => [['hostid' => '100']],
                'objectid' => '200',
                'severity' => 2,
                'age_seconds' => 100,
            ],
            [
                'eventid' => '2',
                'name' => 'Test',
                'host_name' => 'Host',
                'hosts' => [['hostid' => '100']],
                'objectid' => '200',
                'severity' => 4,
                'age_seconds' => 50,
            ],
        ];

        $this->cacheMock->shouldReceive('all')->once()->andReturn($problems);
        $result = $this->service->query('', 'eventid', 'asc');

        $this->assertCount(1, $result['problems']);
        $this->assertEquals(2, $result['problems'][0]['grouped_event_count']);
        $this->assertEquals(['1', '2'], $result['problems'][0]['related_eventids']);
        $this->assertEquals('2', $result['problems'][0]['eventid']); // Severity 4 won
    }

    public function test_same_problem_name_different_hosts_are_not_grouped()
    {
        $problems = [
            [
                'eventid' => '1',
                'name' => 'Test',
                'host_name' => 'Host A',
                'hosts' => [['hostid' => '100']],
                'objectid' => '200',
            ],
            [
                'eventid' => '2',
                'name' => 'Test',
                'host_name' => 'Host B',
                'hosts' => [['hostid' => '101']],
                'objectid' => '201',
            ],
        ];

        $this->cacheMock->shouldReceive('all')->once()->andReturn($problems);
        $result = $this->service->query('', 'eventid', 'asc');

        $this->assertCount(2, $result['problems']);
    }

    public function test_same_host_different_problems_are_not_grouped()
    {
        $problems = [
            [
                'eventid' => '1',
                'name' => 'Test A',
                'host_name' => 'Host',
                'hosts' => [['hostid' => '100']],
                'objectid' => '200',
            ],
            [
                'eventid' => '2',
                'name' => 'Test B',
                'host_name' => 'Host',
                'hosts' => [['hostid' => '100']],
                'objectid' => '201',
            ],
        ];

        $this->cacheMock->shouldReceive('all')->once()->andReturn($problems);
        $result = $this->service->query('', 'eventid', 'asc');

        $this->assertCount(2, $result['problems']);
    }
}
