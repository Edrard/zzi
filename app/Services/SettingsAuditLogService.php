<?php

namespace App\Services;

class SettingsAuditLogService
{
    public function logChanges(array $changedSettings): void
    {
        if (empty($changedSettings)) {
            return;
        }

        $sensitiveKeywords = ['token', 'password', 'secret', 'api_key', 'session'];

        $sanitizedChanges = array_map(function ($change) use ($sensitiveKeywords) {
            $isSensitive = false;
            foreach ($sensitiveKeywords as $keyword) {
                if (str_contains(strtolower($change['key']), $keyword)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $change['old_value'] = '[redacted]';
                $change['new_value'] = '[redacted]';
            }

            return $change;
        }, $changedSettings);

        AuditLogger::log(
            action: 'settings.updated',
            entityType: 'settings',
            entityId: null,
            context: ['changes' => $sanitizedChanges]
        );
    }
}
