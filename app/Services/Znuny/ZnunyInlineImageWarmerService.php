<?php

namespace App\Services\Znuny;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class ZnunyInlineImageWarmerService
{
    private ZnunyClient $client;

    private ZnunyInlineImageService $inlineImageService;

    private ZnunyTicketWorkspaceStateTypeMapper $stateTypeMapper;

    private ZnunyTicketWorkspaceCacheReader $workspaceReader;

    private const TAIL_OFFSET_KEY = 'znuny:inline_image_warmer:tail_offset';

    private const LAST_RUN_KEY = 'znuny:inline_image_warmer:last_run_at';

    private const LAST_STARTED_KEY = 'znuny:inline_image_warmer:last_started_at';

    private const RUNNING_LOCK_KEY = 'znuny:inline_image_warmer:running';

    public function __construct(
        ZnunyClient $client,
        ZnunyInlineImageService $inlineImageService,
        ZnunyTicketWorkspaceStateTypeMapper $stateTypeMapper,
        ZnunyTicketWorkspaceCacheReader $workspaceReader
    ) {
        $this->client = $client;
        $this->inlineImageService = $inlineImageService;
        $this->stateTypeMapper = $stateTypeMapper;
        $this->workspaceReader = $workspaceReader;
    }

    public function warm(): array
    {
        if (! SettingsService::bool('znuny_inline_image_warmer_enabled')) {
            return $this->result(0, 0, 0, 0, 0, 0, 'warmer disabled');
        }

        // We use an arbitrary TTL (e.g. 1 hour) for crash safety on the lock itself.
        // In normal operations, this is cleared precisely in the finally block.
        if (! Redis::set(self::RUNNING_LOCK_KEY, time(), 'EX', 3600, 'NX')) {
            return $this->result(0, 0, 0, 0, 0, 0, 'already running');
        }

        try {
            Redis::set(self::LAST_STARTED_KEY, time());

            $activeStateTypeIdsJson = SettingsService::string('znuny_ticket_workspace_active_state_type_ids', '[]');
            $activeStateTypeIds = json_decode($activeStateTypeIdsJson, true) ?? [];

            if (empty($activeStateTypeIds) || ! is_array($activeStateTypeIds)) {
                return $this->result(0, 0, 0, 0, 0, 0, 'no active state types configured');
            }

            $mappedStateTypes = $this->stateTypeMapper->mapInternalIdsToZnunyTypes($activeStateTypeIds);

            if (empty($mappedStateTypes)) {
                return $this->result(0, 0, 0, 0, 0, 0, 'no mapped state types found');
            }

            // 1. Candidate Discovery (Local Workspace Cache)
            $allActiveTickets = $this->workspaceReader->getTickets(['state_types' => $mappedStateTypes]);
            $totalActive = count($allActiveTickets);

            if ($totalActive === 0) {
                Redis::set(self::LAST_RUN_KEY, time());

                return $this->result(0, 0, 0, 0, 0, 0, 'success');
            }

            // Filter and deduplicate eligible tickets locally while preserving Changed DESC order
            // from ZnunyTicketWorkspaceCacheReader::getTickets().
            $eligibleByTicketId = [];
            foreach ($allActiveTickets as $ticket) {
                $rawTicketId = $ticket['TicketID'] ?? null;
                if (is_int($rawTicketId)) {
                    $ticketId = $rawTicketId;
                } elseif (is_string($rawTicketId) && ctype_digit($rawTicketId)) {
                    $ticketId = (int) $rawTicketId;
                } else {
                    continue;
                }

                $rawInlineCount = $ticket['InlineAttachmentCount'] ?? 0;
                if (is_int($rawInlineCount)) {
                    $inlineCount = $rawInlineCount;
                } elseif (is_string($rawInlineCount) && ctype_digit($rawInlineCount)) {
                    $inlineCount = (int) $rawInlineCount;
                } else {
                    $inlineCount = 0;
                }

                if ($ticketId <= 0 || $inlineCount <= 0 || isset($eligibleByTicketId[$ticketId])) {
                    continue;
                }

                $eligibleByTicketId[$ticketId] = $ticket;
            }

            $eligibleTickets = array_values($eligibleByTicketId);
            $totalEligible = count($eligibleTickets);

            $batchSize = max(1, min(1000, (int) config('znuny.inline_image_warmer_batch_size', 50)));
            $hotPercentage = max(1, min(100, (int) config('znuny.inline_image_warmer_hot_percentage', 10)));

            $hotCountMax = (int) ceil($batchSize * $hotPercentage / 100);
            $hotCountMax = max(1, min($hotCountMax, $batchSize));
            $tailCountMax = $batchSize - $hotCountMax;

            $candidates = [];
            $hotInspected = 0;
            $tailInspected = 0;

            if ($totalEligible <= $batchSize) {
                foreach ($eligibleTickets as $ticket) {
                    $candidates[$ticket['TicketID']] = $ticket;
                }

                $hotInspected = $totalEligible;
                Redis::set(self::TAIL_OFFSET_KEY, 0);
            } else {
                // Hot candidates are always the newest eligible tickets.
                $hotWindow = array_slice($eligibleTickets, 0, $hotCountMax);
                foreach ($hotWindow as $ticket) {
                    $candidates[$ticket['TicketID']] = $ticket;
                    $hotInspected++;
                }

                // Rotation is only across the remaining eligible (non-hot) population.
                $tailPool = array_slice($eligibleTickets, $hotCountMax);
                $tailPoolCount = count($tailPool);
                $tailOffset = (int) Redis::get(self::TAIL_OFFSET_KEY);

                if ($tailOffset < 0 || $tailOffset >= $tailPoolCount) {
                    $tailOffset = 0;
                }

                if ($tailCountMax > 0 && $tailPoolCount > 0) {
                    $take = min($tailCountMax, $tailPoolCount);

                    for ($i = 0; $i < $take; $i++) {
                        $idx = ($tailOffset + $i) % $tailPoolCount;
                        $ticket = $tailPool[$idx];
                        $candidates[$ticket['TicketID']] = $ticket;
                        $tailInspected++;
                    }

                    Redis::set(
                        self::TAIL_OFFSET_KEY,
                        ($tailOffset + $tailInspected) % $tailPoolCount
                    );
                }
            }

            $selectedCount = count($candidates);
            $referencesDiscovered = 0;
            $referencesProcessed = 0;
            $errors = 0;

            $processedReferences = [];

            foreach ($candidates as $ticketId => $ticket) {
                try {
                    $references = $this->client->getTicketInlineAttachmentReferences($ticketId);
                    $referencesDiscovered += count($references);

                    foreach ($references as $ref) {
                        $tuple = "{$ref['TicketID']}-{$ref['ArticleID']}-{$ref['ContentID']}";
                        if (isset($processedReferences[$tuple])) {
                            continue;
                        }
                        $processedReferences[$tuple] = true;

                        try {
                            $this->inlineImageService->getInlineImage($ref['TicketID'], $ref['ArticleID'], $ref['ContentID']);
                            $referencesProcessed++;
                        } catch (Throwable $e) {
                            $errors++;
                            Log::warning('ZnunyInlineImageWarmer: Failed to process reference', [
                                'TicketID' => $ref['TicketID'],
                                'ArticleID' => $ref['ArticleID'],
                                'ContentIDHash' => hash('sha256', $ref['ContentID']),
                                'exception' => get_class($e),
                            ]);
                        }
                    }
                } catch (Throwable $e) {
                    $errors++;
                    Log::warning('ZnunyInlineImageWarmer: Failed to get inline references', [
                        'TicketID' => $ticketId,
                        'exception' => get_class($e),
                    ]);
                }
            }

            Redis::set(self::LAST_RUN_KEY, time());

            return $this->result(
                $totalActive,
                $hotCountMax,
                $tailCountMax,
                $selectedCount,
                $referencesDiscovered,
                $referencesProcessed,
                'success',
                $errors,
                $hotInspected + $tailInspected
            );
        } finally {
            Redis::del(self::RUNNING_LOCK_KEY);
        }
    }

    private function result(
        int $totalActive,
        int $hotSlots,
        int $tailSlots,
        int $selectedUnique,
        int $referencesDiscovered,
        int $referencesProcessed,
        string $status,
        int $errors = 0,
        int $ticketsInspected = 0
    ): array {
        return [
            'total_active' => $totalActive,
            'hot_slots' => $hotSlots,
            'tail_slots' => $tailSlots,
            'selected_unique_tickets' => $selectedUnique,
            'references_discovered' => $referencesDiscovered,
            'references_processed' => $referencesProcessed,
            'errors' => $errors,
            'status' => $status,
            'tickets_inspected' => $ticketsInspected,
        ];
    }
}
