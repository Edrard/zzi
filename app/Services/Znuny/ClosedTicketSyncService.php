<?php

namespace App\Services\Znuny;

use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;

class ClosedTicketSyncService
{
    private const LOCK_KEY = 'znuny:closed_ticket:sync:lock';

    public function __construct(
        private ClosedTicketCacheService $cacheService,
        private ZnunyClient $znunyClient
    ) {}

    public function getSettings(): array
    {
        $windowDays = SettingsService::int('znuny_closed_ticket_window_days', 30) ?? 30;
        if ($windowDays < 1) {
            $windowDays = 30;
        }

        $smallSyncInterval = SettingsService::int('znuny_closed_ticket_small_sync_interval_minutes', 5) ?? 5;
        if ($smallSyncInterval < 1) {
            $smallSyncInterval = 5;
        }

        return [
            'window_days' => $windowDays,
            'small_sync_interval' => $smallSyncInterval,
            'auto_audit_enabled' => SettingsService::bool('znuny_closed_ticket_sync_audit_auto_enabled', false) ?? false,
        ];
    }

    public function syncAuto(): array
    {
        return $this->runWithLock(function () {
            $settings = $this->getSettings();
            $windowDays = $settings['window_days'];

            $validation = $this->cacheService->validateMetadata($windowDays);

            if ($validation['is_valid']) {
                $metadata = $this->cacheService->getMetadata() ?? [];
                $lastSuccess = $metadata['last_small_completed_at'] ?? null;
                $smallSyncInterval = $settings['small_sync_interval'];

                if ($lastSuccess) {
                    $minutesSinceLast = (time() - strtotime($lastSuccess)) / 60;
                    if ($minutesSinceLast < $smallSyncInterval) {
                        $result = [
                            'mode' => 'auto',
                            'effective_mode' => 'skipped',
                            'reason' => 'interval_not_due',
                            'window_days' => $windowDays,
                            'lookback_minutes' => (int) max(2 * $smallSyncInterval, $minutesSinceLast + 1),
                            'fetched_count' => 0,
                            'cached_count' => 0,
                        ];

                        $this->auditIfApplicable($result);

                        return $result;
                    }
                }

                return $this->runSmallSync('auto', 'scheduled');
            } else {
                return $this->runFullSync('auto', $validation['reason']);
            }
        }, 'auto');
    }

    public function syncManual(): array
    {
        return $this->runWithLock(function () {
            return $this->runSmallSync('manual', 'manual');
        }, 'manual');
    }

    public function syncFull(string $invokedMode = 'full', string $reason = 'forced_full'): array
    {
        return $this->runWithLock(function () use ($invokedMode, $reason) {
            return $this->runFullSync($invokedMode, $reason);
        }, $invokedMode);
    }

    private function runWithLock(callable $callback, string $mode): array
    {
        if (! Cache::add(self::LOCK_KEY, true, now()->addMinutes(30))) {
            $result = [
                'mode' => $mode,
                'effective_mode' => 'skipped',
                'reason' => 'locked',
                'window_days' => $this->getSettings()['window_days'],
                'fetched_count' => 0,
                'cached_count' => 0,
            ];
            $this->auditIfApplicable($result);

            return $result;
        }

        try {
            return $callback();
        } finally {
            Cache::forget(self::LOCK_KEY);
        }
    }

    private function runSmallSync(string $mode, string $reason): array
    {
        $startTime = microtime(true);
        $settings = $this->getSettings();
        $windowDays = $settings['window_days'];
        $smallSyncInterval = $settings['small_sync_interval'];
        $metadata = $this->cacheService->getMetadata() ?? [];

        $lastSuccess = $metadata['last_small_completed_at'] ?? null;
        $minutesSinceLast = $lastSuccess ? (time() - strtotime($lastSuccess)) / 60 : 0;

        $lookbackMinutes = (int) max(2 * $smallSyncInterval, $minutesSinceLast + 1);

        $limit = 100;
        $fetchedCount = 0;
        $cachedCount = 0;
        $offset = 0;
        $maxPages = 1000;
        $pageCount = 0;
        $allFetchedIds = [];

        $boundaryTimestamp = time() - ($lookbackMinutes * 60);

        try {
            while (true) {
                if ($pageCount >= $maxPages) {
                    throw new \Exception("Max pages limit ({$maxPages}) exceeded during small sync.");
                }
                $pageCount++;

                $tickets = $this->znunyClient->searchTickets([
                    'StateType' => 'closed',
                    'SortBy' => 'Changed',
                    'SortDirection' => 'Down',
                    'Limit' => $limit,
                    'Offset' => $offset,
                ]);

                if (empty($tickets)) {
                    break;
                }

                $pageTicketIds = array_column($tickets, 'TicketID');
                $newTicketIds = array_diff($pageTicketIds, $allFetchedIds);
                if (empty($newTicketIds)) {
                    throw new \Exception('Repeated closed-ticket search page detected before sync completion.');
                }
                foreach ($newTicketIds as $id) {
                    $allFetchedIds[] = $id;
                }

                $fetchedCount += count($tickets);

                $oldestInPage = time();
                foreach ($tickets as $ticket) {
                    $changedAt = $ticket['Changed'] ?? $ticket['Created'] ?? date('Y-m-d H:i:s');
                    $timestamp = strtotime($changedAt);

                    if ($timestamp < $oldestInPage) {
                        $oldestInPage = $timestamp;
                    }

                    $this->cacheService->upsertTicket($ticket, $windowDays * 6);
                    $cachedCount++;
                }

                if ($oldestInPage < $boundaryTimestamp || count($tickets) < $limit) {
                    break;
                }

                $offset += $limit;
            }

            $metadata['last_small_completed_at'] = now()->toDateTimeString();
            $metadata['last_mode'] = 'small';
            $metadata['last_reason'] = $reason;
            $metadata['last_run_started_at'] = date('Y-m-d H:i:s', (int) $startTime);
            $metadata['last_run_completed_at'] = now()->toDateTimeString();
            unset($metadata['last_error']);
            $this->cacheService->setMetadata($metadata);

            $result = [
                'mode' => $mode,
                'effective_mode' => 'small',
                'reason' => $reason,
                'window_days' => $windowDays,
                'retention_days' => $windowDays * 6,
                'lookback_minutes' => $lookbackMinutes,
                'fetched_count' => $fetchedCount,
                'cached_count' => $cachedCount,
                'duration_ms' => round((microtime(true) - $startTime) * 1000),
                'metadata_status' => $metadata['integrity_status'] ?? 'incomplete',
            ];

            $this->auditIfApplicable($result);

            return $result;

        } catch (\Throwable $e) {
            $metadata['last_error'] = $e->getMessage();
            $this->cacheService->setMetadata($metadata);

            $result = [
                'mode' => $mode,
                'effective_mode' => 'small',
                'reason' => $reason,
                'window_days' => $windowDays,
                'fetched_count' => $fetchedCount,
                'cached_count' => $cachedCount,
                'duration_ms' => round((microtime(true) - $startTime) * 1000),
                'metadata_status' => $metadata['integrity_status'] ?? 'incomplete',
                'error_message' => $e->getMessage(),
            ];

            $this->auditIfApplicable($result);

            return $result;
        }
    }

    private function runFullSync(string $mode, string $reason): array
    {
        $startTime = microtime(true);
        $settings = $this->getSettings();
        $windowDays = $settings['window_days'];
        $metadata = $this->cacheService->getMetadata() ?? [];

        $limit = 100;
        $fetchedCount = 0;
        $cachedCount = 0;
        $offset = 0;
        $maxPages = 1000;
        $pageCount = 0;
        $allFetchedIds = [];

        $boundaryTimestamp = time() - ($windowDays * 86400);

        $newestLoaded = null;
        $oldestLoaded = null;
        $exhausted = false;

        try {
            while (true) {
                if ($pageCount >= $maxPages) {
                    throw new \Exception("Max pages limit ({$maxPages}) exceeded during full sync.");
                }
                $pageCount++;

                $tickets = $this->znunyClient->searchTickets([
                    'StateType' => 'closed',
                    'SortBy' => 'Changed',
                    'SortDirection' => 'Down',
                    'Limit' => $limit,
                    'Offset' => $offset,
                ]);

                if (empty($tickets)) {
                    $exhausted = true;
                    break;
                }

                $pageTicketIds = array_column($tickets, 'TicketID');
                $newTicketIds = array_diff($pageTicketIds, $allFetchedIds);
                if (empty($newTicketIds)) {
                    throw new \Exception('Repeated closed-ticket search page detected before sync completion.');
                }
                foreach ($newTicketIds as $id) {
                    $allFetchedIds[] = $id;
                }

                $fetchedCount += count($tickets);

                $oldestInPage = time();
                foreach ($tickets as $ticket) {
                    $changedAt = $ticket['Changed'] ?? $ticket['Created'] ?? date('Y-m-d H:i:s');
                    $timestamp = strtotime($changedAt);

                    if ($newestLoaded === null || $timestamp > $newestLoaded) {
                        $newestLoaded = $timestamp;
                    }

                    if ($oldestLoaded === null || $timestamp < $oldestLoaded) {
                        $oldestLoaded = $timestamp;
                    }

                    if ($timestamp < $oldestInPage) {
                        $oldestInPage = $timestamp;
                    }

                    $this->cacheService->upsertTicket($ticket, $windowDays * 6);
                    $cachedCount++;
                }

                if ($oldestInPage < $boundaryTimestamp) {
                    break;
                }

                if (count($tickets) < $limit) {
                    $exhausted = true;
                    break;
                }

                $offset += $limit;
            }

            if ($exhausted && ($oldestLoaded === null || $oldestLoaded > $boundaryTimestamp)) {
                $oldestLoaded = $boundaryTimestamp;
            }

            $metadata['window_days'] = $windowDays;
            $metadata['retention_days'] = $windowDays * 6;
            $metadata['last_full_completed_at'] = now()->toDateTimeString();
            $metadata['last_small_completed_at'] = now()->toDateTimeString();

            if ($oldestLoaded !== null) {
                $metadata['oldest_loaded_closed_at'] = date('Y-m-d H:i:s', $oldestLoaded);
            }

            if ($newestLoaded !== null) {
                $metadata['newest_loaded_closed_at'] = date('Y-m-d H:i:s', $newestLoaded);
            } else {
                unset($metadata['newest_loaded_closed_at']);
            }

            $metadata['integrity_status'] = 'complete';
            $metadata['last_mode'] = 'full';
            $metadata['last_reason'] = $reason;
            $metadata['last_run_started_at'] = date('Y-m-d H:i:s', (int) $startTime);
            $metadata['last_run_completed_at'] = now()->toDateTimeString();
            unset($metadata['last_error']);

            $this->cacheService->setMetadata($metadata);

            $result = [
                'mode' => $mode,
                'effective_mode' => 'full',
                'reason' => $reason,
                'window_days' => $windowDays,
                'retention_days' => $windowDays * 6,
                'fetched_count' => $fetchedCount,
                'cached_count' => $cachedCount,
                'duration_ms' => round((microtime(true) - $startTime) * 1000),
                'metadata_status' => 'complete',
            ];

            $this->auditIfApplicable($result);

            return $result;

        } catch (\Throwable $e) {
            $metadata['last_error'] = $e->getMessage();
            $this->cacheService->setMetadata($metadata);

            $result = [
                'mode' => $mode,
                'effective_mode' => 'full',
                'reason' => $reason,
                'window_days' => $windowDays,
                'fetched_count' => $fetchedCount,
                'cached_count' => $cachedCount,
                'duration_ms' => round((microtime(true) - $startTime) * 1000),
                'metadata_status' => $metadata['integrity_status'] ?? 'incomplete',
                'error_message' => $e->getMessage(),
            ];

            $this->auditIfApplicable($result);

            return $result;
        }
    }

    private function auditIfApplicable(array $result): void
    {
        $settings = $this->getSettings();

        $shouldAudit = false;
        if ($result['mode'] === 'manual') {
            $shouldAudit = true;
        } elseif ($settings['auto_audit_enabled']) {
            $shouldAudit = true;
        }

        if (! $shouldAudit) {
            return;
        }

        $user = auth()->user();

        AuditLogger::log('znuny.closed_ticket.sync', null, null, $result, $user);
    }
}
