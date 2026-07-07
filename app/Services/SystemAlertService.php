<?php

namespace App\Services;

class SystemAlertService
{
    public function create(string $source, string $severity, string $title, string $message): \App\Models\SystemAlert
    {
        return \App\Models\SystemAlert::create([
            'source' => $source,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'status' => 'active',
        ]);
    }

    public function warning(string $source, string $title, string $message): \App\Models\SystemAlert
    {
        return $this->create($source, 'warning', $title, $message);
    }

    public function danger(string $source, string $title, string $message): \App\Models\SystemAlert
    {
        return $this->create($source, 'danger', $title, $message);
    }

    public function acknowledge(\App\Models\SystemAlert $alert, ?int $userId = null): void
    {
        $alert->update([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
            'acknowledged_by' => $userId,
        ]);
    }
}
