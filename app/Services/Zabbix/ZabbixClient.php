<?php

namespace App\Services\Zabbix;

use App\Services\SettingsService;
use Exception;
use Illuminate\Support\Facades\Http;

class ZabbixClient
{
    protected string $url;

    protected string $token;

    protected int $timeout;

    protected bool $verifySsl;

    public function __construct()
    {
        $this->url = SettingsService::string('zabbix_api_url', '');
        $this->token = SettingsService::string('zabbix_api_token', '');
        $this->timeout = SettingsService::int('zabbix_api_timeout', 15) ?? 15;
        $this->verifySsl = SettingsService::bool('zabbix_api_verify_ssl', true) ?? true;
    }

    /**
     * @param  array<string, mixed>  $params
     *
     * @throws Exception
     */
    public function request(string $method, array $params = []): mixed
    {
        if (empty($this->url)) {
            throw new Exception('Zabbix API URL is not configured.');
        }

        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => random_int(1, 10000),
        ];

        $headers = [
            'Content-Type' => 'application/json-rpc',
        ];

        if ($method !== 'apiinfo.version') {
            if (empty($this->token)) {
                throw new Exception('Zabbix API Token is not configured.');
            }
            $headers['Authorization'] = 'Bearer '.$this->token;
        }

        $request = Http::timeout($this->timeout)->withHeaders($headers);

        if (! $this->verifySsl) {
            $request->withoutVerifying();
        }

        $response = $request->post($this->url, $payload);

        if (! $response->successful()) {
            throw new Exception('Zabbix API HTTP request failed: '.$response->status());
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new Exception('Invalid response format from Zabbix API.');
        }

        if (isset($data['error'])) {
            $errorMessage = $data['error']['data'] ?? $data['error']['message'] ?? 'Unknown error';
            throw new Exception('Zabbix API returned an error: '.$errorMessage);
        }

        if (! array_key_exists('result', $data)) {
            throw new Exception('Invalid response format: missing result field.');
        }

        return $data['result'];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function testConnection(): array
    {
        // Unauthenticated call to check URL and connectivity
        $version = $this->request('apiinfo.version', []);

        // Authenticated call to verify the token
        $this->getProblems(['limit' => 1]);

        return [
            'status' => 'success',
            'version' => $version,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<int, mixed>
     *
     * @throws Exception
     */
    public function getProblems(array $params = []): array
    {
        $defaultParams = [
            'recent' => true,
            'output' => 'extend',
            'selectAcknowledges' => 'extend',
            'selectTags' => 'extend',
            'limit' => 20,
            'sortfield' => 'eventid',
            'sortorder' => 'DESC',
        ];

        $result = $this->request('problem.get', array_merge($defaultParams, $params));

        return is_array($result) ? $result : [];
    }
}
