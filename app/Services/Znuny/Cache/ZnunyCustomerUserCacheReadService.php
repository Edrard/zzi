<?php

namespace App\Services\Znuny\Cache;

class ZnunyCustomerUserCacheReadService
{
    private PrewarmSnapshotManager $snapshotManager;

    public function __construct(?PrewarmSnapshotManager $snapshotManager = null)
    {
        $this->snapshotManager = $snapshotManager ?? new PrewarmSnapshotManager('customer_users');
    }

    public function getSnapshot(): ?array
    {
        $snapshot = $this->snapshotManager->readActiveSnapshot();

        if (! $snapshot || ! isset($snapshot['payload']) || ! is_array($snapshot['payload'])) {
            return null;
        }

        $payload = $snapshot['payload'];

        if (count($payload) !== 1 || ! isset($payload['queues']) || ! is_array($payload['queues']) || ! array_is_list($payload['queues']) || empty($payload['queues'])) {
            return null;
        }

        $seenIds = [];
        $seenNames = [];

        foreach ($payload['queues'] as $q) {
            if (! is_array($q)) {
                return null;
            }

            if (! isset($q['queue_id']) || ! $this->isValidId($q['queue_id'])) {
                return null;
            }
            $qId = (int) $q['queue_id'];

            if (! isset($q['queue_name']) || ! is_string($q['queue_name'])) {
                return null;
            }
            $qName = $q['queue_name'];
            if (trim($qName) === '' || trim($qName) !== $qName) {
                return null; // normalized string
            }

            if (isset($seenIds[$qId]) || isset($seenNames[$qName])) {
                return null;
            }
            $seenIds[$qId] = true;
            $seenNames[$qName] = true;

            if (! isset($q['search_terms']) || ! is_array($q['search_terms']) || ! array_is_list($q['search_terms'])) {
                return null;
            }
            $seenTerms = [];
            foreach ($q['search_terms'] as $term) {
                if (! is_string($term) || trim($term) === '' || trim($term) !== $term || mb_strlen($term) < 2) {
                    return null;
                }
                $lower = mb_strtolower($term);
                if (isset($seenTerms[$lower])) {
                    return null;
                }
                $seenTerms[$lower] = true;
            }

            if (! isset($q['options']) || ! is_array($q['options'])) {
                return null;
            }
            if (count($q['options']) > 50) {
                return null;
            }

            foreach ($q['options'] as $key => $label) {
                if (! is_string($key) && ! is_int($key)) {
                    return null;
                }
                $normalizedKey = (string) $key;
                if (! is_string($label)) {
                    return null;
                }

                if (trim($normalizedKey) === '' || trim($normalizedKey) !== $normalizedKey) {
                    return null;
                }
                if (trim($label) === '' || trim($label) !== $label) {
                    return null;
                }
            }
        }

        return [
            'generation' => $snapshot['generation'],
            'queues' => $payload['queues'],
            'metadata' => $snapshot['metadata'],
        ];
    }

    public function getOptionsForQueue(string $queueName): array
    {
        $queueName = trim($queueName);
        $snapshot = $this->getSnapshot();
        if (! $snapshot) {
            return [];
        }

        foreach ($snapshot['queues'] as $q) {
            // Case-sensitive exact match required
            if ($q['queue_name'] === $queueName) {
                return $q['options'];
            }
        }

        return [];
    }

    public function getSearchTermsForQueue(string $queueName): array
    {
        $queueName = trim($queueName);
        $snapshot = $this->getSnapshot();
        if (! $snapshot) {
            return [];
        }

        foreach ($snapshot['queues'] as $q) {
            if ($q['queue_name'] === $queueName) {
                return $q['search_terms'];
            }
        }

        return [];
    }

    public function getMetadata(): array
    {
        return $this->snapshotManager->readMetadata();
    }

    private function isValidId($id): bool
    {
        if (is_int($id) && $id > 0) {
            return true;
        }
        if (is_string($id) && preg_match('/^[1-9][0-9]*$/', $id)) {
            return true;
        }
        return false;
    }
}
