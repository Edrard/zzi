<?php

namespace App\Services\Znuny\Cache;

class ZnunyLookupCacheReadService
{
    private PrewarmSnapshotManager $snapshotManager;

    public function __construct(?PrewarmSnapshotManager $snapshotManager = null)
    {
        $this->snapshotManager = $snapshotManager ?? new PrewarmSnapshotManager('lookups');
    }

    public function getSnapshot(): ?array
    {
        $snapshot = $this->snapshotManager->readActiveSnapshot();

        if (! $snapshot || ! isset($snapshot['payload']) || ! is_array($snapshot['payload'])) {
            return null;
        }

        $payload = $snapshot['payload'];

        if (! $this->isValidOptionMapCategory($payload, 'states')) {
            return null;
        }

        if (! $this->isValidOptionMapCategory($payload, 'priorities')) {
            return null;
        }

        if (! $this->isValidOptionMapCategory($payload, 'types')) {
            return null;
        }

        return [
            'generation' => $snapshot['generation'],
            'states' => $payload['states'],
            'priorities' => $payload['priorities'],
            'types' => $payload['types'],
            'customer_companies' => $this->getValidCustomerCompanies($payload),
            'metadata' => $snapshot['metadata'],
        ];
    }

    private function getValidCustomerCompanies(array $payload): array
    {
        if (!isset($payload['customer_companies']) || !is_array($payload['customer_companies'])) {
            return [];
        }

        foreach ($payload['customer_companies'] as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                return [];
            }
            if ((string)$key === '') {
                return [];
            }
            if (!is_string($value) || $value === '') {
                return [];
            }
        }

        return $payload['customer_companies'];
    }

    public function getStates(): array
    {
        $snapshot = $this->getSnapshot();
        return $snapshot !== null ? $snapshot['states'] : [];
    }

    public function getPriorities(): array
    {
        $snapshot = $this->getSnapshot();
        return $snapshot !== null ? $snapshot['priorities'] : [];
    }

    public function getTypes(): array
    {
        $snapshot = $this->getSnapshot();
        return $snapshot !== null ? $snapshot['types'] : [];
    }

    public function getCustomerCompanies(): array
    {
        $snapshot = $this->getSnapshot();
        return $snapshot !== null ? $snapshot['customer_companies'] : [];
    }

    public function hasCustomerCompany(string $customerId): bool
    {
        return $customerId !== ''
            && array_key_exists($customerId, $this->getCustomerCompanies());
    }

    public function getMetadata(): array
    {
        return $this->snapshotManager->readMetadata();
    }

    private function isValidOptionMapCategory(array $payload, string $category): bool
    {
        if (! isset($payload[$category]) || ! is_array($payload[$category]) || empty($payload[$category])) {
            return false;
        }

        foreach ($payload[$category] as $key => $value) {
            if (! is_string($key) && ! is_int($key)) {
                return false;
            }

            if (! is_string($value)) {
                return false;
            }

            $normalizedKey = (string) $key;

            if (trim($normalizedKey) === '' || trim($value) === '') {
                return false;
            }

            if (trim($normalizedKey) !== trim($value)) {
                return false;
            }

            // Validate already normalized without surrounding whitespace
            if ($normalizedKey !== trim($normalizedKey) || $value !== trim($value)) {
                return false;
            }
        }

        return true;
    }
}
