<?php

namespace App\Services\OwnerSuggestion;

use App\Models\ZnunyOwnerSuggestionObservation;
use Illuminate\Support\Facades\Log;
use Throwable;

class OwnerSuggestionObservationRecorder
{
    public function __construct(
        protected ProblemNameNormalizer $normalizer
    ) {}

    public function recordManualTicketCreated(array $data): ?ZnunyOwnerSuggestionObservation
    {
        try {
            $problemName = $data['problem_name'] ?? null;
            $ownerId = $data['owner_id'] ?? null;
            $ownerLogin = $data['owner_login'] ?? null;

            if (empty(trim((string) $problemName))) {
                return null;
            }

            if (empty(trim((string) $ownerId)) && empty(trim((string) $ownerLogin))) {
                return null;
            }

            $normalizedKey = $this->normalizer->normalize($problemName);

            if (empty($normalizedKey)) {
                return null;
            }

            return ZnunyOwnerSuggestionObservation::create([
                'problem_name' => $problemName,
                'normalized_problem_key' => $normalizedKey,
                'queue_name' => $data['queue_name'] ?? null,
                'owner_id' => $ownerId,
                'owner_login' => $ownerLogin,
                'zabbix_event_id' => $data['zabbix_event_id'] ?? null,
                'zabbix_host_name' => $data['zabbix_host_name'] ?? null,
                'customer_user_login' => $data['customer_user_login'] ?? null,
                'znuny_ticket_id' => $data['znuny_ticket_id'] ?? null,
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to record owner suggestion observation', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);

            return null; // Non-critical failure
        }
    }
}
