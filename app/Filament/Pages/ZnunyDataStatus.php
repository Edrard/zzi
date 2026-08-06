<?php

namespace App\Filament\Pages;

use App\Services\Znuny\Cache\PrewarmRunnerService;
use Filament\Actions\Action;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use App\Services\SettingsService;
use App\Services\Znuny\Cache\ZnunyQueueCacheReadService;
use App\Services\Znuny\Cache\ZnunyAgentCacheReadService;
use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use App\Services\Znuny\Cache\ZnunyCustomerUserCacheReadService;
use BackedEnum;

class ZnunyDataStatus extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-server-stack';
    protected static ?string $slug = 'znuny-data-status';
    protected static ?int $navigationSort = 31;
    protected string $view = 'filament.pages.znuny-data-status';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.administration');
    }

    public static function getNavigationLabel(): string
    {
        return __('znuny_data_status.navigation_label');
    }

    public function getTitle(): string
    {
        return __('znuny_data_status.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAdministerApplication() ?? false;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->constantState([])
            ->components([
                $this->buildDatasetSection(__('znuny_data_status.datasets.queues'), 'queues', ZnunyQueueCacheReadService::class, __('znuny_data_status.descriptions.queues')),
                $this->buildDatasetSection(__('znuny_data_status.datasets.agents'), 'agents', ZnunyAgentCacheReadService::class, __('znuny_data_status.descriptions.agents')),
                $this->buildDatasetSection(__('znuny_data_status.datasets.customer_users'), 'customer_users', ZnunyCustomerUserCacheReadService::class, __('znuny_data_status.descriptions.customer_users')),
                $this->buildDatasetSection(__('znuny_data_status.datasets.lookups'), 'lookups', ZnunyLookupCacheReadService::class, __('znuny_data_status.descriptions.lookups')),
            ]);
    }

    private function buildDatasetSection(string $title, string $datasetKey, string $serviceClass, string $countDescription): Section
    {
        $meta = app($serviceClass)->getMetadata();

        $intervalDefault = match ($datasetKey) {
            'lookups' => 60,
            'customer_users' => 30,
            default => 5,
        };
        $interval = max(3, SettingsService::int('znuny_prewarm_' . $datasetKey . '_interval_minutes', $intervalDefault));

        $rawStatus = $meta['status'] ?? 'unknown';
        if ($rawStatus === '') {
            $rawStatus = 'unknown';
        }

        $statusColor = match ($rawStatus) {
            'ready' => 'success',
            'refreshing' => 'warning',
            'failed' => 'danger',
            'stale' => 'warning',
            default => 'gray',
        };

        return Section::make($title)
            ->key($datasetKey . '_section')
            ->schema([
                TextEntry::make($datasetKey . '_dataset_name')
                    ->label(__('znuny_data_status.fields.dataset_name'))
                    ->default($title)
                    ->weight(FontWeight::Bold),

                TextEntry::make($datasetKey . '_internal_key')
                    ->label(__('znuny_data_status.fields.internal_key'))
                    ->default($datasetKey)
                    ->color('gray'),

                TextEntry::make($datasetKey . '_status')
                    ->label(__('znuny_data_status.fields.status'))
                    ->default(__('znuny_data_status.status.' . $rawStatus))
                    ->badge()
                    ->color($statusColor),

                TextEntry::make($datasetKey . '_item_count')
                    ->label(__('znuny_data_status.fields.item_count'))
                    ->default((string) ($meta['item_count'] ?? '0'))
                    ->weight(FontWeight::Bold)
                    ->helperText($countDescription),

                TextEntry::make($datasetKey . '_last_attempt_at')
                    ->label(__('znuny_data_status.fields.last_attempt_at'))
                    ->default(!empty($meta['last_attempt_at']) ? \Carbon\Carbon::parse($meta['last_attempt_at'])->diffForHumans() : __('znuny_data_status.values.never')),

                TextEntry::make($datasetKey . '_last_successful_refresh_at')
                    ->label(__('znuny_data_status.fields.last_successful_refresh_at'))
                    ->default(!empty($meta['last_successful_refresh_at']) ? \Carbon\Carbon::parse($meta['last_successful_refresh_at'])->diffForHumans() : __('znuny_data_status.values.never')),

                TextEntry::make($datasetKey . '_active_generation')
                    ->label(__('znuny_data_status.fields.active_generation'))
                    ->default(!empty($meta['active_generation']) ? $meta['active_generation'] : __('znuny_data_status.values.none'))
                    ->color('gray'),

                TextEntry::make($datasetKey . '_interval')
                    ->label(__('znuny_data_status.fields.interval'))
                    ->default($interval . ' ' . __('znuny_data_status.values.minutes')),

                TextEntry::make($datasetKey . '_last_error')
                    ->label(__('znuny_data_status.fields.last_error'))
                    ->default(!empty($meta['last_error']) ? $meta['last_error'] : __('znuny_data_status.values.none'))
                    ->color(!empty($meta['last_error']) ? 'danger' : 'gray')
                    ->columnSpanFull(),
            ])
            ->columns(3)
            ->headerActions([
                Action::make('rewarm_' . $datasetKey)
                    ->label(__('znuny_data_status.actions.refresh_now'))
                    ->icon('heroicon-m-arrow-path')
                    ->action(function () use ($datasetKey, $title) {
                        $result = app(PrewarmRunnerService::class)->run($datasetKey, 'manual');

                        try {
                            \App\Services\AuditLogger::log(
                                'znuny_prewarm_manual_refresh',
                                'znuny_prewarm_dataset',
                                null,
                                [
                                    'dataset' => $datasetKey,
                                    'dataset_label' => $title,
                                    'status' => $result['status'],
                                    'message' => $result['message'] ?? '',
                                    'source' => 'manual',
                                ]
                            );
                        } catch (\Throwable $e) {
                            $sanitizedAuditError = app(\App\Services\Znuny\Cache\PrewarmErrorSanitizer::class)->sanitize($e->getMessage());
                            \Illuminate\Support\Facades\Log::error('AuditLogger failed during manual refresh.', [
                                'dataset' => $datasetKey,
                                'source' => 'manual',
                                'status' => $result['status'],
                                'message' => $sanitizedAuditError,
                            ]);
                        }

                        if ($result['status'] === 'success') {
                            Notification::make()->success()->title(__('znuny_data_status.notifications.success_title', ['dataset' => $title]))->send();
                        } elseif ($result['status'] === 'skipped_locked') {
                            Notification::make()->warning()->title(__('znuny_data_status.notifications.skipped_locked_title'))->send();
                        } elseif ($result['status'] === 'timeout') {
                            Notification::make()->danger()->title(__('znuny_data_status.notifications.timeout_title', ['dataset' => $title]))->body($result['message'])->send();
                        } else {
                            Notification::make()->danger()->title(__('znuny_data_status.notifications.error_title', ['dataset' => $title]))->body($result['message'])->send();
                        }

                        // Reload infolist state by redirecting to self
                        return redirect(static::getUrl());
                    })
            ]);
    }
}
