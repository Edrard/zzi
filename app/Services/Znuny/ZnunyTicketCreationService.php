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
    public function validateTicketPayload(int|string $ownerId, string $queue, string $customerUser): array
    {
        $payload = [
            'OwnerID' => (int) $ownerId,
            'Queue' => $queue,
            'CustomerUser' => $customerUser,
            'State' => 'new',
            'Lock' => 'lock',
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
