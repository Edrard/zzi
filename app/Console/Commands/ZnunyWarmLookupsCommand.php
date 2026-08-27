<?php

namespace App\Console\Commands;

use App\Enums\ZnunyPrewarmRefreshResult;
use App\Services\Znuny\Cache\PrewarmSnapshotManager;
use App\Services\Znuny\ZnunyClient;
use Illuminate\Console\Command;
use Throwable;

class ZnunyWarmLookupsCommand extends Command
{
    protected $signature = 'znuny:cache:warm-lookups';
    protected $description = 'Warm up Znuny lookups (states, priorities, types)';

    public function handle(ZnunyClient $client): int
    {
        $manager = new PrewarmSnapshotManager('lookups');

        $intervalMinutes = max(3, \App\Services\SettingsService::int('znuny_prewarm_lookups_interval_minutes', 60));

        $result = $manager->refresh(
            function () use ($client) {
                $rawStates = $client->getTicketStates();
                $rawPriorities = $client->getTicketPriorities();
                $rawTypes = $client->getTicketTypes();

                $states = $this->normalizeCategory($rawStates, 'states');
                $priorities = $this->normalizeCategory($rawPriorities, 'priorities');
                $types = $this->normalizeCategory($rawTypes, 'types');

                $customerCompanies = [];
                $offset = 0;
                $limit = (int) config('znuny.customer_company_page_size', 100);

                $expectedTotalCount = null;

                while (true) {
                    $page = $client->getCustomerCompaniesPage($offset, $limit);

                    if ($page['offset'] !== $offset) {
                        throw new \Exception("Pagination error: returned offset mismatch.");
                    }
                    if (count($page['companies']) !== $page['count']) {
                        throw new \Exception("Pagination error: returned companies array size mismatch with Count.");
                    }
                    if ($page['limit'] !== $limit) {
                        throw new \Exception("Pagination error: returned Limit mismatch.");
                    }

                    if ($expectedTotalCount === null) {
                        $expectedTotalCount = $page['total_count'];
                    } elseif ($expectedTotalCount !== $page['total_count']) {
                        throw new \Exception("Pagination error: TotalCount changed during iteration.");
                    }

                    if ($offset + $page['count'] > $expectedTotalCount) {
                        throw new \Exception("Pagination error: offset + count exceeds TotalCount.");
                    }

                    foreach ($page['companies'] as $c) {
                        $id = $c['customer_id'];
                        if (isset($customerCompanies[$id])) {
                            throw new \Exception("Duplicate CustomerID detected: '{$id}'.");
                        }
                        $customerCompanies[$id] = $c['name'];
                    }

                    if ($page['has_more']) {
                        if ($page['count'] === 0) {
                            throw new \Exception("Pagination error: HasMore=1 but Count=0.");
                        }
                        if ($offset + $page['count'] >= $expectedTotalCount) {
                            throw new \Exception("Pagination error: HasMore=1 but offset + count >= TotalCount.");
                        }
                        $offset += $page['count'];
                    } else {
                        if ($offset + $page['count'] !== $expectedTotalCount) {
                            throw new \Exception("Pagination error: HasMore=0 but offset + count != TotalCount.");
                        }
                        break;
                    }
                }

                return [
                    'payload' => [
                        'states' => $states,
                        'priorities' => $priorities,
                        'types' => $types,
                        'customer_companies' => $customerCompanies,
                    ],
                    'item_count' => count($states) + count($priorities) + count($types) + count($customerCompanies),
                ];
            },
            'artisan',
            $intervalMinutes
        );

        if ($result === ZnunyPrewarmRefreshResult::SKIPPED_LOCKED) {
            $this->warn('Lookups warmup skipped because another process holds the lock.');
            $this->line('PREWARM_RESULT=skipped_locked');
            return self::SUCCESS;
        }

        if ($result === ZnunyPrewarmRefreshResult::FAILED) {
            $this->error($this->getSafeLastError($manager));
            $this->line('PREWARM_RESULT=failed');
            return self::FAILURE;
        }

        $this->info('Successfully warmed up lookups dataset.');
        $this->line('PREWARM_RESULT=success');
        return self::SUCCESS;
    }

    private function getSafeLastError(PrewarmSnapshotManager $manager): string
    {
        try {
            $meta = $manager->readMetadata();
            if (! empty($meta['last_error']) && is_string($meta['last_error']) && trim($meta['last_error']) !== '') {
                return trim($meta['last_error']);
            }
        } catch (Throwable $e) {
            // Ignore metadata read failure during error reporting
        }

        return 'Refresh failed; see application logs.';
    }

    private function normalizeCategory(mixed $rawItems, string $categoryName): array
    {
        if (! is_array($rawItems)) {
            throw new \Exception("Raw {$categoryName} must be an array.");
        }

        if (array_key_exists('Data', $rawItems)) {
            if (! is_array($rawItems['Data'])) {
                throw new \Exception("Malformed Data wrapper in {$categoryName}: must be an array.");
            }
            $rawItems = $rawItems['Data'];
        } elseif (array_key_exists('data', $rawItems)) {
            if (! is_array($rawItems['data'])) {
                throw new \Exception("Malformed data wrapper in {$categoryName}: must be an array.");
            }
            $rawItems = $rawItems['data'];
        }

        $normalized = [];

        foreach ($rawItems as $item) {
            $scalarValue = null;

            if (is_string($item) || is_int($item) || is_float($item)) {
                $scalarValue = $item;
            } elseif (is_array($item)) {
                $keys = ['name', 'Name', 'label', 'Label', 'value', 'Value'];
                foreach ($keys as $k) {
                    if (isset($item[$k]) && (is_string($item[$k]) || is_int($item[$k]) || is_float($item[$k]))) {
                        $scalarValue = $item[$k];
                        break;
                    }
                }
            }

            if ($scalarValue === null) {
                throw new \Exception("Malformed item in {$categoryName} without recognizable scalar value.");
            }

            $trimmed = trim((string) $scalarValue);
            if ($trimmed === '') {
                throw new \Exception("Blank normalized value in {$categoryName}.");
            }

            if (isset($normalized[$trimmed])) {
                throw new \Exception("Duplicate normalized value '{$trimmed}' inside category {$categoryName}.");
            }

            $normalized[$trimmed] = $trimmed;
        }

        if (empty($normalized)) {
            throw new \Exception("Empty normalized category: {$categoryName}");
        }

        uksort($normalized, function ($a, $b) {
            $cmp = strnatcasecmp($a, $b);
            if ($cmp === 0) {
                return strcmp($a, $b);
            }
            return $cmp;
        });

        // Ensure keys and values are properly aligned after sort, just in case
        $finalAssoc = [];
        foreach ($normalized as $key => $val) {
            $finalAssoc[$key] = $val;
        }

        return $finalAssoc;
    }
}
