<?php

namespace App\Services\Znuny;

use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Models\ZnunyTicketCreationAttempt;
use Throwable;

class ZnunyTicketCreationReliabilityService
{
    public function __construct(
        private ZnunyTicketCreationMarkerBuilder $markerBuilder
    ) {}

    public function applyMarkerAndCreateAttempt(
        string $sourceType,
        string|int $sourceId,
        string $originalSubject,
        array &$payload,
        ?int $createdBy = null
    ): ZnunyTicketCreationAttempt {
        $sourceIdStr = (string) $sourceId;

        $marker = match ($sourceType) {
            'zabbix_problem' => $this->markerBuilder->buildZabbixMarker($sourceIdStr),
            'scheduled_run' => $this->markerBuilder->buildScheduledMarker($sourceIdStr),
            default => throw new \InvalidArgumentException("Unknown source type: {$sourceType}"),
        };
        $markedSubject = $this->markerBuilder->appendMarker($originalSubject, $marker);

        if (isset($payload['Ticket']['Title'])) {
            $payload['Ticket']['Title'] = $markedSubject;
        }
        if (isset($payload['Article']['Subject'])) {
            $payload['Article']['Subject'] = $markedSubject;
        }

        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => $sourceType,
            'source_id' => $sourceIdStr,
            'marker' => $marker,
            'subject_original' => $originalSubject,
            'subject_sent' => $markedSubject,
            'status' => ZnunyTicketCreationAttemptStatus::Preparing,
            'started_at' => now(),
            'payload_snapshot' => $payload,
            'created_by' => $createdBy,
        ]);

        return $attempt;
    }

    public function recordApiStart(ZnunyTicketCreationAttempt $attempt): void
    {
        $attempt->update(['status' => ZnunyTicketCreationAttemptStatus::Sending]);
    }

    public function recordApiSuccess(ZnunyTicketCreationAttempt $attempt, array $apiResult): void
    {
        $attempt->update([
            'status' => ZnunyTicketCreationAttemptStatus::Success,
            'ticket_id' => $apiResult['ticket_id'] ?? null,
            'ticket_number' => $apiResult['ticket_number'] ?? null,
            'response_snapshot' => $this->sanitizeResponse($apiResult),
            'finished_at' => now(),
        ]);
    }

    public function recordApiUncertain(ZnunyTicketCreationAttempt $attempt, array $apiResult, ?string $errorSummary = null, ?string $errorDetails = null): void
    {
        $attempt->update([
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
            'error_summary' => $errorSummary ?? 'Znuny API returned success false or missing ID/Number.',
            'error_details' => $errorDetails ?? json_encode($apiResult['errors'] ?? []),
            'response_snapshot' => $this->sanitizeResponse($apiResult),
            'finished_at' => now(),
        ]);
    }

    public function recordApiException(ZnunyTicketCreationAttempt $attempt, Throwable $e): void
    {
        $attempt->update([
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
            'error_summary' => 'Exception during ticket creation HTTP request: '.substr($e->getMessage(), 0, 150),
            'error_details' => $e->getMessage()."\n".$e->getTraceAsString(),
            'finished_at' => now(),
        ]);
    }

    public function sanitizeResponse(array $response): array
    {
        array_walk_recursive($response, function (&$value, $key) {
            if (! is_string($key)) {
                return;
            }
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, ['userlogin', 'password', 'token', 'sessionid', 'authorization'])) {
                $value = '[REDACTED]';
            }
        });

        return $response;
    }
}
