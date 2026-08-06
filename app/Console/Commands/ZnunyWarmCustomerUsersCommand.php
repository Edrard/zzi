<?php

namespace App\Console\Commands;

use App\Enums\ZnunyPrewarmRefreshResult;
use App\Services\SettingsService;
use App\Services\Znuny\Cache\PrewarmSnapshotManager;
use App\Services\Znuny\Cache\ZnunyQueueCacheReadService;
use App\Services\Znuny\ZnunyClient;
use Illuminate\Console\Command;
use Throwable;

class ZnunyWarmCustomerUsersCommand extends Command
{
    protected $signature = 'znuny:cache:warm-customer-users';
    protected $description = 'Warm up Znuny customer users per prewarmed queue';

    public function handle(ZnunyClient $client, ZnunyQueueCacheReadService $queueService): int
    {
        $manager = new PrewarmSnapshotManager('customer_users');
        $intervalMinutes = max(3, \App\Services\SettingsService::int('znuny_prewarm_customer_users_interval_minutes', 30));

        $result = $manager->refresh(
            function () use ($client, $queueService) {
                $snapshot = $queueService->getSnapshot();
                if (! $snapshot || empty($snapshot['payload']) || ! is_array($snapshot['payload'])) {
                    throw new \Exception('Missing, expired, empty, or malformed queue snapshot.');
                }

                $queues = $snapshot['payload'];
                $mappings = app(SettingsService::class)->json('znuny_queue_host_mappings', []);
                if (! is_array($mappings)) {
                    $mappings = [];
                }

                $finalQueues = [];
                $seenQueueIds = [];
                $seenQueueNames = [];
                $totalItemCount = 0;

                foreach ($queues as $queue) {
                    if (! is_array($queue) || ! isset($queue['id']) || ! isset($queue['name'])) {
                        throw new \Exception('Malformed queue entry in snapshot.');
                    }

                    if (! $this->isValidId($queue['id'])) {
                        throw new \Exception('Malformed queue ID in snapshot.');
                    }

                    $qId = (int) $queue['id'];
                    $qName = trim((string) $queue['name']);

                    if ($qName === '') {
                        throw new \Exception('Empty queue name in snapshot.');
                    }

                    if (isset($seenQueueIds[$qId])) {
                        throw new \Exception("Duplicate queue ID detected: {$qId}.");
                    }
                    if (isset($seenQueueNames[$qName])) {
                        throw new \Exception("Duplicate queue name detected: {$qName}.");
                    }

                    $seenQueueIds[$qId] = true;
                    $seenQueueNames[$qName] = true;

                    $searchTerms = $this->buildSearchTerms($queue, $mappings);

                    $options = [];
                    foreach ($searchTerms as $term) {
                        if (count($options) >= 50) {
                            break;
                        }

                        $results = $client->searchCustomerUsers($term, 50);
                        if (! is_array($results)) {
                            throw new \Exception('Malformed customer user result: not an array.');
                        }

                        foreach ($results as $row) {
                            if (! is_array($row) || ! isset($row['login'])) {
                                throw new \Exception('Malformed customer user row.');
                            }
                            if (! is_string($row['login'])) {
                                throw new \Exception('Malformed customer user row: login must be a string.');
                            }

                            $login = trim($row['login']);
                            if ($login === '') {
                                throw new \Exception('Customer user row has empty login.');
                            }

                            if (isset($options[$login])) {
                                continue; // First duplicate login wins
                            }

                            $label = $login;
                            if (array_key_exists('label', $row) && $row['label'] !== null) {
                                if (! is_string($row['label'])) {
                                    throw new \Exception('Malformed customer user row: label must be a string.');
                                }
                                $trimmedLabel = trim($row['label']);
                                if ($trimmedLabel !== '') {
                                    $label = $trimmedLabel;
                                }
                            }

                            $options[$login] = [
                                'login' => $login,
                                'label' => $label,
                            ];

                            if (count($options) >= 50) {
                                break;
                            }
                        }
                    }

                    // Sort options
                    uasort($options, function ($a, $b) {
                        $cmp = strnatcasecmp($a['label'], $b['label']);
                        if ($cmp === 0) {
                            $cmp = strcmp($a['label'], $b['label']);
                            if ($cmp === 0) {
                                return strcmp($a['login'], $b['login']);
                            }
                        }
                        return $cmp;
                    });

                    // Format options map
                    $formattedOptions = [];
                    foreach ($options as $opt) {
                        $formattedOptions[$opt['login']] = $opt['label'];
                    }

                    $totalItemCount += count($formattedOptions);

                    $finalQueues[] = [
                        'queue_id' => $qId,
                        'queue_name' => $qName,
                        'search_terms' => $searchTerms,
                        'options' => $formattedOptions,
                    ];
                }

                usort($finalQueues, function ($a, $b) {
                    $cmp = strnatcasecmp($a['queue_name'], $b['queue_name']);
                    if ($cmp === 0) {
                        $cmp = strcmp($a['queue_name'], $b['queue_name']);
                        if ($cmp === 0) {
                            return $a['queue_id'] <=> $b['queue_id'];
                        }
                    }
                    return $cmp;
                });

                return [
                    'payload' => [
                        'queues' => $finalQueues,
                    ],
                    'item_count' => $totalItemCount,
                ];
            },
            'artisan',
            $intervalMinutes
        );

        if ($result === ZnunyPrewarmRefreshResult::SKIPPED_LOCKED) {
            $this->warn('Customer users warmup skipped: Another refresh is already running.');
            $this->line('PREWARM_RESULT=skipped_locked');
            return self::SUCCESS;
        }

        if ($result === ZnunyPrewarmRefreshResult::FAILED) {
            $this->error($this->getSafeFailureMessage($manager));
            $this->line('PREWARM_RESULT=failed');
            return self::FAILURE;
        }

        $this->info('Successfully warmed Znuny customer users.');
        $this->line('PREWARM_RESULT=success');
        return self::SUCCESS;
    }

    private function getSafeFailureMessage(PrewarmSnapshotManager $manager): string
    {
        try {
            $meta = $manager->readMetadata();
            if (! empty($meta['last_error']) && is_string($meta['last_error'])) {
                $error = trim($meta['last_error']);
                if ($error !== '') {
                    return $error;
                }
            }
        } catch (Throwable $e) {
            // Ignore metadata read exception
        }

        return 'Refresh failed; see application logs.';
    }

    private function buildSearchTerms(array $queue, array $mappings): array
    {
        $terms = [];
        $qName = trim((string) $queue['name']);
        if ($qName !== '') {
            $terms[] = $qName;
        }

        if (isset($queue['label'])) {
            $label = trim((string) $queue['label']);
            if ($label !== '' && ! $this->inTermsCaseInsensitive($label, $terms)) {
                $terms[] = $label;
            }
        }

        if (isset($queue['full_name'])) {
            $fullName = trim((string) $queue['full_name']);
            if ($fullName !== '' && ! $this->inTermsCaseInsensitive($fullName, $terms)) {
                $terms[] = $fullName;
            }
        }

        foreach ($mappings as $mapping) {
            if (! is_array($mapping)) {
                continue;
            }

            $mq = null;
            $keys = ['queue', 'queue_name', 'znuny_queue', 'znuny_queue_name'];
            foreach ($keys as $k) {
                if (isset($mapping[$k]) && is_string($mapping[$k])) {
                    $mq = trim($mapping[$k]);
                    break;
                }
            }

            if ($mq !== null && $mq !== '') {
                // Compare case-insensitively against name, label, full name
                $match = false;
                if (strcasecmp($mq, $qName) === 0) {
                    $match = true;
                } elseif (isset($queue['label']) && strcasecmp($mq, trim((string) $queue['label'])) === 0) {
                    $match = true;
                } elseif (isset($queue['full_name']) && strcasecmp($mq, trim((string) $queue['full_name'])) === 0) {
                    $match = true;
                }

                if ($match) {
                    $prefix = null;
                    if (isset($mapping['host_prefix']) && is_string($mapping['host_prefix'])) {
                        $prefix = trim($mapping['host_prefix']);
                    } elseif (isset($mapping['prefix']) && is_string($mapping['prefix'])) {
                        $prefix = trim($mapping['prefix']);
                    }

                    if ($prefix !== null && $prefix !== '' && ! $this->inTermsCaseInsensitive($prefix, $terms)) {
                        $terms[] = $prefix;
                    }
                }
            }
        }

        $finalTerms = [];
        foreach ($terms as $t) {
            if (mb_strlen($t) >= 2) {
                $finalTerms[] = $t;
            }
        }

        return $finalTerms;
    }

    private function inTermsCaseInsensitive(string $search, array $terms): bool
    {
        foreach ($terms as $t) {
            if (strcasecmp($t, $search) === 0) {
                return true;
            }
        }
        return false;
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
