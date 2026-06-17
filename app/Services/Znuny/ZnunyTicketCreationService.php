<?php

namespace App\Services\Znuny;

class ZnunyTicketCreationService
{
    public function __construct(
        protected ZnunyClient $client
    ) {}

    /**
     * @return array{
     *   valid: bool,
     *   errors: array<int, string>,
     *   warnings: array<int, string>
     * }
     */
    public function validateTicketPayload(
        int|string $ownerId,
        string $queue,
        string $customerUser,
        string $title,
        string $articleSubject,
        string $articleBody
    ): array {
        if (empty(trim((string) $ownerId)) || empty(trim($queue)) || empty(trim($customerUser))) {
            return [
                'valid' => false,
                'errors' => ['Missing required Owner, Queue, or CustomerUser.'],
                'warnings' => [],
            ];
        }

        if (empty(trim($title))) {
            return [
                'valid' => false,
                'errors' => ['Ticket title is required.'],
                'warnings' => [],
            ];
        }

        if (empty(trim($articleBody))) {
            return [
                'valid' => false,
                'errors' => ['Ticket article body is required.'],
                'warnings' => [],
            ];
        }

        $payload = [
            'Ticket' => [
                'Title' => $title,
                'Queue' => $queue,
                'CustomerUser' => $customerUser,
                'State' => 'new',
                'Lock' => 'lock',
                'OwnerID' => (int) $ownerId,
            ],
            'Article' => [
                'Subject' => $articleSubject,
                'Body' => $articleBody,
                'ContentType' => 'text/plain; charset=utf8',
            ],
        ];

        try {
            $response = $this->client->validateTicketCreate($payload);

            return [
                'valid' => ! empty($response['valid']),
                'errors' => $response['errors'] ?? [],
                'warnings' => $response['warnings'] ?? [],
            ];
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'errors' => [$e->getMessage()],
                'warnings' => [],
            ];
        }
    }
}
