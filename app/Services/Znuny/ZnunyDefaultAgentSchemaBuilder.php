<?php

namespace App\Services\Znuny;

use App\Models\Setting;
use App\Services\SettingsService;
use Filament\Forms\Components\Select;
use Illuminate\Support\HtmlString;

class ZnunyDefaultAgentSchemaBuilder
{
    public function build(Setting $setting): Select
    {
        $options = [];
        $warning = null;

        try {
            $agentService = app(ZnunyAgentService::class);
            $selectableAgents = $agentService->getSelectableAgents(failSilently: true);
            foreach ($selectableAgents as $agent) {
                $options[$agent['id']] = $agent['label'];
            }

            if ($agentService->lastError()) {
                $warning = 'Could not load active agents from Znuny API.';
            }
        } catch (\Throwable $e) {
            $warning = 'Could not load active agents from Znuny API.';
        }

        $currentId = SettingsService::string('znuny_default_agent_id');
        if ($currentId !== '' && ! isset($options[$currentId]) && empty($warning)) {
            // Check if it's excluded or completely inactive
            $allAgents = $agentService->getAgents(failSilently: true);
            $isActive = collect($allAgents)->contains('id', (int) $currentId);

            if ($isActive) {
                $warning = 'The currently selected default agent is excluded from selectable agents. Please choose another agent.';
            } else {
                $warning = "The currently selected agent (ID: {$currentId}) is no longer returned by the active agents list. Please select a valid agent.";
            }
        }

        $helpText = 'Used only by future automatic ticket creation. Manual ticket creation requires the operator to choose an owner.';
        if ($warning) {
            $helpText = "<span style=\"color: #e11d48; font-weight: bold;\">Warning: {$warning}</span><br>".$helpText;
        }

        return Select::make($setting->key)
            ->label('Default agent for automatic ticket creation')
            ->helperText(new HtmlString($helpText))
            ->options($options)
            ->searchable()
            ->required(false);
    }
}
