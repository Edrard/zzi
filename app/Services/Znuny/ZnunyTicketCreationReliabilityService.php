<?php

namespace App\Services\Znuny;

use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Enums\ZnunyTicketCreationClassification;
use App\Models\ZnunyTicketCreationAttempt;
use Throwable;

class ZnunyTicketCreationReliabilityService
{
    public function __construct(
        private ZnunyTicketCreationMarkerBuilder $markerBuilder,
        private ZnunyTicketCreationResultClassifier $classifier
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

    public function recordApiResult(ZnunyTicketCreationAttempt $attempt, array $apiResult): ZnunyTicketCreationClassification
    {
        $classification = $this->classifier->classify($apiResult);
        $sanitizedResponse = $this->sanitizedResponse($apiResult);

        $updateData = [
            'finished_at' => now(),
            'response_snapshot' => $sanitizedResponse,
        ];

        if ($classification === ZnunyTicketCreationClassification::Success) {
            $updateData['status'] = ZnunyTicketCreationAttemptStatus::Success;
            $updateData['ticket_id'] = $apiResult['ticket_id'] ?? null;
            $updateData['ticket_number'] = $apiResult['ticket_number'] ?? null;
        } elseif ($classification === ZnunyTicketCreationClassification::ConfirmedFailed) {
            $updateData['status'] = ZnunyTicketCreationAttemptStatus::ConfirmedFailed;
            $updateData['error_summary'] = 'Znuny API explicitly rejected the request.';
            $updateData['error_details'] = $this->buildSafeErrorDetails($apiResult, $classification);
        } else {
            $updateData['status'] = ZnunyTicketCreationAttemptStatus::Uncertain;

            $successFlag = $apiResult['success'] ?? null;
            if ($successFlag === null) {
                $updateData['error_summary'] = 'Response missing success flag.';
            } elseif (! is_bool($successFlag)) {
                $updateData['error_summary'] = 'Response success flag is not a boolean.';
            } elseif ($successFlag === true) {
                $updateData['error_summary'] = 'Response reported success but missing or invalid TicketID/TicketNumber.';
            } else {
                $updateData['error_summary'] = 'Znuny API returned success false but ambiguous error state or identifiers.';
            }

            $updateData['error_details'] = $this->buildSafeErrorDetails($apiResult, $classification);
        }

        $attempt->update($updateData);

        return $classification;
    }

    public function recordApiException(ZnunyTicketCreationAttempt $attempt, Throwable $e): ZnunyTicketCreationClassification
    {
        $sanitizedMessage = $this->sanitizeExceptionMessage($e->getMessage());
        $boundedMessage = substr($sanitizedMessage, 0, 150);
        $errorDetails = get_class($e).': '.$boundedMessage;

        $attempt->update([
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
            'error_summary' => 'Exception during ticket creation HTTP request: '.$boundedMessage,
            'error_details' => $errorDetails,
            'finished_at' => now(),
        ]);

        return ZnunyTicketCreationClassification::Uncertain;
    }

    public function sanitizeExceptionMessage(string $message): string
    {
        $message = preg_replace(
            '/(Authorization\s*[:=]\s*)(?:(?:Bearer|Basic|Token)\s+)?([^\s,&"\'\r\n]+)/i',
            '$1[REDACTED]',
            $message
        ) ?? $message;

        $message = preg_replace(
            '/(Bearer\s+)([^\s,&"\'\r\n]+)/i',
            '$1[REDACTED]',
            $message
        ) ?? $message;

        $keys = 'password|token|secret|api_key|apikey|access_token|refresh_token|client_secret|sessionid|session_id|userlogin';

        $message = preg_replace(
            '/(["\']?(?:'.$keys.')["\']?\s*[=:]\s*)(["\'])(.*?)\2/i',
            '$1$2[REDACTED]$2',
            $message
        ) ?? $message;

        $message = preg_replace(
            '/(["\']?(?:'.$keys.')["\']?\s*[=:]\s*)(?!["\'])([^\s&,\r\n]+)/i',
            '$1[REDACTED]',
            $message
        ) ?? $message;

        return preg_replace(
            '/([a-z0-9+.-]+:\/\/)([^:@\/\s]+):([^@\/\s]+)(@)/i',
            '$1[REDACTED]:[REDACTED]$4',
            $message
        ) ?? $message;
    }

    public function sanitizedResponse(array $apiResult): array
    {
        $response = $apiResult;
        array_walk_recursive($response, function (&$value, $key) {
            if (! is_string($key)) {
                return;
            }
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, [
                'userlogin',
                'password',
                'token',
                'secret',
                'api_key',
                'apikey',
                'access_token',
                'refresh_token',
                'client_secret',
                'sessionid',
                'session_id',
                'authorization',
            ], true)) {
                $value = '[REDACTED]';
            }
        });

        return $response;
    }

    public function normalizedSanitizedErrors(array $apiResult): array
    {
        return $this->normalizedSanitizedField($apiResult, 'errors');
    }

    public function normalizedSanitizedField(array $apiResult, string $field): array
    {
        $sanitized = $this->sanitizedResponse($apiResult);

        return $this->classifier->normalizeErrors($sanitized[$field] ?? []);
    }

    public function safeJsonEncode(mixed $data): string
    {
        $json = json_encode(
            $data,
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            return '["JSON encoding failed"]';
        }

        return $json;
    }

    public function buildSafeErrorDetails(array $apiResult, ZnunyTicketCreationClassification $classification): string
    {
        $sanitized = $this->sanitizedResponse($apiResult);
        $errors = $this->normalizedSanitizedErrors($apiResult);

        if ($classification === ZnunyTicketCreationClassification::ConfirmedFailed) {
            return $this->safeJsonEncode($errors);
        }

        if ($classification === ZnunyTicketCreationClassification::Uncertain) {
            if (! empty($errors)) {
                return $this->safeJsonEncode($errors);
            }

            return $this->safeJsonEncode($sanitized);
        }

        return $this->safeJsonEncode($sanitized);
    }
}
