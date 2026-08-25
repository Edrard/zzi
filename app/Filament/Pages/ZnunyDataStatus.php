<?php

namespace App\Filament\Pages;

use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Services\Znuny\Cache\PrewarmErrorSanitizer;
use App\Services\Znuny\Cache\PrewarmRunnerService;
use App\Services\Znuny\Cache\ZnunyAgentCacheReadService;
use App\Services\Znuny\Cache\ZnunyCustomerUserCacheReadService;
use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use App\Services\Znuny\Cache\ZnunyQueueCacheReadService;
use App\Services\Znuny\ZnunyInlineImageWarmerService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

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
                $this->buildInlineImageSection(),
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
        $interval = max(3, SettingsService::int('znuny_prewarm_'.$datasetKey.'_interval_minutes', $intervalDefault));

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
            ->key($datasetKey.'_section')
            ->schema([
                TextEntry::make($datasetKey.'_dataset_name')
                    ->label(__('znuny_data_status.fields.dataset_name'))
                    ->default($title)
                    ->weight(FontWeight::Bold),

                TextEntry::make($datasetKey.'_internal_key')
                    ->label(__('znuny_data_status.fields.internal_key'))
                    ->default($datasetKey)
                    ->color('gray'),

                TextEntry::make($datasetKey.'_status')
                    ->label(__('znuny_data_status.fields.status'))
                    ->default(__('znuny_data_status.status.'.$rawStatus))
                    ->badge()
                    ->color($statusColor),

                TextEntry::make($datasetKey.'_item_count')
                    ->label(__('znuny_data_status.fields.item_count'))
                    ->default((string) ($meta['item_count'] ?? '0'))
                    ->weight(FontWeight::Bold)
                    ->helperText($countDescription),

                TextEntry::make($datasetKey.'_last_attempt_at')
                    ->label(__('znuny_data_status.fields.last_attempt_at'))
                    ->default(! empty($meta['last_attempt_at']) ? Carbon::parse($meta['last_attempt_at'])->diffForHumans() : __('znuny_data_status.values.never')),

                TextEntry::make($datasetKey.'_last_successful_refresh_at')
                    ->label(__('znuny_data_status.fields.last_successful_refresh_at'))
                    ->default(! empty($meta['last_successful_refresh_at']) ? Carbon::parse($meta['last_successful_refresh_at'])->diffForHumans() : __('znuny_data_status.values.never')),

                TextEntry::make($datasetKey.'_active_generation')
                    ->label(__('znuny_data_status.fields.active_generation'))
                    ->default(! empty($meta['active_generation']) ? $meta['active_generation'] : __('znuny_data_status.values.none'))
                    ->color('gray'),

                TextEntry::make($datasetKey.'_interval')
                    ->label(__('znuny_data_status.fields.interval'))
                    ->default($interval.' '.__('znuny_data_status.values.minutes')),

                TextEntry::make($datasetKey.'_last_error')
                    ->label(__('znuny_data_status.fields.last_error'))
                    ->default(! empty($meta['last_error']) ? $meta['last_error'] : __('znuny_data_status.values.none'))
                    ->color(! empty($meta['last_error']) ? 'danger' : 'gray')
                    ->columnSpanFull(),
            ])
            ->columns(3)
            ->headerActions([
                Action::make('rewarm_'.$datasetKey)
                    ->label(__('znuny_data_status.actions.refresh_now'))
                    ->icon('heroicon-m-arrow-path')
                    ->action(function () use ($datasetKey, $title) {
                        $result = app(PrewarmRunnerService::class)->run($datasetKey, 'manual');

                        try {
                            AuditLogger::log(
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
                            $sanitizedAuditError = app(PrewarmErrorSanitizer::class)->sanitize($e->getMessage());
                            Log::error('AuditLogger failed during manual refresh.', [
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
                    }),
            ]);
    }

    private function buildInlineImageSection(): Section
    {
        $datasetKey = 'inline_images';
        $title = __('znuny_data_status.datasets.inline_images');
        $countDescription = __('znuny_data_status.descriptions.inline_images');

        $settingsHealthy = true;

        try {
            $enabled = SettingsService::bool('znuny_inline_image_warmer_enabled', false) ?? false;
            $interval = max(1, min(1440, SettingsService::int('znuny_inline_image_warmer_interval_minutes', 5) ?? 5));
            $ttl = max(1, min(10080, SettingsService::int('znuny_inline_image_cache_ttl_minutes', 60) ?? 60));
        } catch (\Throwable) {
            $settingsHealthy = false;
            $enabled = false;
            $interval = 5;
            $ttl = 60;
        }

        $batchSize = max(1, min(1000, (int) config('znuny.inline_image_warmer_batch_size', 50)));
        $hotPercentage = max(1, min(100, (int) config('znuny.inline_image_warmer_hot_percentage', 10)));
        $warmerParams = "{$batchSize} / {$hotPercentage}% hot";

        $markersAvailable = true;
        $markersValid = true;
        $lastRunAt = null;
        $lastStartedAt = null;
        $isRunning = false;
        $tailOffset = 0;

        try {
            $rawLastRunAt = Redis::get('znuny:inline_image_warmer:last_run_at');
            $rawLastStartedAt = Redis::get('znuny:inline_image_warmer:last_started_at');
            $rawTailOffset = Redis::get('znuny:inline_image_warmer:tail_offset');
            $isRunning = Redis::exists('znuny:inline_image_warmer:running');
        } catch (\Throwable) {
            $markersAvailable = false;
            $rawLastRunAt = null;
            $rawLastStartedAt = null;
            $rawTailOffset = null;
        }

        if ($markersAvailable) {
            if ($rawLastRunAt !== null && $rawLastRunAt !== '') {
                $timestamp = filter_var($rawLastRunAt, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);

                if ($timestamp === false) {
                    $markersValid = false;
                } else {
                    try {
                        $lastRunAt = Carbon::createFromTimestamp((int) $timestamp);
                    } catch (\Throwable) {
                        $markersValid = false;
                        $lastRunAt = null;
                    }
                }
            }

            if ($rawLastStartedAt !== null && $rawLastStartedAt !== '') {
                $timestampStarted = filter_var($rawLastStartedAt, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);

                if ($timestampStarted !== false) {
                    try {
                        $lastStartedAt = Carbon::createFromTimestamp((int) $timestampStarted);
                    } catch (\Throwable) {
                        $lastStartedAt = null;
                    }
                }
            }

            if ($rawTailOffset !== null && $rawTailOffset !== '') {
                $normalizedTailOffset = filter_var($rawTailOffset, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 0],
                ]);

                if ($normalizedTailOffset === false) {
                    $markersValid = false;
                } else {
                    $tailOffset = (int) $normalizedTailOffset;
                }
            }
        }

        $inlineCacheAvailable = true;

        try {
            $itemCount = (string) Redis::connection('inline_images')->dbSize();
        } catch (\Throwable) {
            $inlineCacheAvailable = false;
            $itemCount = __('znuny_data_status.values.unknown');
        }

        $rawStatus = 'unknown';
        $statusColor = 'gray';

        $latestActivityAt = $lastRunAt;
        if ($lastStartedAt !== null && ($latestActivityAt === null || $lastStartedAt->greaterThan($latestActivityAt))) {
            $latestActivityAt = $lastStartedAt;
        }

        if (! $settingsHealthy) {
            $rawStatus = 'unknown';
        } elseif (! $enabled) {
            $rawStatus = 'disabled';
        } elseif (! $markersAvailable || ! $markersValid || ! $inlineCacheAvailable) {
            $rawStatus = 'unknown';
        } elseif ($isRunning) {
            $rawStatus = 'running';
            $statusColor = 'info';
        } elseif ($latestActivityAt === null) {
            $rawStatus = 'pending';
            $statusColor = 'warning';
        } elseif (Carbon::now()->greaterThan($latestActivityAt->copy()->addMinutes($ttl))) {
            $rawStatus = 'stale_inline';
            $statusColor = 'warning';
        } else {
            $rawStatus = 'ready';
            $statusColor = 'success';
        }

        $lastSuccessfulRefresh = (! $markersAvailable || ! $markersValid)
            ? __('znuny_data_status.values.unknown')
            : ($lastRunAt?->diffForHumans() ?? __('znuny_data_status.values.never'));

        $tailOffsetDisplay = (! $markersAvailable || ! $markersValid)
            ? __('znuny_data_status.values.unknown')
            : (string) $tailOffset;

        return Section::make($title)
            ->key($datasetKey.'_section')
            ->schema([
                TextEntry::make($datasetKey.'_dataset_name')
                    ->label(__('znuny_data_status.fields.dataset_name'))
                    ->default($title)
                    ->weight(FontWeight::Bold),

                TextEntry::make($datasetKey.'_internal_key')
                    ->label(__('znuny_data_status.fields.internal_key'))
                    ->default($datasetKey)
                    ->color('gray'),

                TextEntry::make($datasetKey.'_status')
                    ->label(__('znuny_data_status.fields.status'))
                    ->default(__('znuny_data_status.status.'.$rawStatus))
                    ->badge()
                    ->color($statusColor),

                TextEntry::make($datasetKey.'_item_count')
                    ->label(__('znuny_data_status.fields.item_count'))
                    ->default($itemCount)
                    ->weight(FontWeight::Bold)
                    ->helperText($countDescription),

                TextEntry::make($datasetKey.'_last_successful_refresh_at')
                    ->label(__('znuny_data_status.fields.last_successful_refresh_at'))
                    ->default($lastSuccessfulRefresh),

                TextEntry::make($datasetKey.'_tail_offset')
                    ->label(__('znuny_data_status.fields.tail_offset'))
                    ->default($tailOffsetDisplay)
                    ->helperText(__('znuny_data_status.descriptions.tail_offset')),

                TextEntry::make($datasetKey.'_interval')
                    ->label(__('znuny_data_status.fields.interval'))
                    ->default($interval.' '.__('znuny_data_status.values.minutes')),

                TextEntry::make($datasetKey.'_ttl')
                    ->label(__('znuny_data_status.fields.ttl'))
                    ->default($ttl.' '.__('znuny_data_status.values.minutes')),

                TextEntry::make($datasetKey.'_warmer_parameters')
                    ->label(__('znuny_data_status.fields.warmer_parameters'))
                    ->default($warmerParams)
                    ->helperText(__('znuny_data_status.descriptions.warmer_parameters')),
            ])
            ->columns(3)
            ->headerActions([
                Action::make('rewarm_'.$datasetKey)
                    ->label(__('znuny_data_status.actions.refresh_now'))
                    ->icon('heroicon-m-arrow-path')
                    ->action(function () use ($title) {
                        try {
                            $enabled = SettingsService::bool('znuny_inline_image_warmer_enabled', false) ?? false;

                            if (! $enabled) {
                                Notification::make()
                                    ->info()
                                    ->title(__('znuny_data_status.notifications.inline_disabled_title', ['dataset' => $title]))
                                    ->send();

                                return redirect(static::getUrl());
                            }

                            $result = app(ZnunyInlineImageWarmerService::class)->warm();
                            $status = is_string($result['status'] ?? null) ? $result['status'] : 'unknown';
                            $errors = max(0, (int) ($result['errors'] ?? 0));

                            if ($status === 'success' && $errors === 0) {
                                Notification::make()
                                    ->success()
                                    ->title(__('znuny_data_status.notifications.success_title', ['dataset' => $title]))
                                    ->send();
                            } elseif ($status === 'success') {
                                Notification::make()
                                    ->warning()
                                    ->title(__('znuny_data_status.notifications.inline_warning_title', ['dataset' => $title]))
                                    ->body(__('znuny_data_status.notifications.inline_warning_body', ['count' => $errors]))
                                    ->send();
                            } else {
                                Notification::make()
                                    ->warning()
                                    ->title(__('znuny_data_status.notifications.inline_skipped_title', ['dataset' => $title]))
                                    ->body(__('znuny_data_status.notifications.inline_skipped_body'))
                                    ->send();
                            }
                        } catch (\Throwable $e) {
                            $sanitizedAuditError = app(PrewarmErrorSanitizer::class)->sanitize($e->getMessage());

                            Log::error('Inline image warmer manual refresh failed.', [
                                'dataset' => 'inline_images',
                                'source' => 'manual',
                                'message' => $sanitizedAuditError,
                            ]);

                            Notification::make()
                                ->danger()
                                ->title(__('znuny_data_status.notifications.error_title', ['dataset' => $title]))
                                ->send();
                        }

                        return redirect(static::getUrl());
                    }),
            ]);
    }
}
