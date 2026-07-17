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
            ->label(__('settings.settings_page.queue_mappings.heading'))
            ->helperText(new HtmlString(
                __('settings.settings_page.queue_mappings.helper_text').(
                    $queueError
                        ? '<br><span style="color: #e11d48; font-weight: bold;">'.$queueError.'</span>'
                        : ''
                )
            ))
            ->schema([
                TextInput::make('host_prefix')
                    ->label(__('settings.settings_page.queue_mappings.columns.host_prefix'))
                    ->helperText(__('settings.settings_page.queue_mappings.fields.host_prefix.helper_text'))
                    ->dehydrateStateUsing(fn ($state) => trim($state))
                    ->distinct()
                    ->required(false),
                Select::make('queue_name')
                    ->label(__('settings.settings_page.queue_mappings.columns.queue_name'))
                    ->options($queueOptions)
                    ->searchable()
                    ->required(false),
                TextInput::make('note')
                    ->label(__('settings.settings_page.queue_mappings.columns.note'))
                    ->placeholder(__('settings.settings_page.queue_mappings.fields.note.placeholder'))
                    ->required(false),
            ])
            ->columns(3)
            ->defaultItems(0)
            ->reorderable(false);
    }

    public function getSaveAction(): Action
    {
        return Action::make('saveMappings')
            ->label(__('settings.settings_page.queue_mappings.actions.save_mappings.label'))
            ->icon('heroicon-o-check')
            ->color('success')
            ->action(function (Settings $livewire) {
                if (auth()->user()->role !== 'admin') {
                    abort(403, __('settings.settings_page.queue_mappings.errors.only_admins'));
                }

                $mappingService = app(ZnunyQueueHostMappingService::class);
                $state = $livewire->data['znuny_queue_host_mappings'] ?? [];
                $mappingService->saveMappings($state);

                Notification::make()
                    ->title(__('settings.settings_page.queue_mappings.notifications.saved_successfully'))
                    ->success()
                    ->send();
            });
    }

    public function getScanMissingAction(): Action
    {
        return Action::make('scanMissing')
            ->label(__('settings.settings_page.queue_mappings.actions.scan_missing.label'))
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

                $message = __('settings.settings_page.queue_mappings.notifications.scan_complete.body', [
                    'scanned' => $stats['scanned'],
                    'unique_prefixes' => $stats['unique_prefixes'],
                    'added' => $stats['added'],
                    'skipped_existing_queue' => $stats['skipped_existing_queue'],
                    'skipped_existing_mapping' => $stats['skipped_existing_mapping'],
                    'failed_api' => $stats['failed_api'],
                ]);

                Notification::make()
                    ->title(__('settings.settings_page.queue_mappings.notifications.scan_complete.title'))
                    ->body($message)
                    ->success()
                    ->send();
            });
    }
}
