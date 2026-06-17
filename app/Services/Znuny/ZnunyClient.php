<?php

namespace App\Services\Znuny;

use App\Services\SettingsService;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class ZnunyClient
{
    private ?string $cachedSessionId = null;

    /**
     * Get the base API URL (rtrimmed).
     */
    protected function apiUrl(): string
    {
        $url = SettingsService::string('znuny_api_url', '');
        if (empty($url)) {
            throw new Exception('Znuny API URL is not configured.');
        }

        return rtrim($url, '/');
    }

    /**
     * Get the web URL (rtrimmed).
     */
    protected function webUrl(): string
    {
        $url = SettingsService::string('znuny_web_url', '');

        return rtrim($url, '/');
    }

    /**
     * Retrieve the ticket URL template.
     */
    protected function ticketUrlTemplate(): string
    {
        return SettingsService::string('znuny_ticket_url_template', '');
    }

    /**
     * Handle raw HTTP requests using Laravel Http facade with timeout/ssl defaults.
     */
    protected function request(): PendingRequest
    {
        $timeout = SettingsService::int('znuny_api_timeout', 15) ?? 15;
        $verifySsl = SettingsService::bool('znuny_api_verify_ssl', true) ?? true;

        return Http::timeout($timeout)
            ->withOptions(['verify' => $verifySsl])
            ->acceptJson();
    }

    /**
     * Sanitize an exception message to avoid exposing sensitive details.
     */
    protected function sanitizeExceptionMessage(string $message): string
    {
        $password = SettingsService::string('znuny_password', '');
        if (! empty($password)) {
            $message = str_replace($password, '[redacted]', $message);
        }

        if ($this->cachedSessionId) {
            $message = str_replace($this->cachedSessionId, '[redacted_session]', $message);
        }

        $message = preg_replace('/SessionID=[^&\s]+/', 'SessionID=[redacted_session]', $message);
        $message = preg_replace('/"SessionID":"[^"]+"/', '"SessionID":"[redacted_session]"', $message);
        $message = preg_replace('/Password=[^&\s]+/', 'Password=[redacted]', $message);
        $message = preg_replace('/"Password":"[^"]+"/', '"Password":"[redacted]"', $message);

        return $message;
    }

    /**
     * Process a JSON payload safely and throw standard Znuny error blocks.
     */
    protected function processResponse(Response $response): array
    {
        if (! $response->successful()) {
            throw new Exception("HTTP request failed with status {$response->status()}");
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new Exception('Invalid JSON response from Znuny API.');
        }

        // Handle Znuny logical Error payloads returning 200 OK
        if (isset($data['Error'])) {
            $errorCode = $data['Error']['ErrorCode'] ?? 'UnknownErrorCode';
            $errorMsg = $data['Error']['ErrorMessage'] ?? 'Unknown ErrorMessage';
            throw new Exception("Znuny API Error: [$errorCode] $errorMsg");
        }

        return $data;
    }

    /**
     * Validate and normalize a TicketID.
     */
    protected function normalizeTicketId(int|string $ticketId): int
    {
        $ticketIdStr = (string) $ticketId;
        if (! preg_match('/^[1-9]\d*$/', $ticketIdStr)) {
            throw new Exception('Invalid TicketID provided.');
        }

        return (int) $ticketIdStr;
    }

    /**
     * Check if exception message represents an invalid session error.
     */
    protected function isInvalidSessionError(Throwable $exception): bool
    {
        $msg = strtolower($exception->getMessage());

        return str_contains($msg, 'sessionidinvalid') ||
               str_contains($msg, 'sessionid invalid') ||
               str_contains($msg, 'session invalid') ||
               str_contains($msg, 'invalid sessionid');
    }

    /**
     * Create a session via POST /Session
     */
    public function createSession(): string
    {
        $username = SettingsService::string('znuny_username', '');
        $password = SettingsService::string('znuny_password', '');

        if (empty($username) || empty($password)) {
            throw new Exception('Znuny username or password is not configured.');
        }

        try {
            $response = $this->request()->post($this->apiUrl().'/Session', [
                'UserLogin' => $username,
                'Password' => $password,
            ]);

            $data = $this->processResponse($response);

            if (empty($data['SessionID'])) {
                throw new Exception('Znuny returned an empty SessionID.');
            }

            $this->cachedSessionId = $data['SessionID'];

            return $this->cachedSessionId;

        } catch (Throwable $e) {
            throw new Exception($this->sanitizeExceptionMessage($e->getMessage()));
        }
    }

    /**
     * Helper to retrieve or create a SessionID.
     */
    protected function sessionId(): string
    {
        if ($this->cachedSessionId) {
            return $this->cachedSessionId;
        }

        return $this->createSession();
    }

    /**
     * Get ticket by ID
     */
    public function getTicket(int|string $ticketId, bool $isRetry = false): array
    {
        $normalizedId = $this->normalizeTicketId($ticketId);

        try {
            $session = $this->sessionId();
            $response = $this->request()->get($this->apiUrl()."/Ticket/{$normalizedId}", [
                'SessionID' => $session,
            ]);

            $data = $this->processResponse($response);

            if (empty($data['Ticket']) || ! is_array($data['Ticket'])) {
                throw new Exception("No ticket returned for TicketID {$normalizedId}.");
            }

            $ticket = $data['Ticket'][0] ?? null;
            if (! $ticket) {
                throw new Exception("Empty ticket array returned for TicketID {$normalizedId}.");
            }

            return [
                'TicketID' => $ticket['TicketID'] ?? null,
                'TicketNumber' => $ticket['TicketNumber'] ?? null,
                'Title' => $ticket['Title'] ?? null,
                'QueueID' => $ticket['QueueID'] ?? null,
                'Queue' => $ticket['Queue'] ?? null,
                'OwnerID' => $ticket['OwnerID'] ?? null,
                'Owner' => $ticket['Owner'] ?? null,
                'ResponsibleID' => $ticket['ResponsibleID'] ?? null,
                'Responsible' => $ticket['Responsible'] ?? null,
                'StateID' => $ticket['StateID'] ?? null,
                'State' => $ticket['State'] ?? null,
                'StateType' => $ticket['StateType'] ?? null,
                'PriorityID' => $ticket['PriorityID'] ?? null,
                'Priority' => $ticket['Priority'] ?? null,
                'CustomerID' => $ticket['CustomerID'] ?? null,
                'CustomerUserID' => $ticket['CustomerUserID'] ?? null,
                'Created' => $ticket['Created'] ?? null,
                'Changed' => $ticket['Changed'] ?? null,
            ];

        } catch (Throwable $e) {
            if (! $isRetry && $this->isInvalidSessionError($e)) {
                $this->cachedSessionId = null;

                return $this->getTicket($normalizedId, true);
            }

            throw new Exception($this->sanitizeExceptionMessage($e->getMessage()));
        }
    }

    /**
     * Get list of agents from Znuny Agent endpoint.
     */
    public function getAgents(bool $isRetry = false): array
    {
        try {
            $session = $this->sessionId();
            $response = $this->request()->get($this->apiUrl().'/Agent', [
                'SessionID' => $session,
            ]);

            $data = $this->processResponse($response);

            if (! isset($data['Agents']) || ! is_array($data['Agents'])) {
                throw new Exception('Invalid Agents list returned from Znuny API.');
            }

            $normalized = [];
            foreach ($data['Agents'] as $agent) {
                if (! isset($agent['UserID']) || ! isset($agent['UserLogin'])) {
                    continue;
                }

                $userIdStr = (string) $agent['UserID'];
                if (! preg_match('/^[1-9]\d*$/', $userIdStr)) {
                    continue; // Skip invalid UserID safely
                }

                $id = (int) $userIdStr;
                $login = trim((string) $agent['UserLogin']);

                if ($login === '') {
                    continue; // Skip invalid UserLogin safely
                }

                $fullname = isset($agent['UserFullname']) ? trim((string) $agent['UserFullname']) : null;
                if ($fullname === '') {
                    $fullname = null;
                }

                $label = $fullname ? "{$fullname} <{$login}>" : $login;

                $normalized[] = [
                    'id' => $id,
                    'login' => $login,
                    'name' => $fullname,
                    'label' => $label,
                ];
            }

            usort($normalized, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

            return $normalized;

        } catch (Throwable $e) {
            if (! $isRetry && $this->isInvalidSessionError($e)) {
                $this->cachedSessionId = null;

                return $this->getAgents(true);
            }

            throw new Exception($this->sanitizeExceptionMessage($e->getMessage()));
        }
    }

    /**
     * Search tickets through TicketSearch operation
     */
    public function searchTickets(array $filters = [], bool $isRetry = false): array
    {
        throw new Exception('Znuny TicketSearch route has not been verified.');
    }

    /**
     * Construct the UI agent URL for a Ticket
     */
    public function ticketUrl(int|string $ticketId): string
    {
        $normalizedId = $this->normalizeTicketId($ticketId);

        $template = $this->ticketUrlTemplate();
        if (empty($template)) {
            $webUrl = $this->webUrl();

            return "{$webUrl}?Action=AgentTicketZoom;TicketID=".urlencode((string) $normalizedId);
        }

        return str_replace('{ticket_id}', urlencode((string) $normalizedId), $template);
    }

    /**
     * Test the API connection retrieval of a Ticket.
     */
    public function testConnection(int|string $ticketId = 55992): array
    {
        try {
            $normalizedId = $this->normalizeTicketId($ticketId);
            $this->cachedSessionId = null; // force fresh session check
            $ticket = $this->getTicket($normalizedId);

            return [
                'status' => 'success',
                'TicketID' => $ticket['TicketID'],
                'TicketNumber' => $ticket['TicketNumber'],
                'Title' => $ticket['Title'],
                'Queue' => $ticket['Queue'],
                'Owner' => $ticket['Owner'],
                'State' => $ticket['State'],
                'ticket_url' => $this->ticketUrl($normalizedId),
            ];

        } catch (Throwable $e) {
            return [
                'status' => 'failed',
                'error' => $this->sanitizeExceptionMessage($e->getMessage()),
            ];
        }
    }

    /**
     * Execute an API call with automatic session retry.
     */
    protected function withSessionRetry(\Closure $callback, bool $isRetry = false): mixed
    {
        try {
            return $callback($this->sessionId());
        } catch (Throwable $e) {
            if (! $isRetry && $this->isInvalidSessionError($e)) {
                $this->cachedSessionId = null;

                return $this->withSessionRetry($callback, true);
            }

            throw new Exception($this->sanitizeExceptionMessage($e->getMessage()));
        }
    }

    /**
     * Call /Health
     */
    public function health(): array
    {
        return $this->withSessionRetry(function ($session) {
            $response = $this->request()->get($this->apiUrl().'/Health', [
                'SessionID' => $session,
            ]);

            $data = $this->processResponse($response);

            return [
                'success' => (bool) ($data['Success'] ?? false),
                'plugin' => $data['Plugin'] ?? 'ZnunyAgentList',
                'version' => $data['Version'] ?? '1.1.0',
                'time' => $data['Time'] ?? now()->toIso8601String(),
            ];
        });
    }

    /**
     * Call /SystemConfig
     */
    public function systemConfig(): array
    {
        return $this->withSessionRetry(function ($session) {
            $response = $this->request()->get($this->apiUrl().'/SystemConfig', [
                'SessionID' => $session,
            ]);

            $data = $this->processResponse($response);

            $features = [];
            if (isset($data['Features']) && is_array($data['Features'])) {
                foreach ($data['Features'] as $k => $v) {
                    $features[$k] = (bool) $v;
                }
            }

            return [
                'plugin' => $data['Plugin'] ?? 'ZnunyAgentList',
                'version' => $data['Version'] ?? '1.1.0',
                'znuny_version' => $data['Znuny']['Version'] ?? null,
                'features' => $features,
            ];
        });
    }

    /**
     * Call /Queue
     */
    public function getQueues(): array
    {
        return $this->withSessionRetry(function ($session) {
            $response = $this->request()->get($this->apiUrl().'/Queue', [
                'SessionID' => $session,
            ]);

            $data = $this->processResponse($response);
            $normalized = [];

            if (! empty($data['Queues']) && is_array($data['Queues'])) {
                foreach ($data['Queues'] as $q) {
                    $id = $q['QueueID'] ?? null;
                    $name = trim((string) ($q['Name'] ?? ''));

                    if (! is_numeric($id) || $id <= 0 || $name === '') {
                        continue;
                    }

                    $fullName = isset($q['FullName']) ? trim((string) $q['FullName']) : $name;

                    $normalized[] = [
                        'id' => (int) $id,
                        'name' => $name,
                        'full_name' => $fullName,
                        'valid_id' => (int) ($q['ValidID'] ?? 1),
                        'label' => $name,
                    ];
                }
            }

            usort($normalized, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

            return $normalized;
        });
    }

    /**
     * Call /Queue/{QueueID}
     */
    public function getQueue(int|string $queueId): array
    {
        return $this->withSessionRetry(function ($session) use ($queueId) {
            $response = $this->request()->get($this->apiUrl().'/Queue/'.rawurlencode((string) $queueId), [
                'SessionID' => $session,
            ]);

            $data = $this->processResponse($response);

            return $this->normalizeQueueResponse($data);
        });
    }

    /**
     * Call /QueueByName/{Name}
     */
    public function getQueueByName(string $name): array
    {
        return $this->withSessionRetry(function ($session) use ($name) {
            $response = $this->request()->get($this->apiUrl().'/QueueByName/'.rawurlencode($name), [
                'SessionID' => $session,
            ]);

            $data = $this->processResponse($response);

            return $this->normalizeQueueResponse($data);
        });
    }

    /**
     * Normalize Queue response data into standard array shape.
     */
    private function normalizeQueueResponse(array $data): array
    {
        if (empty($data['Queue']) || empty($data['Queue']['QueueID'])) {
            return [
                'found' => false,
                'warnings' => $data['Warnings'] ?? ['Queue not found.'],
            ];
        }

        $q = $data['Queue'];
        $name = trim((string) ($q['Name'] ?? ''));
        $id = $q['QueueID'] ?? null;

        if (! is_numeric($id) || $id <= 0 || $name === '') {
            return [
                'found' => false,
                'warnings' => $data['Warnings'] ?? ['Queue data invalid.'],
            ];
        }

        return [
            'found' => true,
            'id' => (int) $id,
            'name' => $name,
            'full_name' => isset($q['FullName']) ? trim((string) $q['FullName']) : $name,
            'valid_id' => (int) ($q['ValidID'] ?? 1),
            'label' => $name,
            'warnings' => $data['Warnings'] ?? [],
        ];
    }

    /**
     * Call /CustomerUser?Search=...&Limit=...
     */
    public function searchCustomerUsers(string $search, int $limit = 20): array
    {
        return $this->withSessionRetry(function ($session) use ($search, $limit) {
            $safeLimit = max(1, min(50, $limit));

            $response = $this->request()->get($this->apiUrl().'/CustomerUser', [
                'SessionID' => $session,
                'Search' => $search,
                'Limit' => $safeLimit,
            ]);

            $data = $this->processResponse($response);
            $normalized = [];

            if (! empty($data['CustomerUsers']) && is_array($data['CustomerUsers'])) {
                foreach ($data['CustomerUsers'] as $u) {
                    $login = trim((string) ($u['UserLogin'] ?? ''));
                    if ($login === '') {
                        continue;
                    }

                    $firstName = isset($u['UserFirstname']) ? trim((string) $u['UserFirstname']) : '';
                    $lastName = isset($u['UserLastname']) ? trim((string) $u['UserLastname']) : '';

                    $fullNameParts = array_filter([$firstName, $lastName]);
                    $fullName = implode(' ', $fullNameParts);
                    $label = $fullName ? "{$fullName} <{$login}>" : $login;

                    $normalized[] = [
                        'login' => $login,
                        'customer_id' => trim((string) ($u['UserCustomerID'] ?? '')),
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => trim((string) ($u['UserEmail'] ?? '')),
                        'label' => $label,
                    ];
                }
            }

            usort($normalized, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

            return $normalized;
        });
    }

    /**
     * Call /CustomerUser/{UserLogin}
     */
    public function getCustomerUser(string $userLogin): array
    {
        return $this->withSessionRetry(function ($session) use ($userLogin) {
            $response = $this->request()->get($this->apiUrl().'/CustomerUser/'.rawurlencode($userLogin), [
                'SessionID' => $session,
            ]);

            $data = $this->processResponse($response);

            if (empty($data['CustomerUser']) || empty($data['CustomerUser']['UserLogin'])) {
                return [
                    'found' => false,
                    'warnings' => $data['Warnings'] ?? ['CustomerUser not found.'],
                ];
            }

            $u = $data['CustomerUser'];
            $login = trim((string) $u['UserLogin']);

            if ($login === '') {
                return [
                    'found' => false,
                    'warnings' => $data['Warnings'] ?? ['CustomerUser login invalid.'],
                ];
            }

            $firstName = isset($u['UserFirstname']) ? trim((string) $u['UserFirstname']) : '';
            $lastName = isset($u['UserLastname']) ? trim((string) $u['UserLastname']) : '';

            $fullNameParts = array_filter([$firstName, $lastName]);
            $fullName = implode(' ', $fullNameParts);
            $label = $fullName ? "{$fullName} <{$login}>" : $login;

            return [
                'found' => true,
                'login' => $login,
                'customer_id' => trim((string) ($u['UserCustomerID'] ?? '')),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => trim((string) ($u['UserEmail'] ?? '')),
                'label' => $label,
                'warnings' => $data['Warnings'] ?? [],
            ];
        });
    }

    /**
     * Call /ResolveTicketDefaults?HostName=...
     */
    public function resolveTicketDefaults(string $hostName): array
    {
        return $this->withSessionRetry(function ($session) use ($hostName) {
            $response = $this->request()->get($this->apiUrl().'/ResolveTicketDefaults', [
                'SessionID' => $session,
                'HostName' => $hostName,
            ]);

            $data = $this->processResponse($response);

            $result = [
                'input' => [
                    'host_name' => $hostName,
                ],
                'detected' => [
                    'queue_name' => $data['Detected']['QueueName'] ?? null,
                    'customer_user_login' => $data['Detected']['CustomerUserLogin'] ?? null,
                ],
                'queue' => [
                    'found' => ! empty($data['Queue']['Found']),
                ],
                'customer_user' => [
                    'found' => ! empty($data['CustomerUser']['Found']),
                ],
                'warnings' => $data['Warnings'] ?? [],
            ];

            if ($result['queue']['found']) {
                $result['queue']['id'] = (int) ($data['Queue']['QueueID'] ?? 0);
                $result['queue']['name'] = trim((string) ($data['Queue']['Name'] ?? ''));
                $result['queue']['full_name'] = trim((string) ($data['Queue']['FullName'] ?? ''));
            }

            if ($result['customer_user']['found']) {
                $result['customer_user']['login'] = trim((string) ($data['CustomerUser']['UserLogin'] ?? ''));
                $result['customer_user']['customer_id'] = trim((string) ($data['CustomerUser']['UserCustomerID'] ?? ''));
            }

            return $result;
        });
    }

    /**
     * Call POST /ValidateTicketCreate
     */
    public function validateTicketCreate(array $payload): array
    {
        return $this->withSessionRetry(function ($session) use ($payload) {
            $payload['SessionID'] = $session;

            $response = $this->request()->post($this->apiUrl().'/ValidateTicketCreate', $payload);

            $data = $this->processResponse($response);

            return [
                'valid' => ! empty($data['Valid']),
                'errors' => $data['Errors'] ?? [],
                'warnings' => $data['Warnings'] ?? [],
            ];
        });
    }

    /**
     * Call POST /Ticket to create a new ticket in Znuny.
     */
    public function createTicket(array $payload): array
    {
        return $this->withSessionRetry(function ($session) use ($payload) {
            $payload['SessionID'] = $session;

            $response = $this->request()->post($this->apiUrl().'/Ticket', $payload);

            $data = $this->processResponse($response);

            if (empty($data['TicketID']) || empty($data['TicketNumber'])) {
                return [
                    'success' => false,
                    'ticket_id' => null,
                    'ticket_number' => null,
                    'warnings' => $data['Warnings'] ?? [],
                    'errors' => array_merge($data['Errors'] ?? [], ['Missing TicketID or TicketNumber in response']),
                    'raw' => $data,
                ];
            }

            return [
                'success' => true,
                'ticket_id' => $data['TicketID'],
                'ticket_number' => $data['TicketNumber'],
                'warnings' => $data['Warnings'] ?? [],
                'errors' => $data['Errors'] ?? [],
                'raw' => $data,
            ];
        });
    }
}
