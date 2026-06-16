<?php

namespace App\Services\Znuny;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;

class ZnunyDefaultAgentSettingsService
{
    public function __construct(private ZnunyAgentService $agentService) {}

    public function saveDefaultAgent(Setting $setting, $newValue, string $currentPlaintext, array &$changedSettings, Collection $settings): void
    {
        $selectableAgents = $this->agentService->getSelectableAgents(failSilently: true);
        $selectedAgent = null;

        if ($newValue !== '') {
            if ($this->agentService->lastError()) {
                // Agent loading failed, do not destroy the existing stored value/snapshot
                return;
            }

            $selectedAgent = collect($selectableAgents)->firstWhere('id', (int) $newValue);
            if (! $selectedAgent) {
                // Invalid selection, do not silently save it
                return;
            }
        }

        $newLogin = $selectedAgent ? $selectedAgent['login'] : '';
        $newName = $selectedAgent ? (string) $selectedAgent['name'] : '';

        // Track changes for ID
        $changedSettings[] = [
            'key' => 'znuny_default_agent_id',
            'old_value' => $currentPlaintext,
            'new_value' => $newValue,
        ];
        $setting->update(['value' => $newValue]);

        // Update login and name
        foreach (['znuny_default_agent_login' => $newLogin, 'znuny_default_agent_name' => $newName] as $k => $v) {
            $subSetting = $settings->firstWhere('key', $k);
            if ($subSetting && $subSetting->value !== $v) {
                $changedSettings[] = [
                    'key' => $k,
                    'old_value' => $subSetting->value,
                    'new_value' => $v,
                ];
                $subSetting->update(['value' => $v]);
            }
        }
    }
}
