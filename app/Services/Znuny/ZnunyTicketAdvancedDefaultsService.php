<?php

namespace App\Services\Znuny;

use App\Services\SettingsService;

class ZnunyTicketAdvancedDefaultsService
{
    public function getDefaults(): array
    {
        $priority = SettingsService::string('znuny_ticket_default_priority', '3 normal');
        if (empty(trim($priority))) {
            $priority = '3 normal';
        }

        $state = SettingsService::string('znuny_ticket_default_state', 'new');
        if (empty(trim($state))) {
            $state = 'new';
        }

        $lock = SettingsService::string('znuny_ticket_default_lock', 'lock');
        if (! in_array($lock, ['lock', 'unlock'])) {
            $lock = 'lock';
        }

        return [
            'priority' => $priority,
            'state' => $state,
            'lock' => $lock,
        ];
    }
}
