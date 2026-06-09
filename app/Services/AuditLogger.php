<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogger
{
    public static function log(string $action, ?string $entityType = null, int|string|null $entityId = null, array $context = [], ?User $user = null): AuditLog
    {
        if ($user === null && auth()->check()) {
            /** @var User $user */
            $user = auth()->user();
        }

        $ipAddress = null;
        $userAgent = null;

        if (request() !== null) {
            $ipAddress = request()->ip();
            $userAgent = request()->userAgent();
        }

        return AuditLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'context' => $context,
        ]);
    }
}
