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
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function testConnectionWithCredentials(string $url, string $token, int $timeout, bool $verifySsl): array
    {
        $oldUrl = $this->url;
        $oldToken = $this->token;
        $oldTimeout = $this->timeout;
        $oldVerifySsl = $this->verifySsl;

        try {
            $this->url = $url;
            $this->token = $token;
            $this->timeout = $timeout;
            $this->verifySsl = $verifySsl;

            return $this->testConnection();
        } finally {
            $this->url = $oldUrl;
            $this->token = $oldToken;
            $this->timeout = $oldTimeout;
            $this->verifySsl = $oldVerifySsl;
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<int, mixed>
     *
     * @throws Exception
     */
    public function getProblems(array $params = []): array
    {
        $excludeSuppressed = SettingsService::bool('zabbix_exclude_suppressed_problems', true);

        $defaultParams = [
            'recent' => true,
            'output' => 'extend',
            'selectAcknowledges' => 'extend',
            'selectTags' => 'extend',
            'limit' => 20,
            'sortfield' => 'eventid',
            'sortorder' => 'DESC',
        ];

        if ($excludeSuppressed) {
            $defaultParams['suppressed'] = false;
        }

        $result = $this->request('problem.get', array_merge($defaultParams, $params));

        return is_array($result) ? $result : [];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<int, mixed>
     *
     * @throws Exception
     */
    public function getProblemsForPolling(array $params = []): array
    {
        $defaultParams = [
            'recent' => true,
            'output' => 'extend',
            'selectAcknowledges' => 'extend',
            'selectTags' => 'extend',
            'selectSuppressionData' => 'extend', // useful if not already returned
            'limit' => 20,
            'sortfield' => 'eventid',
            'sortorder' => 'DESC',
        ];

        $result = $this->request('problem.get', array_merge($defaultParams, $params));

        return is_array($result) ? $result : [];
    }

    /**
     * @param  array<int, string|int>  $eventIds
     * @return array<string, array<int, mixed>>
     *
     * @throws Exception
     */
    public function getEventHosts(array $eventIds): array
    {
        if (empty($eventIds)) {
            return [];
        }

        $result = $this->request('event.get', [
            'eventids' => array_values(array_unique($eventIds)),
            'selectHosts' => ['hostid', 'host', 'name', 'status'],
            'output' => ['eventid'],
        ]);

        if (! is_array($result)) {
            return [];
        }

        $map = [];
        foreach ($result as $event) {
            if (isset($event['eventid'])) {
                $eventId = (string) $event['eventid'];
                $map[$eventId] = isset($event['hosts']) && is_array($event['hosts']) ? $event['hosts'] : [];
            }
        }

        return $map;
    }

    /**
     * @param  array<int, string|int>  $triggerIds
     * @return array<string, array<string, mixed>>
     *
     * @throws Exception
     */
    public function getTriggersForProblems(array $triggerIds): array
    {
        if (empty($triggerIds)) {
            return [];
        }

        $result = $this->request('trigger.get', [
            'triggerids' => array_values(array_unique($triggerIds)),
            'output' => ['triggerid', 'description', 'status', 'priority'],
            'selectItems' => ['itemid', 'name', 'key_', 'status'],
            'selectDependencies' => ['triggerid', 'description', 'status'],
        ]);

        if (! is_array($result)) {
            return [];
        }

        $map = [];
        foreach ($result as $trigger) {
            if (isset($trigger['triggerid'])) {
                $triggerId = (string) $trigger['triggerid'];
                $map[$triggerId] = [
                    'triggerid' => $triggerId,
                    'description' => $trigger['description'] ?? null,
                    'status' => $trigger['status'] ?? null,
                    'priority' => $trigger['priority'] ?? null,
                    'items' => isset($trigger['items']) && is_array($trigger['items']) ? $trigger['items'] : [],
                    'dependencies' => isset($trigger['dependencies']) && is_array($trigger['dependencies']) ? $trigger['dependencies'] : [],
                ];
            }
        }

        return $map;
    }

    /**
     * @param  array<int, string|int>  $hostIds
     * @return array<int, array<string, mixed>>
     *
     * @throws Exception
     */
    public function getHostInterfaces(array $hostIds): array
    {
        if (empty($hostIds)) {
            return [];
        }

        $result = $this->request('hostinterface.get', [
            'hostids' => array_values(array_unique($hostIds)),
            'output' => ['hostid', 'ip', 'main', 'type'],
        ]);

        return is_array($result) ? $result : [];
    }
}
