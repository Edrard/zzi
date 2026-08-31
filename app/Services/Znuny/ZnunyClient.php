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

    private ?string $overrideApiUrl = null;

    private ?string $overrideUsername = null;

    private ?string $overridePassword = null;

    /**
     * Provide explicit credentials to override saved settings for this client instance.
     */
    public function withCredentials(string $apiUrl, string $username, string $password): static
    {
        $this->overrideApiUrl = $apiUrl;
        $this->overrideUsername = $username;
        $this->overridePassword = $password;
        $this->cachedSessionId = null;

        return $this;
    }

    /**
     * Test connection with explicit credentials.
     */
    public function testConnectionWithCredentials(string $apiUrl, string $username, string $password): array
    {
        return $this->withCredentials($apiUrl, $username, $password)->testConnection();
    }

    /**
     * Get the base API URL (rtrimmed).
     */
    protected function apiUrl(): string
    {
        $url = $this->overrideApiUrl ?? SettingsService::string('znuny_api_url', '');
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
    protected function buildPendingRequest(): PendingRequest
    {
        $timeout = SettingsService::int('znuny_api_timeout', 15) ?? 15;
        if ($timeout <= 0) {
            $timeout = 1;
        }
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
        $password = $this->overridePassword ?? SettingsService::string('znuny_password', '');
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

        // Return Data unwrapped if present, otherwise return whole payload
        return array_key_exists('Data', $data) ? $data['Data'] : $data;
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

    private function normalizeStrictPositiveId(mixed $value, string $field): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value)) {
            return (int) $value;
        }

        throw new Exception("Malformed raw ID for {$field}.");
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
               str_contains($msg, 'invalid sessionid') ||
               str_contains($msg, 'znunyagentlist.authfail');
    }

    /**
     * Create a session via POST /Session
     */
    public function createSession(): string
    {
        $username = $this->overrideUsername ?? SettingsService::string('znuny_username', '');
        $password = $this->overridePassword ?? SettingsService::string('znuny_password', '');

        if (empty($username) || empty($password)) {
            throw new Exception('Znuny username or password is not configured.');
        }

        try {
            $response = $this->buildPendingRequest()->post($this->apiUrl().'/Session', [
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
            $response = $this->buildPendingRequest()->get($this->apiUrl()."/ZnunyAgentListTicket/{$normalizedId}", [
                'SessionID' => $session,
            ]);

            $data = $this->processResponse($response);

            if (isset($data['Found']) && (int) $data['Found'] === 0) {
                throw new Exception('Ticket not found in Znuny.');
            }

            if (empty($data['Ticket']) || ! is_array($data['Ticket'])) {
                throw new Exception("No valid ticket data returned for TicketID {$normalizedId}.");
            }

            $ticket = $data['Ticket'];

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
                'LockID' => $ticket['LockID'] ?? null,
                'Lock' => $ticket['Lock'] ?? null,
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
     * Get ticket articles through the custom ZnunyAgentList Ticket::Get operation.
     */
    public function getTicketArticles(int|string $ticketId): array
    {
        return $this->withSessionRetry(function ($session) use ($ticketId) {
            $normalizedId = $this->normalizeTicketId($ticketId);

            $response = $this->buildPendingRequest()->get($this->apiUrl().'/ZnunyAgentListTicket/'.rawurlencode((string) $normalizedId), [
                'SessionID' => $session,
                'AllArticles' => 1,
                'DynamicFields' => 0,
                'Attachments' => 0,
            ]);

            $data = $this->processResponse($response);

            $rawFound = $data['Found'] ?? null;
            if ($rawFound !== null && ! in_array($rawFound, [0, '0', 1, '1'], true)) {
                throw new Exception('Malformed Found value in Znuny article response.');
            }
            if ($rawFound === 0 || $rawFound === '0') {
                throw new Exception('Ticket not found in Znuny.');
            }

            $articles = [];
            if (isset($data['Articles']) && is_array($data['Articles'])) {
                $articles = $data['Articles'];
            }

            return array_map(function ($article) {
                $result = [
                    'article_id' => isset($article['ArticleID']) ? (int) $article['ArticleID'] : null,
                    'article_number' => isset($article['ArticleNumber']) ? (int) $article['ArticleNumber'] : null,
                    'ticket_id' => isset($article['TicketID']) ? (int) $article['TicketID'] : null,
                    'subject' => $article['Subject'] ?? null,
                    'body' => $article['Body'] ?? null,
                    'from' => $article['From'] ?? null,
                    'to' => $article['To'] ?? null,
                    'sender_type' => $article['SenderType'] ?? null,
                    'communication_channel' => $article['CommunicationChannel'] ?? null,
                    'is_visible_for_customer' => isset($article['IsVisibleForCustomer']) ? (bool) $article['IsVisibleForCustomer'] : false,
                    'mime_type' => $article['MimeType'] ?? null,
                    'content_type' => $article['ContentType'] ?? null,
                    'created_at' => $article['Created'] ?? $article['CreateTime'] ?? null,
                    'changed_at' => $article['Changed'] ?? $article['ChangeTime'] ?? null,
                    'html_body_available' => false,
                ];

                $htmlBodyAvailable = (int) ($article['HTMLBodyAvailable'] ?? 0);
                if ($htmlBodyAvailable === 1) {
                    $htmlContentType = $article['HTMLBodyContentType'] ?? null;
                    $htmlContentBase64 = $article['HTMLBodyContent'] ?? null;

                    if (is_string($htmlContentType) && is_string($htmlContentBase64)) {
                        $decodedBytes = base64_decode($htmlContentBase64, true);
                        if ($decodedBytes !== false) {
                            $charset = 'UTF-8';
                            if (preg_match('/charset\s*=\s*(?:["\']?)([^"\';\s]+)(?:["\']?)/i', $htmlContentType, $matches)) {
                                $charset = trim($matches[1]);
                            }

                            try {
                                $converted = mb_convert_encoding($decodedBytes, 'UTF-8', $charset);
                                if ($converted !== false) {
                                    // The bytes are UTF-8 now. Keep HTML metadata from forcing
                                    // DOMDocument to reinterpret them using the original charset.
                                    $converted = preg_replace(
                                        '/(<meta\\b[^>]*\\bcharset\\s*=\\s*)(["\']?)[^"\';\\s>]+\\2/i',
                                        '$1UTF-8',
                                        $converted,
                                    ) ?? $converted;

                                    $result['html_body_available'] = true;
                                    $result['html_body'] = $converted;
                                    $result['html_body_content_type'] = $htmlContentType;
                                }
                            } catch (Throwable $e) {
                                // Fall back to false if conversion fails
                            }
                        }
                    }
                }

                return $result;
            }, $articles);
        });
    }

    /**
     * Get ticket article inline attachment references via TicketGet.
     * Does NOT download binary base64 contents (uses GetAttachmentContents=0).
     */
    public function getTicketInlineAttachmentReferences(int|string $ticketId): array
    {
        return $this->withSessionRetry(function ($session) use ($ticketId) {
            $normalizedId = $this->normalizeTicketId($ticketId);

            $response = $this->buildPendingRequest()->get($this->apiUrl().'/Ticket/'.rawurlencode((string) $normalizedId), [
                'SessionID' => $session,
                'AllArticles' => 1,
                'DynamicFields' => 0,
                'Attachments' => 1,
                'GetAttachmentContents' => 0,
            ]);

            $data = $this->processResponse($response);

            $ticketData = [];
            if (isset($data['Ticket']) && is_array($data['Ticket'])) {
                if (isset($data['Ticket'][0]) && is_array($data['Ticket'][0])) {
                    $ticketData = $data['Ticket'][0];
                } elseif (isset($data['Ticket']['TicketID'])) {
                    $ticketData = $data['Ticket'];
                }
            } elseif (isset($data[0]) && is_array($data[0])) {
                $ticketData = $data[0];
            }

            $articles = [];
            if (isset($ticketData['Article']) && is_array($ticketData['Article'])) {
                $articles = array_is_list($ticketData['Article'])
                    ? $ticketData['Article']
                    : [$ticketData['Article']];
            }

            $references = [];

            foreach ($articles as $article) {
                if (! is_array($article)) {
                    continue;
                }

                try {
                    $articleId = $this->normalizeStrictPositiveId($article['ArticleID'] ?? null, 'ArticleID');
                } catch (Throwable $e) {
                    continue;
                }

                $attachments = $article['Attachment'] ?? [];
                if (! is_array($attachments) || $attachments === []) {
                    continue;
                }

                if (! array_is_list($attachments)) {
                    $attachments = [$attachments];
                }

                foreach ($attachments as $attachment) {
                    if (! is_array($attachment)) {
                        continue;
                    }

                    $rawContentId = $attachment['ContentID'] ?? null;
                    if (! is_string($rawContentId) || trim($rawContentId) === '') {
                        continue;
                    }

                    try {
                        $contentId = ZnunyInlineImageContentId::normalize($rawContentId);
                    } catch (Throwable $e) {
                        continue;
                    }

                    $references[] = [
                        'TicketID' => $normalizedId,
                        'ArticleID' => $articleId,
                        'ContentID' => $contentId,
                    ];
                }
            }

            return $references;
        });
    }

    /**
     * Get list of agents from Znuny Agent endpoint.
     */
    public function getAgents(bool $isRetry = false): array
    {
        try {
            $session = $this->sessionId();
            $response = $this->buildPendingRequest()->get($this->apiUrl().'/Agent', [
                'SessionID' => $session,
            ]);

            $data = $this->processResponse($response);

            if (! isset($data['Agents']) || ! is_array($data['Agents'])) {
                throw new Exception('Invalid Agents list returned from Znuny API.');
            }

            $normalized = [];
            foreach ($data['Agents'] as $agent) {
                if (! is_array($agent)) {
                    throw new Exception('Agent entry must be an array.');
                }

                if (! array_key_exists('UserID', $agent)) {
                    throw new Exception('Agent entry must have a UserID.');
                }

                $id = $this->normalizeStrictPositiveId($agent['UserID'], 'UserID');

                if (! array_key_exists('UserLogin', $agent)) {
                    throw new Exception('Agent entry must have a UserLogin.');
                }

                $login = trim((string) $agent['UserLogin']);
                if ($login === '') {
                    throw new Exception('Agent entry must have a non-empty UserLogin.');
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
                    'first_name' => trim((string) ($agent['UserFirstname'] ?? '')),
                    'last_name' => trim((string) ($agent['UserLastname'] ?? '')),
                    'label' => $label,
                ];
            }

            usort($normalized, function ($a, $b) {
                $cmp = strcasecmp($a['label'], $b['label']);
                if ($cmp === 0) {
                    return $a['id'] <=> $b['id'];
                }

                return $cmp;
            });

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
     * Get list of ticket states.
     */
    public function getTicketStates(bool $isRetry = false): array
    {
        try {
            $session = $this->sessionId();
            $response = $this->buildPendingRequest()->get($this->apiUrl().'/TicketState', [
                'SessionID' => $session,
            ]);

            $data = $this->processResponse($response);

            if (! isset($data['TicketStates']) || ! is_array($data['TicketStates'])) {
                throw new Exception('Invalid TicketStates list returned from Znuny API.');
            }

            return $data['TicketStates'];

        } catch (Throwable $e) {
            if (! $isRetry && $this->isInvalidSessionError($e)) {
                $this->cachedSessionId = null;

                return $this->getTicketStates(true);
            }

            throw new Exception($this->sanitizeExceptionMessage($e->getMessage()));
        }
    }

    /**
     * Get list of ticket priorities.
     */
    public function getTicketPriorities(bool $isRetry = false): array
    {
        try {
            $session = $this->sessionId();
            $response = $this->buildPendingRequest()->get($this->apiUrl().'/TicketPriority', [
                'SessionID' => $session,
            ]);

            $data = $this->processResponse($response);

            if (! isset($data['TicketPriorities']) || ! is_array($data['TicketPriorities'])) {
                throw new Exception('Invalid TicketPriorities list returned from Znuny API.');
            }

            return $data['TicketPriorities'];

        } catch (Throwable $e) {
            if (! $isRetry && $this->isInvalidSessionError($e)) {
                $this->cachedSessionId = null;

                return $this->getTicketPriorities(true);
            }

            throw new Exception($this->sanitizeExceptionMessage($e->getMessage()));
        }
    }

    /**
     * Get list of ticket types.
     */
    public function getTicketTypes(bool $isRetry = false): array
    {
        try {
            $session = $this->sessionId();
            $response = $this->buildPendingRequest()->get($this->apiUrl().'/TicketType', [
                'SessionID' => $session,
            ]);

            $data = $this->processResponse($response);

            if (! isset($data['TicketTypes']) || ! is_array($data['TicketTypes'])) {
                throw new Exception('Invalid TicketTypes list returned from Znuny API.');
            }

            return $data['TicketTypes'];

        } catch (Throwable $e) {
            if (! $isRetry && $this->isInvalidSessionError($e)) {
                $this->cachedSessionId = null;

                return $this->getTicketTypes(true);
            }

            throw new Exception($this->sanitizeExceptionMessage($e->getMessage()));
        }
    }

    /**
     * Search tickets through TicketSearch operation
     */
    public function searchTickets(array $filters = [], bool $isRetry = false): array
    {
        if (empty($filters)) {
            return [];
        }

        $validFilters = [
            'TicketNumber', 'Title', 'Queue', 'State', 'StateType', 'Owner',
            'CreatedFrom', 'CreatedTo',
            'Limit', 'Offset', 'Page', 'SortBy', 'SortDirection',
        ];

        $payload = [];
        $hasMeaningfulFilter = false;

        foreach ($validFilters as $f) {
            if (isset($filters[$f])) {
                $payload[$f] = $filters[$f];
                if (! in_array($f, ['Limit', 'Offset', 'Page', 'SortBy', 'SortDirection'])) {
                    $hasMeaningfulFilter = true;
                }
            }
        }

        if (! $hasMeaningfulFilter) {
            return [];
        }

        return $this->withSessionRetry(function ($session) use ($payload) {
            $payload['SessionID'] = $session;

            $response = $this->buildPendingRequest()->get($this->apiUrl().'/ZnunyAgentListTicketSearch', $payload);

            $data = $this->processResponse($response);

            $tickets = [];
            if (isset($data['Tickets']) && is_array($data['Tickets'])) {
                $tickets = $data['Tickets'];
            } elseif (isset($data['Ticket']) && is_array($data['Ticket'])) {
                if (isset($data['Ticket']['TicketID'])) {
                    $tickets = [$data['Ticket']];
                } else {
                    $tickets = $data['Ticket'];
                }
            } elseif (is_array($data) && array_is_list($data)) {
                $tickets = $data;
            } elseif (is_array($data) && isset($data['TicketID'])) {
                $tickets = [$data];
            }

            return array_map(fn ($ticket) => $this->mapTicketResponse($ticket), $tickets);
        });
    }

    /**
     * Perform a ticket search and return tickets along with pagination and count metadata.
     */
    public function searchTicketsWithMetadata(array $filters = []): array
    {
        if (empty($filters)) {
            return $this->emptyMetadataResponse();
        }

        $validFilters = [
            'TicketNumber', 'Title', 'Queue', 'State', 'StateType', 'Owner',
            'CreatedFrom', 'CreatedTo',
            'Limit', 'Offset', 'Page', 'SortBy', 'SortDirection', 'CountOnly',
        ];

        $payload = [];
        $hasMeaningfulFilter = false;

        foreach ($validFilters as $f) {
            if (isset($filters[$f])) {
                $payload[$f] = $filters[$f];
                if (! in_array($f, ['Limit', 'Offset', 'Page', 'SortBy', 'SortDirection', 'CountOnly'])) {
                    $hasMeaningfulFilter = true;
                }
            }
        }

        if (! $hasMeaningfulFilter) {
            return $this->emptyMetadataResponse();
        }

        return $this->withSessionRetry(function ($session) use ($payload) {
            $payload['SessionID'] = $session;

            $response = $this->buildPendingRequest()->get($this->apiUrl().'/ZnunyAgentListTicketSearch', $payload);

            $data = $this->processResponse($response);

            $tickets = [];
            if (isset($data['Tickets']) && is_array($data['Tickets'])) {
                $tickets = $data['Tickets'];
            } elseif (isset($data['Ticket']) && is_array($data['Ticket'])) {
                if (isset($data['Ticket']['TicketID'])) {
                    $tickets = [$data['Ticket']];
                } else {
                    $tickets = $data['Ticket'];
                }
            } elseif (is_array($data) && array_is_list($data)) {
                $tickets = $data;
            } elseif (is_array($data) && isset($data['TicketID'])) {
                $tickets = [$data];
            }

            $mappedTickets = array_map(fn ($ticket) => $this->mapTicketResponse($ticket), $tickets);

            $count = count($mappedTickets);
            if (isset($data['Count'])) {
                $count = (int) $data['Count'];
            }

            $totalCount = $count;
            if (isset($data['TotalCount'])) {
                $totalCount = (int) $data['TotalCount'];
            }

            $limit = 0;
            if (isset($data['Limit'])) {
                $limit = (int) $data['Limit'];
            }

            $offset = 0;
            if (isset($data['Offset'])) {
                $offset = (int) $data['Offset'];
            }

            $countOnly = false;
            if (! empty($payload['CountOnly'])) {
                $countOnly = true;
            }

            $warnings = [];
            if (isset($data['Warnings']) && is_array($data['Warnings'])) {
                $warnings = $data['Warnings'];
            }

            return [
                'tickets' => $mappedTickets,
                'count' => $count,
                'total_count' => $totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'sort_by' => $payload['SortBy'] ?? null,
                'sort_direction' => $payload['SortDirection'] ?? null,
                'count_only' => $countOnly,
                'warnings' => $warnings,
            ];
        });
    }

    private function emptyMetadataResponse(): array
    {
        return [
            'tickets' => [],
            'count' => 0,
            'total_count' => 0,
            'limit' => 0,
            'offset' => 0,
            'sort_by' => null,
            'sort_direction' => null,
            'count_only' => false,
            'warnings' => [],
        ];
    }

    private function normalizeInlineAttachmentCount(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function normalizeHTMLBodyArticleCount(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function mapTicketResponse(array $ticket): array
    {
        return [
            'TicketID' => isset($ticket['TicketID']) ? (int) $ticket['TicketID'] : null,
            'TicketNumber' => $ticket['TicketNumber'] ?? null,
            'Title' => $ticket['Title'] ?? null,
            'QueueID' => isset($ticket['QueueID']) ? (int) $ticket['QueueID'] : null,
            'Queue' => $ticket['Queue'] ?? null,
            'OwnerID' => isset($ticket['OwnerID']) ? (int) $ticket['OwnerID'] : null,
            'Owner' => $ticket['Owner'] ?? null,
            'ResponsibleID' => isset($ticket['ResponsibleID']) ? (int) $ticket['ResponsibleID'] : null,
            'Responsible' => $ticket['Responsible'] ?? null,
            'CustomerID' => $ticket['CustomerID'] ?? null,
            'CustomerUserID' => $ticket['CustomerUserID'] ?? null,
            'CustomerUser' => $ticket['CustomerUser'] ?? null,
            'StateID' => isset($ticket['StateID']) ? (int) $ticket['StateID'] : null,
            'State' => $ticket['State'] ?? null,
            'StateType' => $ticket['StateType'] ?? null,
            'LockID' => isset($ticket['LockID']) ? (int) $ticket['LockID'] : null,
            'Lock' => $ticket['Lock'] ?? null,
            'PriorityID' => isset($ticket['PriorityID']) ? (int) $ticket['PriorityID'] : null,
            'Priority' => $ticket['Priority'] ?? null,
            'TypeID' => isset($ticket['TypeID']) ? (int) $ticket['TypeID'] : null,
            'Type' => $ticket['Type'] ?? null,
            'ServiceID' => isset($ticket['ServiceID']) ? (int) $ticket['ServiceID'] : null,
            'Service' => $ticket['Service'] ?? null,
            'SLAID' => isset($ticket['SLAID']) ? (int) $ticket['SLAID'] : null,
            'SLA' => $ticket['SLA'] ?? null,
            'Created' => $ticket['Created'] ?? null,
            'Changed' => $ticket['Changed'] ?? null,
            'ArticleCount' => isset($ticket['ArticleCount']) ? (int) $ticket['ArticleCount'] : null,
            'InlineAttachmentCount' => $this->normalizeInlineAttachmentCount($ticket['InlineAttachmentCount'] ?? null),
            'HTMLBodyArticleCount' => $this->normalizeHTMLBodyArticleCount($ticket['HTMLBodyArticleCount'] ?? null),
            'LastArticleID' => isset($ticket['LastArticleID']) ? (int) $ticket['LastArticleID'] : null,
            'LastArticleCreated' => $ticket['LastArticleCreated'] ?? null,
            'SyncFingerprint' => $ticket['SyncFingerprint'] ?? null,
        ];
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
    public function testConnection(int|string|null $ticketId = null): array
    {
        $this->cachedSessionId = null; // force fresh session check

        $result = [
            'status' => 'failed',
            'checks' => [],
            'counts' => [
                'agents' => 0,
                'queues' => 0,
                'states' => 0,
            ],
            'warnings' => [],
            'errors' => [],
        ];

        try {
            // Check 1: Session creation
            $this->sessionId();
            $result['checks']['session'] = true;

            // Check 2: Health
            $health = $this->health();
            $result['checks']['health'] = $health['success'];

            // Check 3: SystemConfig
            $config = $this->systemConfig();
            $result['checks']['system_config'] = ! empty($config['plugin']);

            // Check 4: Agents
            $agents = $this->getAgents();
            $result['counts']['agents'] = count($agents);
            $result['checks']['agents'] = true;

            // Check 5: Queues
            $queues = $this->getQueues();
            $result['counts']['queues'] = count($queues);
            $result['checks']['queues'] = true;

            // Check 6: Ticket States
            $states = $this->getTicketStates();
            $result['counts']['states'] = count($states);
            $result['checks']['states'] = true;

            // Optional Check: Ticket
            if ($ticketId) {
                try {
                    $normalizedId = $this->normalizeTicketId($ticketId);
                    $ticket = $this->getTicket($normalizedId);
                    $result['checks']['ticket'] = true;
                    $result['ticket_url'] = $this->ticketUrl($normalizedId);
                } catch (Throwable $te) {
                    $result['checks']['ticket'] = false;
                    $result['warnings'][] = 'Optional ticket check failed: '.$this->sanitizeExceptionMessage($te->getMessage());
                }
            }

            $result['status'] = empty($result['warnings']) ? 'success' : 'partial';

        } catch (Throwable $e) {
            $result['status'] = 'failed';
            $result['errors'][] = $this->sanitizeExceptionMessage($e->getMessage());
        }

        return $result;
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
            $response = $this->buildPendingRequest()->get($this->apiUrl().'/Health', [
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
            $response = $this->buildPendingRequest()->get($this->apiUrl().'/SystemConfig', [
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
            $response = $this->buildPendingRequest()->get($this->apiUrl().'/Queue', [
                'SessionID' => $session,
            ]);

            $data = $this->processResponse($response);
            $normalized = [];

            if (! isset($data['Queues']) || ! is_array($data['Queues'])) {
                throw new Exception('Invalid Queues list returned from Znuny API.');
            }

            foreach ($data['Queues'] as $q) {
                if (! is_array($q)) {
                    throw new Exception('Queue entry must be an array.');
                }

                if (! array_key_exists('QueueID', $q)) {
                    throw new Exception('Queue entry must have a QueueID.');
                }
                $id = $this->normalizeStrictPositiveId($q['QueueID'], 'QueueID');

                if (! array_key_exists('Name', $q)) {
                    throw new Exception('Queue entry must have a Name.');
                }
                $name = trim((string) $q['Name']);
                if ($name === '') {
                    throw new Exception('Queue entry must have a non-empty Name.');
                }

                if (! array_key_exists('ValidID', $q)) {
                    throw new Exception('Queue entry must have a ValidID.');
                }
                $validId = $this->normalizeStrictPositiveId($q['ValidID'], 'ValidID');

                $fullName = isset($q['FullName']) ? trim((string) $q['FullName']) : $name;

                $normalized[] = [
                    'id' => $id,
                    'name' => $name,
                    'full_name' => $fullName,
                    'valid_id' => $validId,
                    'label' => $name,
                ];
            }

            usort($normalized, function ($a, $b) {
                $cmp = strcasecmp($a['label'], $b['label']);
                if ($cmp === 0) {
                    return $a['id'] <=> $b['id'];
                }

                return $cmp;
            });

            return $normalized;
        });
    }

    /**
     * Call /Queue/{QueueID}
     */
    public function getQueue(int|string $queueId): array
    {
        return $this->withSessionRetry(function ($session) use ($queueId) {
            $response = $this->buildPendingRequest()->get($this->apiUrl().'/Queue/'.rawurlencode((string) $queueId), [
                'SessionID' => $session,
            ]);

            $data = $this->processResponse($response);

            return $this->normalizeQueueResponse($data);
        });
    }

    /**
     * Call /TicketMoveAssign/Validate
     */
    public function validateTicketMoveAssign(array $payload): array
    {
        return $this->withSessionRetry(function ($session) use ($payload) {
            $payload['SessionID'] = $session;

            $response = $this->buildPendingRequest()->post($this->apiUrl().'/TicketMoveAssign/Validate', $payload);

            return $this->processResponse($response);
        });
    }

    /**
     * Call /TicketMoveAssign
     */
    public function moveAssignTicket(array $payload): array
    {
        return $this->withSessionRetry(function ($session) use ($payload) {
            $payload['SessionID'] = $session;

            $response = $this->buildPendingRequest()->post($this->apiUrl().'/TicketMoveAssign', $payload);

            return $this->processResponse($response);
        });
    }

    /**
     * Call /QueueByName/{Name}
     */
    public function getQueueByName(string $name): array
    {
        return $this->withSessionRetry(function ($session) use ($name) {
            $response = $this->buildPendingRequest()->get($this->apiUrl().'/QueueByName/'.rawurlencode($name), [
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
     * Call /Queue/:QueueID/AssignableAgents
     */
    public function getQueueAssignableAgents(int|string $queueId): array
    {
        return $this->withSessionRetry(function ($session) use ($queueId) {
            $response = $this->buildPendingRequest()->get($this->apiUrl().'/Queue/'.rawurlencode((string) $queueId).'/AssignableAgents', [
                'SessionID' => $session,
            ]);

            $data = $this->processResponse($response);
            $normalized = [];

            if (! empty($data['Agents']) && is_array($data['Agents'])) {
                foreach ($data['Agents'] as $agent) {
                    $id = trim((string) ($agent['UserID'] ?? ''));
                    if ($id === '') {
                        continue;
                    }
                    $fullname = trim((string) ($agent['UserFullname'] ?? ''));
                    $login = trim((string) ($agent['UserLogin'] ?? ''));
                    $label = ($fullname !== '') ? "{$fullname} <{$login}>" : $login;

                    $normalized[] = [
                        'id' => (int) $id,
                        'login' => $login,
                        'first_name' => trim((string) ($agent['UserFirstname'] ?? '')),
                        'last_name' => trim((string) ($agent['UserLastname'] ?? '')),
                        'label' => $label,
                    ];
                }
            }

            usort($normalized, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

            return $normalized;
        });
    }

    /**
     * Call /Agent/:UserID/AssignableQueues
     */
    public function getAgentAssignableQueues(int|string $userId): array
    {
        return $this->withSessionRetry(function ($session) use ($userId) {
            $response = $this->buildPendingRequest()->get($this->apiUrl().'/Agent/'.rawurlencode((string) $userId).'/AssignableQueues', [
                'SessionID' => $session,
            ]);

            $data = $this->processResponse($response);
            if (! array_key_exists('Queues', $data) || ! is_array($data['Queues'])) {
                throw new Exception('Invalid Assignable Queues list returned from Znuny API.');
            }

            $normalized = [];
            foreach ($data['Queues'] as $q) {
                if (! is_array($q)) {
                    throw new Exception('Queue relationship entry must be an array.');
                }

                if (! array_key_exists('QueueID', $q)) {
                    throw new Exception('Queue relationship entry must have a QueueID.');
                }
                $id = $this->normalizeStrictPositiveId($q['QueueID'], 'QueueID');

                $normalized[] = [
                    'id' => $id,
                    'name' => trim((string) ($q['Name'] ?? '')),
                    'group_id' => trim((string) ($q['GroupID'] ?? '')),
                    'label' => trim((string) ($q['Name'] ?? '')),
                ];
            }

            usort($normalized, function ($a, $b) {
                $cmp = strcasecmp($a['name'], $b['name']);
                if ($cmp === 0) {
                    return $a['id'] <=> $b['id'];
                }

                return $cmp;
            });

            return $normalized;
        });
    }

    /**
     * Call /CustomerUser?Search=...&Limit=...
     */
    public function searchCustomerUsers(string $search, int $limit = 20): array
    {
        return $this->withSessionRetry(function ($session) use ($search, $limit) {
            $safeLimit = max(1, min(50, $limit));

            $response = $this->buildPendingRequest()->get($this->apiUrl().'/CustomerUser', [
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
            $response = $this->buildPendingRequest()->get($this->apiUrl().'/CustomerUser/'.rawurlencode($userLogin), [
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
     * Call POST /CustomerUser
     */
    public function createCustomerUser(array $payload): array
    {
        $filteredPayload = [
            'Login' => $payload['Login'] ?? '',
            'Email' => $payload['Email'] ?? '',
            'FirstName' => $payload['FirstName'] ?? '',
            'LastName' => $payload['LastName'] ?? '',
            'CustomerID' => $payload['CustomerID'] ?? '',
        ];

        return $this->withSessionRetry(function ($session) use ($filteredPayload) {
            $filteredPayload['SessionID'] = $session;

            $response = $this->buildPendingRequest()->post($this->apiUrl().'/CustomerUser', $filteredPayload);

            $data = $this->processResponse($response);

            // Plugin contract:
            // Success => 1
            // Data => { Created => 1, CustomerUser => {...}, Errors => [] }

            $created = ! empty($data['Created']);
            $customerUser = $data['CustomerUser'] ?? null;
            $errors = $data['Errors'] ?? [];

            if (! $created) {
                return [
                    'found' => false,
                    'created' => false,
                    'errors' => $errors,
                ];
            }

            if (empty($customerUser['UserLogin'])) {
                return [
                    'found' => false,
                    'created' => false,
                    'errors' => array_merge($errors, ['CustomerUser login missing in response.']),
                ];
            }

            return [
                'found' => true,
                'created' => true,
                'login' => trim((string) $customerUser['UserLogin']),
                'customer_id' => trim((string) ($customerUser['UserCustomerID'] ?? '')),
                'errors' => $errors,
                'customer_user' => $customerUser,
            ];
        });
    }

    /**
     * Call PATCH /CustomerUser/{UserLogin}
     */
    public function updateCustomerUser(string $login, array $payload): array
    {
        $filteredPayload = [];
        if (array_key_exists('Email', $payload)) {
            $filteredPayload['Email'] = $payload['Email'];
        }
        if (array_key_exists('FirstName', $payload)) {
            $filteredPayload['FirstName'] = $payload['FirstName'];
        }
        if (array_key_exists('LastName', $payload)) {
            $filteredPayload['LastName'] = $payload['LastName'];
        }
        if (array_key_exists('CustomerID', $payload)) {
            $filteredPayload['CustomerID'] = $payload['CustomerID'];
        }
        if (array_key_exists('Login', $payload)) {
            $filteredPayload['Login'] = $payload['Login'];
        }

        return $this->withSessionRetry(function ($session) use ($login, $filteredPayload) {
            $filteredPayload['SessionID'] = $session;

            $response = $this->buildPendingRequest()->patch($this->apiUrl().'/CustomerUser/'.rawurlencode($login), $filteredPayload);

            $data = $this->processResponse($response);

            $updated = ! empty($data['Updated']);
            $customerUser = $data['CustomerUser'] ?? null;
            $errors = $data['Errors'] ?? [];

            if (! $updated) {
                return [
                    'updated' => false,
                    'errors' => $errors,
                ];
            }

            if (empty($customerUser['UserLogin'])) {
                return [
                    'updated' => false,
                    'errors' => array_merge($errors, ['CustomerUser login missing in response.']),
                ];
            }

            return [
                'updated' => true,
                'login' => trim((string) $customerUser['UserLogin']),
                'customer_id' => trim((string) ($customerUser['UserCustomerID'] ?? '')),
                'errors' => $errors,
                'customer_user' => $customerUser,
            ];
        });
    }

    /**
     * Call /ResolveTicketDefaults?HostName=...
     */
    public function resolveTicketDefaults(string $hostName): array
    {
        return $this->withSessionRetry(function ($session) use ($hostName) {
            $response = $this->buildPendingRequest()->get($this->apiUrl().'/ResolveTicketDefaults', [
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

            $response = $this->buildPendingRequest()->post($this->apiUrl().'/ValidateTicketCreate', $payload);

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

            $response = $this->buildPendingRequest()->post($this->apiUrl().'/Ticket', $payload);

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

    /**
     * Call PATCH /Ticket/{TicketID} to create an article/note on an existing ticket.
     */
    public function createTicketArticle(int|string $ticketId, string $subject, string $body, bool $visibleForCustomer): array
    {
        return $this->withSessionRetry(function ($session) use ($ticketId, $subject, $body, $visibleForCustomer) {
            $normalizedId = $this->normalizeTicketId($ticketId);

            $payload = [
                'SessionID' => $session,
                'Ticket' => [
                    'TicketID' => $normalizedId,
                ],
                'Article' => [
                    'Subject' => $subject,
                    'Body' => $body,
                    'ContentType' => 'text/plain; charset=utf-8',
                    'MimeType' => 'text/plain',
                    'Charset' => 'utf-8',
                    'IsVisibleForCustomer' => $visibleForCustomer ? 1 : 0,
                ],
            ];

            $response = $this->buildPendingRequest()->patch($this->apiUrl().'/Ticket/'.$normalizedId, $payload);

            $data = $this->processResponse($response);

            if (empty($data['ArticleID']) || empty($data['TicketID'])) {
                return [
                    'success' => false,
                    'article_id' => null,
                    'ticket_id' => null,
                    'ticket_number' => null,
                    'warnings' => $data['Warnings'] ?? [],
                    'errors' => array_merge($data['Errors'] ?? [], ['Missing ArticleID or TicketID in response']),
                    'raw' => $data,
                ];
            }

            return [
                'success' => true,
                'article_id' => $data['ArticleID'],
                'ticket_id' => $data['TicketID'],
                'ticket_number' => $data['TicketNumber'] ?? null,
                'warnings' => $data['Warnings'] ?? [],
                'errors' => $data['Errors'] ?? [],
                'raw' => $data,
            ];
        });
    }

    /**
     * Normalize controlled ticket write operations (like Close and Reopen)
     * expecting the unwrapped Data payload.
     */
    protected function normalizeControlledTicketWriteResponse(array $data): array
    {
        $ticket = $data['Ticket'] ?? [];

        $ticketId = $data['TicketID'] ?? $ticket['TicketID'] ?? null;
        $ticketNumber = $data['TicketNumber'] ?? $ticket['TicketNumber'] ?? null;
        $state = $data['State'] ?? $ticket['State'] ?? null;
        $stateType = $data['StateType'] ?? $ticket['StateType'] ?? null;
        $articleId = $data['ArticleID'] ?? null;

        $errors = $data['Errors'] ?? [];
        $warnings = $data['Warnings'] ?? [];

        $hasSuccessIndicator = $articleId !== null || $ticketId !== null || $ticketNumber !== null || ! empty($ticket) || $state !== null;
        $success = empty($errors) && $hasSuccessIndicator;

        if (! $success && empty($errors)) {
            $errors[] = 'Missing ticket write confirmation in response.';
        }

        return [
            'success' => $success,
            'ticket_id' => $ticketId,
            'ticket_number' => $ticketNumber,
            'state' => $state,
            'state_type' => $stateType,
            'article_id' => $articleId,
            'warnings' => $warnings,
            'errors' => $errors,
            'raw' => $data,
        ];
    }

    /**
     * Call POST /TicketClose to safely close a ticket.
     */
    public function closeTicket(int|string $ticketId, array $payload): array
    {
        return $this->withSessionRetry(function ($session) use ($ticketId, $payload) {
            $normalizedId = $this->normalizeTicketId($ticketId);

            $payload['SessionID'] = $session;
            $payload['TicketID'] = $normalizedId;

            $response = $this->buildPendingRequest()->post($this->apiUrl().'/TicketClose', $payload);

            $data = $this->processResponse($response);

            return $this->normalizeControlledTicketWriteResponse($data);
        });
    }

    /**
     * Call POST /TicketReopen to safely reopen a ticket.
     */
    public function reopenTicket(int|string $ticketId, array $payload): array
    {
        return $this->withSessionRetry(function ($session) use ($ticketId, $payload) {
            $normalizedId = $this->normalizeTicketId($ticketId);

            $payload['SessionID'] = $session;
            $payload['TicketID'] = $normalizedId;

            $response = $this->buildPendingRequest()->post($this->apiUrl().'/TicketReopen', $payload);

            $data = $this->processResponse($response);

            return $this->normalizeControlledTicketWriteResponse($data);
        });
    }

    /**
     * Call POST /TicketUnlock to safely unlock a ticket.
     */
    public function unlockTicket(int|string $ticketIdOrNumber): array
    {
        return $this->withSessionRetry(function ($session) use ($ticketIdOrNumber) {
            $payload = ['SessionID' => $session];

            if (is_numeric($ticketIdOrNumber)) {
                $payload['TicketID'] = $this->normalizeTicketId($ticketIdOrNumber);
            } else {
                $payload['TicketNumber'] = $ticketIdOrNumber;
            }

            try {
                $response = $this->buildPendingRequest()->post($this->apiUrl().'/TicketUnlock', $payload);
                $data = $this->processResponse($response);
            } catch (Throwable $e) {
                return [
                    'success' => false,
                    'warnings' => ['Unlock failed or route not available: '.$this->sanitizeExceptionMessage($e->getMessage())],
                    'errors' => [],
                ];
            }

            $ticket = $data['Ticket'] ?? [];
            $ticketId = $data['TicketID'] ?? $ticket['TicketID'] ?? null;
            $ticketNumber = $data['TicketNumber'] ?? $ticket['TicketNumber'] ?? null;
            $lockId = $data['LockID'] ?? $ticket['LockID'] ?? null;
            $lock = $data['Lock'] ?? $ticket['Lock'] ?? null;
            $state = $data['State'] ?? $ticket['State'] ?? null;
            $stateType = $data['StateType'] ?? $ticket['StateType'] ?? null;

            $errors = $data['Errors'] ?? [];
            $warnings = $data['Warnings'] ?? [];

            // ZnunyAgentList TicketUnlock returns Lock => 'unlock'
            $success = empty($errors) && $lock === 'unlock';

            if (! $success && empty($errors)) {
                $errors[] = 'Missing unlock confirmation in response.';
            }

            return [
                'success' => $success,
                'ticket_id' => $ticketId,
                'ticket_number' => $ticketNumber,
                'lock_id' => $lockId,
                'lock' => $lock,
                'state' => $state,
                'state_type' => $stateType,
                'warnings' => $warnings,
                'errors' => $errors,
                'raw' => $data,
            ];
        });
    }

    /**
     * Call POST /TicketLock to safely lock a ticket.
     */
    public function lockTicket(int|string $ticketIdOrNumber): array
    {
        return $this->withSessionRetry(function ($session) use ($ticketIdOrNumber) {
            $payload = ['SessionID' => $session];

            if (is_numeric($ticketIdOrNumber)) {
                $payload['TicketID'] = $this->normalizeTicketId($ticketIdOrNumber);
            } else {
                $payload['TicketNumber'] = $ticketIdOrNumber;
            }

            try {
                $response = $this->buildPendingRequest()->post($this->apiUrl().'/TicketLock', $payload);
                $data = $this->processResponse($response);
            } catch (Throwable $e) {
                return [
                    'success' => false,
                    'warnings' => ['Lock failed or route not available: '.$this->sanitizeExceptionMessage($e->getMessage())],
                    'errors' => [],
                ];
            }

            $ticket = $data['Ticket'] ?? [];
            $ticketId = $data['TicketID'] ?? $ticket['TicketID'] ?? null;
            $ticketNumber = $data['TicketNumber'] ?? $ticket['TicketNumber'] ?? null;
            $lockId = $data['LockID'] ?? $ticket['LockID'] ?? null;
            $lock = $data['Lock'] ?? $ticket['Lock'] ?? null;
            $state = $data['State'] ?? $ticket['State'] ?? null;
            $stateType = $data['StateType'] ?? $ticket['StateType'] ?? null;

            $errors = $data['Errors'] ?? [];
            $warnings = $data['Warnings'] ?? [];

            // ZnunyAgentList TicketLock returns Lock => 'lock'
            $success = empty($errors) && $lock === 'lock';

            if (! $success && empty($errors)) {
                $errors[] = 'Missing lock confirmation in response.';
            }

            return [
                'success' => $success,
                'ticket_id' => $ticketId,
                'ticket_number' => $ticketNumber,
                'lock_id' => $lockId,
                'lock' => $lock,
                'state' => $state,
                'state_type' => $stateType,
                'warnings' => $warnings,
                'errors' => $errors,
                'raw' => $data,
            ];
        });
    }

    public function getInlineAttachment(int|string $ticketId, int|string $articleId, string $contentId): array
    {
        $normalizedTicketId = $this->normalizeTicketId($ticketId);
        $normalizedArticleId = $this->normalizeTicketId($articleId); // Same logic applies

        if (empty($contentId)) {
            throw new \InvalidArgumentException('ContentID cannot be empty.');
        }

        return $this->withSessionRetry(function ($session) use ($normalizedTicketId, $normalizedArticleId, $contentId) {
            $payload = [
                'SessionID' => $session,
                'ContentID' => $contentId,
            ];

            $response = $this->buildPendingRequest()->get($this->apiUrl()."/ZnunyAgentListTicket/{$normalizedTicketId}/Article/{$normalizedArticleId}/InlineAttachment", $payload);
            $data = $this->processResponse($response);

            $rawFound = $data['Found'] ?? null;
            if ($rawFound !== 0 && $rawFound !== '0' && $rawFound !== 1 && $rawFound !== '1') {
                throw new \RuntimeException('Malformed Found value in upstream response.');
            }
            $found = (int) $rawFound === 1;

            if (! $found) {
                return [
                    'found' => false,
                    'ticket_id' => $data['TicketID'] ?? null,
                    'article_id' => $data['ArticleID'] ?? null,
                    'errors' => $data['Errors'] ?? ['Inline attachment not found.'],
                ];
            }

            $returnedTicketId = $data['TicketID'] ?? null;
            $returnedArticleId = $data['ArticleID'] ?? null;

            if ((string) $returnedTicketId !== (string) $normalizedTicketId || (string) $returnedArticleId !== (string) $normalizedArticleId) {
                throw new \RuntimeException('Mismatch in returned TicketID or ArticleID.');
            }

            $contentType = $data['ContentType'] ?? null;
            $returnedContentId = $data['ContentID'] ?? null;
            $content = $data['Content'] ?? null;

            if (empty($contentType) || empty($returnedContentId) || empty($content)) {
                throw new \RuntimeException('Missing ContentType, ContentID, or Content in response.');
            }

            return [
                'found' => true,
                'ticket_id' => $returnedTicketId,
                'article_id' => $returnedArticleId,
                'filename' => $data['Filename'] ?? null,
                'content_type' => $contentType,
                'content_id' => $returnedContentId,
                'filesize_raw' => isset($data['FilesizeRaw']) ? (int) $data['FilesizeRaw'] : null,
                'content_base64' => $content,
            ];
        });
    }

    /**
     * Get a page of customer companies from Znuny.
     */
    public function getCustomerCompaniesPage(int $offset = 0, int $limit = 100): array
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('Offset must be >= 0.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Limit must be between 1 and 100.');
        }

        return $this->withSessionRetry(function ($session) use ($offset, $limit) {
            $response = $this->buildPendingRequest()->get($this->apiUrl().'/CustomerCompany', [
                'SessionID' => $session,
                'Offset' => $offset,
                'Limit' => $limit,
            ]);

            $data = $this->processResponse($response);

            if (! isset($data['Errors'])) {
                throw new Exception('Missing Errors field in response.');
            }
            if (! is_array($data['Errors'])) {
                throw new Exception('Malformed Errors field in response.');
            }
            if (! empty($data['Errors'])) {
                throw new Exception('CustomerCompany API returned errors.');
            }

            if (! isset($data['CustomerCompanies']) || ! is_array($data['CustomerCompanies'])) {
                throw new Exception('Malformed CustomerCompanies array in response.');
            }

            $companies = [];
            foreach ($data['CustomerCompanies'] as $company) {
                if (! is_array($company)) {
                    throw new Exception('Malformed CustomerCompany row.');
                }

                $id = $company['CustomerID'] ?? null;
                if (! is_scalar($id) || trim((string) $id) === '') {
                    throw new Exception('CustomerCompany row missing valid CustomerID.');
                }

                $name = $company['CustomerCompanyName'] ?? null;
                if (! is_scalar($name) || trim((string) $name) === '') {
                    throw new Exception('CustomerCompany row missing valid CustomerCompanyName.');
                }

                $companies[] = [
                    'customer_id' => (string) $id,
                    'name' => (string) $name,
                ];
            }

            $parseMetadataInt = function ($key, $data, $min = 0, $max = null) {
                if (! array_key_exists($key, $data)) {
                    throw new Exception("Missing pagination metadata: {$key}.");
                }
                $val = $data[$key];
                if (is_int($val)) {
                    $intVal = $val;
                } elseif (is_string($val) && preg_match('/^(0|[1-9][0-9]*)$/', $val)) {
                    if ((string) (int) $val !== $val) {
                        throw new Exception("Pagination metadata {$key} out of range.");
                    }
                    $intVal = (int) $val;
                } else {
                    throw new Exception("Malformed pagination metadata: {$key}.");
                }

                if ($intVal < $min) {
                    throw new Exception("Pagination metadata {$key} out of range.");
                }
                if ($max !== null && $intVal > $max) {
                    throw new Exception("Pagination metadata {$key} out of range.");
                }

                return $intVal;
            };

            $count = $parseMetadataInt('Count', $data);
            $totalCount = $parseMetadataInt('TotalCount', $data);
            $resLimit = $parseMetadataInt('Limit', $data, 1, 100);
            $resOffset = $parseMetadataInt('Offset', $data);

            if (! array_key_exists('HasMore', $data)) {
                throw new Exception('Missing pagination metadata: HasMore.');
            }
            $hasMoreRaw = $data['HasMore'];
            if ($hasMoreRaw === 0 || $hasMoreRaw === '0') {
                $hasMore = false;
            } elseif ($hasMoreRaw === 1 || $hasMoreRaw === '1') {
                $hasMore = true;
            } else {
                throw new Exception('Malformed pagination metadata: HasMore.');
            }

            return [
                'companies' => $companies,
                'count' => $count,
                'total_count' => $totalCount,
                'limit' => $resLimit,
                'offset' => $resOffset,
                'has_more' => $hasMore,
            ];
        });
    }
}
