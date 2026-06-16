<?php

namespace App\Services\Znuny;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ZnunyQueueHostMappingSchemaBuilder
{
    public function buildRepeater(Setting $setting, array $initialData): Repeater
    {
        $queueService = app(ZnunyQueueService::class);
        $qResult = $queueService->getSelectableQueuesResult();
        $queueOptions = $qResult['options'] ?? [];
        $queueError = $qResult['error'] ?? null;

        $savedMappings = $initialData['znuny_queue_host_mappings'] ?? [];
        if (is_string($savedMappings)) {
            $savedMappings = json_decode($savedMappings, true) ?? [];
        }

        if (is_array($savedMappings)) {
            foreach ($savedMappings as $m) {
                $qName = $m['queue_name'] ?? null;
                if ($qName && ! isset($queueOptions[$qName])) {
                    $queueOptions[$qName] = $qName.($queueError ? ' (Saved)' : '');
                }
            }
        }

        return Repeater::make($setting->key)
            ->label('Queue host prefix mappings')
            ->helperText(new HtmlString('Maps primary Zabbix host prefixes to existing Znuny queues. Used only when the primary queue candidate is not found in Znuny.'.($queueError ? '<br><span style="color: #e11d48; font-weight: bold;">'.$queueError.'</span>' : '')))
            ->schema([
                TextInput::make('host_prefix')
                    ->label('Host prefix')
                    ->helperText('Example: TestCompany')
                    ->dehydrateStateUsing(fn ($state) => trim($state))
                    ->distinct()
                    ->required(false),
                Select::make('queue_name')
                    ->label('Queue name')
                    ->options($queueOptions)
                    ->searchable()
                    ->required(false),
                TextInput::make('note')
                    ->label('Note')
                    ->required(false),
            ])
            ->columns(3)
            ->defaultItems(0)
            ->reorderable(false);
    }

    public function getSaveAction(): Action
    {
        return Action::make('saveMappings')
            ->label('Save queue mappings')
            ->icon('heroicon-o-check')
            ->color('success')
            ->action(function (Settings $livewire) {
                if (auth()->user()->role !== 'admin') {
                    abort(403, 'Only admins can modify settings.');
                }

                $mappingService = app(ZnunyQueueHostMappingService::class);
                $state = $livewire->data['znuny_queue_host_mappings'] ?? [];
                $mappingService->saveMappings($state);

                Notification::make()
                    ->title('Queue mappings saved successfully.')
                    ->success()
                    ->send();
            });
    }

    public function getScanMissingAction(): Action
    {
        return Action::make('scanMissing')
            ->label('Scan current problems for missing queue mappings')
            ->button()
            ->action(function (Settings $livewire) {
                $mappingService = app(ZnunyQueueHostMappingService::class);

                $fullState = $livewire->form->getRawState();
                $currentState = $fullState['znuny_queue_host_mappings'] ?? [];
                $result = $mappingService->scanMissingMappings($currentState);

                $drafts = $result['drafts'];
                $stats = $result['stats'];

                if (! empty($drafts)) {
                    $newState = $currentState;
                    foreach ($drafts as $draft) {
                        $newState[(string) Str::uuid()] = $draft;
                    }
                    $fullState['znuny_queue_host_mappings'] = $newState;
                    $livewire->form->fill($fullState);
                }

                $message = "Scanned {$stats['scanned']} problems ({$stats['unique_prefixes']} unique prefixes).\n"
                    ."Added {$stats['added']} draft mappings.\n"
                    ."Skipped {$stats['skipped_existing_queue']} existing queues.\n"
                    ."Skipped {$stats['skipped_existing_mapping']} existing mappings.\n"
                    ."Failed API checks: {$stats['failed_api']}.";

                Notification::make()
                    ->title('Scan Complete')
                    ->body($message)
                    ->success()
                    ->send();
            });
    }
}
