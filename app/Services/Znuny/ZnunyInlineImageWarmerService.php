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

    private const TAIL_OFFSET_KEY = 'znuny:inline_image_warmer:tail_offset';

    private const LAST_RUN_KEY = 'znuny:inline_image_warmer:last_run_at';

    public function __construct(
        ZnunyClient $client,
        ZnunyInlineImageService $inlineImageService,
        ZnunyTicketWorkspaceStateTypeMapper $stateTypeMapper
    ) {
        $this->client = $client;
        $this->inlineImageService = $inlineImageService;
        $this->stateTypeMapper = $stateTypeMapper;
    }

    public function warm(): array
    {
        if (! SettingsService::bool('znuny_inline_image_warmer_enabled')) {
            return $this->result(0, 0, 0, 0, 0, 0, 'warmer disabled');
        }

        $activeStateTypeIdsJson = SettingsService::string('znuny_ticket_workspace_active_state_type_ids', '[]');
        $activeStateTypeIds = json_decode($activeStateTypeIdsJson, true) ?? [];

        if (empty($activeStateTypeIds) || ! is_array($activeStateTypeIds)) {
            return $this->result(0, 0, 0, 0, 0, 0, 'no active state types configured');
        }

        $mappedStateTypes = $this->stateTypeMapper->mapInternalIdsToZnunyTypes($activeStateTypeIds);

        if (empty($mappedStateTypes)) {
            return $this->result(0, 0, 0, 0, 0, 0, 'no mapped state types found');
        }

        $combinedStateTypes = implode(',', $mappedStateTypes);

        // Find total active tickets
        $countMetadata = $this->client->searchTicketsWithMetadata([
            'StateType' => $combinedStateTypes,
            'CountOnly' => 1,
        ]);

        $totalActive = $countMetadata['total_count'] ?? 0;

        if ($totalActive === 0) {
            Redis::set(self::LAST_RUN_KEY, time());

            return $this->result(0, 0, 0, 0, 0, 0, 'success');
        }

        $batchSize = max(1, min(1000, (int) config('znuny.inline_image_warmer_batch_size', 50)));
        $hotPercentage = max(1, min(100, (int) config('znuny.inline_image_warmer_hot_percentage', 10)));

        $hotCountMax = (int) ceil($batchSize * $hotPercentage / 100);
        $hotCountMax = max(1, min($hotCountMax, $batchSize));
        $tailCountMax = $batchSize - $hotCountMax;

        $candidates = [];

        // 1. Hot Window Selection
        $hotMetadata = $this->client->searchTicketsWithMetadata([
            'StateType' => $combinedStateTypes,
            'SortBy' => 'Changed',
            'SortDirection' => 'DESC',
            'Offset' => 0,
            'Limit' => $batchSize, // Query enough to find candidates
        ]);

        $hotInspected = 0;
        if (! empty($hotMetadata['tickets'])) {
            foreach ($hotMetadata['tickets'] as $ticket) {
                if (count($candidates) >= $hotCountMax) {
                    break;
                }
                $hotInspected++;
                if (isset($ticket['InlineAttachmentCount']) && (int) $ticket['InlineAttachmentCount'] > 0) {
                    $candidates[$ticket['TicketID']] = $ticket;
                }
            }
        }

        // 2. Tail Window Selection
        $tailOffset = (int) Redis::get(self::TAIL_OFFSET_KEY);
        if ($tailOffset >= $totalActive) {
            $tailOffset = 0;
        }

        $tailInspected = 0;
        if ($tailCountMax > 0) {
            $tailMetadata = $this->client->searchTicketsWithMetadata([
                'StateType' => $combinedStateTypes,
                'SortBy' => 'Changed',
                'SortDirection' => 'DESC',
                'Offset' => $tailOffset,
                'Limit' => $batchSize, // Query enough to find candidates
            ]);

            $tailAdded = 0;
            if (! empty($tailMetadata['tickets'])) {
                foreach ($tailMetadata['tickets'] as $ticket) {
                    $tailInspected++;
                    // Deduplicate
                    if (! isset($candidates[$ticket['TicketID']])) {
                        if (isset($ticket['InlineAttachmentCount']) && (int) $ticket['InlineAttachmentCount'] > 0) {
                            $candidates[$ticket['TicketID']] = $ticket;
                            $tailAdded++;
                            if ($tailAdded >= $tailCountMax) {
                                break;
                            }
                        }
                    }
                }
            }

            // Advance cursor
            $newTailOffset = $tailOffset + $tailInspected;
            if ($newTailOffset >= $totalActive) {
                $newTailOffset = 0;
            }
            Redis::set(self::TAIL_OFFSET_KEY, $newTailOffset);
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
