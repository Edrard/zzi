<?php

namespace Tests\Feature\Znuny;

use App\Services\Znuny\ZnunyClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ZnunyClientApiTimeoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
        \App\Services\SettingsService::clearRequestCache();
        Cache::put('app_settings_all', [
            'znuny_api_url' => ['key' => 'znuny_api_url', 'value' => 'http://test', 'type' => 'string'],
            'znuny_username' => ['key' => 'znuny_username', 'value' => 'u', 'type' => 'string'],
            'znuny_password' => ['key' => 'znuny_password', 'value' => 'p', 'type' => 'string'],
            'znuny_api_timeout' => ['key' => 'znuny_api_timeout', 'value' => '20', 'type' => 'integer'],
            'znuny_api_verify_ssl' => ['key' => 'znuny_api_verify_ssl', 'value' => '1', 'type' => 'boolean'],
        ]);
    }

    private function mockPendingRequest()
    {
        $mock = Mockery::mock(\Illuminate\Http\Client\PendingRequest::class)->makePartial();
        $mock->shouldReceive('withOptions')->with(['verify' => true])->andReturnSelf();
        $mock->shouldReceive('acceptJson')->andReturnSelf();
        return $mock;
    }

    public function test_session_creation_applies_configured_timeout()
    {
        $mock = $this->mockPendingRequest();
        $mock->shouldReceive('post')->once()->andReturn(
            new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode(['SessionID' => 'test'])))
        );
        Http::shouldReceive('timeout')->with(20)->once()->andReturn($mock);

        $client = new ZnunyClient();
        $client->createSession();
    }

    public function test_authenticated_get_applies_timeout_to_session_and_endpoint()
    {
        $mock = $this->mockPendingRequest();
        $mock->shouldReceive('post')->once()->andReturn(
            new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode(['SessionID' => 'test'])))
        );
        $mock->shouldReceive('get')->once()->andReturn(
            new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode(['Agents' => []])))
        );

        Http::shouldReceive('timeout')->with(20)->twice()->andReturn($mock);

        $client = new ZnunyClient();
        $client->getAgents();
    }

    public function test_invalid_session_retry_applies_timeout_to_every_request()
    {
        $mock = $this->mockPendingRequest();

        $mock->shouldReceive('post')->twice()->andReturn(
            new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode(['SessionID' => 'test'])))
        );
        $mock->shouldReceive('get')->once()->andThrow(new \Exception('SessionID invalid'));
        $mock->shouldReceive('get')->once()->andReturn(
            new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode(['Agents' => []])))
        );

        // 4 requests total: original session, original get, retry session, retry get
        Http::shouldReceive('timeout')->with(20)->times(4)->andReturn($mock);

        $client = new ZnunyClient();
        $client->getAgents();
    }

    public function test_missing_timeout_uses_default_15()
    {
        \App\Services\SettingsService::clearRequestCache();
        Cache::put('app_settings_all', [
            'znuny_api_url' => ['key' => 'znuny_api_url', 'value' => 'http://test', 'type' => 'string'],
            'znuny_username' => ['key' => 'znuny_username', 'value' => 'u', 'type' => 'string'],
            'znuny_password' => ['key' => 'znuny_password', 'value' => 'p', 'type' => 'string'],
        ]);

        $mock = $this->mockPendingRequest();
        $mock->shouldReceive('post')->once()->andReturn(
            new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode(['SessionID' => 'test'])))
        );
        Http::shouldReceive('timeout')->with(15)->once()->andReturn($mock);

        $client = new ZnunyClient();
        $client->createSession();
    }

    public static function invalidTimeoutProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-5],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTimeoutProvider')]
    public function test_zero_or_negative_timeout_is_clamped_to_1(int $timeout)
    {
        \App\Services\SettingsService::clearRequestCache();
        Cache::put('app_settings_all', [
            'znuny_api_url' => ['key' => 'znuny_api_url', 'value' => 'http://test', 'type' => 'string'],
            'znuny_username' => ['key' => 'znuny_username', 'value' => 'u', 'type' => 'string'],
            'znuny_password' => ['key' => 'znuny_password', 'value' => 'p', 'type' => 'string'],
            'znuny_api_timeout' => ['key' => 'znuny_api_timeout', 'value' => (string) $timeout, 'type' => 'integer'],
        ]);

        $mock = $this->mockPendingRequest();
        $mock->shouldReceive('post')->once()->andReturn(
            new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode(['SessionID' => 'test'])))
        );
        Http::shouldReceive('timeout')->with(1)->once()->andReturn($mock);

        $client = new ZnunyClient();
        $client->createSession();
    }
}
