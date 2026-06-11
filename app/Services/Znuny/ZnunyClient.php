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
}
