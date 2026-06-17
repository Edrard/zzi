<?php

namespace Tests\Unit\Services\Zabbix;

use App\Services\Zabbix\ZabbixClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ZabbixClientTest extends TestCase
{
    public function test_get_host_interfaces_returns_empty_when_no_host_ids()
    {
        /** @var ZabbixClient|MockObject $client */
        $client = $this->getMockBuilder(ZabbixClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request'])
            ->getMock();

        $client->expects($this->never())->method('request');

        $result = $client->getHostInterfaces([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_get_host_interfaces_makes_correct_api_call()
    {
        /** @var ZabbixClient|MockObject $client */
        $client = $this->getMockBuilder(ZabbixClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request'])
            ->getMock();

        $expectedResponse = [
            ['hostid' => '100', 'ip' => '192.168.1.10', 'main' => '1', 'type' => '1'],
        ];

        $client->expects($this->once())
            ->method('request')
            ->with('hostinterface.get', [
                'hostids' => ['100', '101'],
                'output' => ['hostid', 'ip', 'main', 'type'],
            ])
            ->willReturn($expectedResponse);

        $result = $client->getHostInterfaces([100, '101', 100]);

        $this->assertEquals($expectedResponse, $result);
    }
}
