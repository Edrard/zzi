<?php

namespace App\Filament\Resources\ScheduledZnunyTaskRuns;

use App\Filament\Resources\ScheduledZnunyTaskRuns\Pages\ManageScheduledZnunyTaskRuns;
use App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource;
use App\Models\ScheduledZnunyTaskRun;
use App\Models\User;
use App\Services\Support\DateTimeDisplayService;
use App\Services\Znuny\ScheduledZnunyTaskRunCloseService;
use App\Services\Znuny\ZnunyClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Livewire\Component;
use Throwable;

class ScheduledZnunyTaskRunResource extends Resource
{
    protected static ?string $model = ScheduledZnunyTaskRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.administration');
    }

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return __('scheduled_znuny_task_runs.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('scheduled_znuny_task_runs.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('scheduled_znuny_task_runs.plural');
    }

    public static function statusLabel(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return $status;
        }

        $key = 'scheduled_znuny_task_runs.statuses.'.$status;
        $translated = __($key);

        return $translated === $key ? $status : $translated;
    }

    public static function runTypeLabel(?string $runType): ?string
    {
        if ($runType === null || $runType === '') {
            return $runType;
        }

        $key = 'scheduled_znuny_task_runs.run_types.'.$runType;
        $translated = __($key);

        return $translated === $key ? $runType : $translated;
    }

    protected static ?string $recordTitleAttribute = 'task_name_snapshot';

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->role === 'admin';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('scheduled_znuny_task_id')
                    ->numeric()
                    ->default(null),
                TextInput::make('task_name_snapshot')
                    ->required(),
                TextInput::make('run_type')
                    ->required(),
                DateTimePicker::make('scheduled_for')
                    ->required(),
                DateTimePicker::make('started_at'),
                DateTimePicker::make('finished_at'),
                TextInput::make('duration_ms')
                    ->numeric()
                    ->default(null),
                TextInput::make('status')
                    ->required(),
                TextInput::make('ticket_id')
                    ->numeric()
                    ->default(null),
                TextInput::make('ticket_number')
                    ->default(null),
                TextInput::make('error_summary')
                    ->default(null),
                Textarea::make('error_details')
                    ->default(null)
                    ->columnSpanFull(),
                Textarea::make('payload_snapshot')
                    ->default(null)
                    ->columnSpanFull(),
                Textarea::make('response_snapshot')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('created_by')
                    ->numeric()
                    ->default(null),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $snapshotFormatter = function (mixed $state): string {
            if ($state === null || $state === '' || $state === []) {
                return '—';
            }

            if (is_string($state)) {
                $decoded = json_decode($state, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $state = $decoded;
                } else {
                    return $state;
                }
            }

            if (is_array($state) || is_object($state)) {
                $encoded = json_encode(
                    $state,
                    JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                );

                return $encoded !== false ? $encoded : '—';
            }

            return (string) $state;
        };

        return $schema
            ->components([
                Section::make(__('scheduled_znuny_task_runs.sections.run_information'))
                    ->schema([
                        TextEntry::make('task_name_snapshot')->label(__('scheduled_znuny_task_runs.table.task_name_snapshot')),
                        TextEntry::make('run_type')
                            ->label(__('scheduled_znuny_task_runs.table.run_type'))
                            ->formatStateUsing(fn (?string $state): ?string => static::runTypeLabel($state)),
                        TextEntry::make('status')
                            ->label(__('scheduled_znuny_task_runs.table.status'))
                            ->formatStateUsing(fn (?string $state): ?string => static::statusLabel($state))
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'success' => 'success',
                                'pending' => 'warning',
                                'running' => 'info',
                                'failed' => 'danger',
                                'uncertain' => 'warning',
                                'skipped' => 'gray',
                                default => 'gray',
                            }),
                        TextEntry::make('scheduled_for')
                            ->label(__('scheduled_znuny_task_runs.table.scheduled_for'))
                            ->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->formatLocalizedDateTime($state)),
                        TextEntry::make('started_at')
                            ->label(__('scheduled_znuny_task_runs.table.started_at'))
                            ->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->formatLocalizedDateTime($state)),
                        TextEntry::make('finished_at')
                            ->label(__('scheduled_znuny_task_runs.table.finished_at'))
                            ->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->formatLocalizedDateTime($state)),
                        TextEntry::make('duration_ms')
                            ->label(__('scheduled_znuny_task_runs.table.duration_ms'))
                            ->formatStateUsing(fn ($state) => $state === null ? null : round(abs((int) $state) / 1000, 1).' '.__('scheduled_znuny_task_runs.units.sec')),
                    ])->columns(3),
                Section::make(__('scheduled_znuny_task_runs.sections.ticket_details'))
                    ->schema([
                        TextEntry::make('ticket_id'),
                        TextEntry::make('ticket_number')->label(__('scheduled_znuny_task_runs.table.ticket_number')),
                    ])->columns(2),
                Section::make(__('scheduled_znuny_task_runs.sections.errors'))
                    ->schema([
                        TextEntry::make('error_summary')->label(__('scheduled_znuny_task_runs.table.error_summary')),
                        TextEntry::make('error_details')->columnSpanFull(),
                    ]),
                Section::make(__('scheduled_znuny_task_runs.sections.snapshots'))
                    ->schema([
                        TextEntry::make('payload_snapshot')
                            ->columnSpanFull()
                            ->fontFamily('mono')
                            ->formatStateUsing($snapshotFormatter),
                        TextEntry::make('response_snapshot')
                            ->columnSpanFull()
                            ->fontFamily('mono')
                            ->formatStateUsing($snapshotFormatter),
                    ])->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('latestZnunyTicketCreationAttempt'))
            ->recordTitleAttribute('task_name_snapshot')
            ->defaultSort(fn (Builder $query) => $query->orderByRaw(
                "CASE
                    WHEN resolved_at IS NULL AND resolution_type IS NULL AND status = 'uncertain' THEN 0
                    WHEN resolved_at IS NULL AND resolution_type IS NULL AND status = 'failed' THEN 1
                    WHEN resolution_type IN ('manual_closed', 'manual_link') OR status = 'success' THEN 3
                    ELSE 2
                END ASC"
            )->orderBy('scheduled_for', 'desc')->orderBy('id', 'desc'))
            ->emptyStateHeading(__('scheduled_znuny_task_runs.empty_state'))
            ->columns([
                TextColumn::make('task_name_snapshot')
                    ->label(__('scheduled_znuny_task_runs.table.task_name_snapshot'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('scheduled_for')
                    ->label(__('scheduled_znuny_task_runs.table.scheduled_for'))
                    ->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->formatLocalizedDateTime($state))
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('scheduled_znuny_task_runs.table.status'))
                    ->formatStateUsing(function (?string $state, ScheduledZnunyTaskRun $record): ?string {
                        if ($record->resolution_type === 'manual_closed') {
                            return __('scheduled_znuny_task_runs.resolution_types.manual_closed');
                        }
                        if ($record->resolution_type === 'manual_link') {
                            return __('scheduled_znuny_task_runs.resolution_types.manual_link');
                        }
                        if ($record->resolution_type === 'retry_created') {
                            return __('scheduled_znuny_task_runs.resolution_types.retry_created');
                        }

                        return static::statusLabel($state);
                    })
                    ->badge()
                    ->color(function (string $state, ScheduledZnunyTaskRun $record): string {
                        if (in_array($record->resolution_type, ['manual_closed', 'manual_link'], true)) {
                            return 'success';
                        }
                        if ($record->resolution_type === 'retry_created') {
                            return 'gray';
                        }

                        return match ($state) {
                            'success' => 'success',
                            'pending' => 'warning',
                            'running' => 'info',
                            'failed' => 'danger',
                            'uncertain' => 'warning',
                            'skipped' => 'gray',
                            default => 'gray',
                        };
                    })
                    ->searchable(),
                TextColumn::make('retries')
                    ->label(__('scheduled_znuny_task_runs.table.retries'))
                    ->state(fn (ScheduledZnunyTaskRun $record): string => (string) $record->getKey())
                    ->html()
                    ->formatStateUsing(function (ScheduledZnunyTaskRun $record, Component $livewire): string {
                        if (($livewire instanceof ManageScheduledZnunyTaskRuns) === false) {
                            return Blade::render('<x-filament::badge color="danger">'.e(__('scheduled_znuny_task_runs.chain_states.malformed_chain')).'</x-filament::badge>');
                        }

                        $chainState = $livewire->getRunChainState((int) $record->id);

                        if ($chainState['detached_or_orphan'] === true) {
                            return Blade::render('<x-filament::badge color="danger">'.e(__('scheduled_znuny_task_runs.chain_states.detached_or_orphan')).'</x-filament::badge>');
                        }
                        if ($chainState['malformed_chain'] === true) {
                            return Blade::render('<x-filament::badge color="danger">'.e(__('scheduled_znuny_task_runs.chain_states.malformed_chain')).'</x-filament::badge>');
                        }

                        $total = $chainState['total_retries'] ?? 0;
                        $pos = $chainState['position'] ?? 0;

                        if ($total === 0) {
                            return '&mdash;';
                        }

                        if ($pos === 0) {
                            return e(trans_choice('scheduled_znuny_task_runs.chain_states.root_with_retries', $total, ['total' => $total]));
                        }

                        $text = e(__('scheduled_znuny_task_runs.chain_states.retry_position', ['position' => $pos, 'total' => $total]));

                        if ($chainState['current_leaf'] === true) {
                            $badge = Blade::render('<x-filament::badge color="info" size="sm" class="ml-2 inline-flex">'.e(__('scheduled_znuny_task_runs.chain_states.current')).'</x-filament::badge>');

                            return $text.$badge;
                        }

                        return $text;
                    })
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('ticket_number')
                    ->label(__('scheduled_znuny_task_runs.table.ticket_number'))
                    ->searchable(),
                TextColumn::make('started_at')
                    ->label(__('scheduled_znuny_task_runs.table.started_at'))
                    ->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->formatLocalizedDateTime($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('finished_at')
                    ->label(__('scheduled_znuny_task_runs.table.finished_at'))
                    ->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->formatLocalizedDateTime($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('error_summary')
                    ->label(__('scheduled_znuny_task_runs.table.error_summary'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('scheduled_znuny_task_id')
                    ->label(__('scheduled_znuny_task_runs.filters.scheduled_znuny_task_id'))
                    ->relationship('task', 'name'),
                SelectFilter::make('status')
                    ->label(__('scheduled_znuny_task_runs.filters.status'))
                    ->options([
                        'pending' => static::statusLabel('pending'),
                        'running' => static::statusLabel('running'),
                        'success' => static::statusLabel('success'),
                        'failed' => static::statusLabel('failed'),
                        'skipped' => static::statusLabel('skipped'),
                        'duplicate' => static::statusLabel('duplicate'),
                        'uncertain' => static::statusLabel('uncertain'),
                    ]),
                SelectFilter::make('run_type')
                    ->label(__('scheduled_znuny_task_runs.filters.run_type'))
                    ->options([
                        'scheduled' => static::runTypeLabel('scheduled'),
                        'manual' => static::runTypeLabel('manual'),
                        'catch_up' => static::runTypeLabel('catch_up'),
                        'manual_retry' => static::runTypeLabel('manual_retry'),
                    ]),
                TernaryFilter::make('has_ticket')
                    ->label(__('scheduled_znuny_task_runs.filters.has_ticket'))
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('ticket_number'),
                        false: fn ($query) => $query->whereNull('ticket_number'),
                    ),
                TernaryFilter::make('has_error')
                    ->label(__('scheduled_znuny_task_runs.filters.has_error'))
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('error_summary'),
                        false: fn ($query) => $query->whereNull('error_summary'),
                    ),
                Filter::make('created_at')
                    ->form([
                        DatePicker::make('from')->label(__('scheduled_znuny_task_runs.filters.created_at_from')),
                        DatePicker::make('until')->label(__('scheduled_znuny_task_runs.filters.created_at_until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->recordAction('view')
            ->recordActions([
                ViewAction::make()
                    ->visible(
                        fn (ManageScheduledZnunyTaskRuns $livewire): bool => $livewire->getMountedAction()?->getName() === 'view',
                    ),

                Action::make('review_attempt')
                    ->label(__('scheduled_znuny_task_runs.actions.review_attempt'))
                    ->icon('heroicon-o-magnifying-glass')
                    ->url(fn (ScheduledZnunyTaskRun $record): string => static::getUrl('review', ['record' => $record->root_run_id ?? $record->id]))
                    ->visible(function (ScheduledZnunyTaskRun $record, Component $livewire) {
                        if (($livewire instanceof ManageScheduledZnunyTaskRuns) === false) {
                            return false;
                        }

                        if ($record->resolved_at !== null) {
                            return false;
                        }

                        if (in_array($record->status, ['failed', 'uncertain'], true) === false) {
                            return false;
                        }

                        $chainState = $livewire->getRunChainState((int) $record->id);

                        return $chainState['valid_chain'] === true
                            && $chainState['current_leaf'] === true
                            && $chainState['malformed_chain'] === false
                            && $chainState['detached_or_orphan'] === false;
                    }),

                Action::make('manual_close')
                    ->label(__('scheduled_znuny_task_runs.review.actions.manual_close.label'))
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(__('scheduled_znuny_task_runs.review.actions.manual_close.modal_heading'))
                    ->modalDescription(__('scheduled_znuny_task_runs.review.actions.manual_close.modal_description'))
                    ->modalSubmitActionLabel(__('scheduled_znuny_task_runs.review.actions.manual_close.submit'))
                    ->visible(function (ScheduledZnunyTaskRun $record, Component $livewire) {
                        if (($livewire instanceof ManageScheduledZnunyTaskRuns) === false) {
                            return false;
                        }

                        if ($record->resolved_at !== null) {
                            return false;
                        }

                        if (in_array($record->status, ['failed', 'uncertain'], true) === false) {
                            return false;
                        }

                        $chainState = $livewire->getRunChainState((int) $record->id);

                        return $chainState['valid_chain'] === true
                            && $chainState['current_leaf'] === true
                            && $chainState['malformed_chain'] === false
                            && $chainState['detached_or_orphan'] === false;
                    })
                    ->action(function (ScheduledZnunyTaskRun $record, ScheduledZnunyTaskRunCloseService $closeService) {
                        $actor = auth()->user();
                        if (($actor instanceof User) === false) {
                            Notification::make()
                                ->title(__('scheduled_znuny_task_runs.review.notifications.unexpected_error.title'))
                                ->body(__('scheduled_znuny_task_runs.review.notifications.unexpected_error.body'))
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            $result = $closeService->close($record->id, $actor);
                        } catch (Throwable) {
                            Notification::make()
                                ->title(__('scheduled_znuny_task_runs.review.notifications.unexpected_error.title'))
                                ->body(__('scheduled_znuny_task_runs.review.notifications.unexpected_error.body'))
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->refresh();

                        if ($result['closed'] ?? false) {
                            Notification::make()
                                ->title(__('scheduled_znuny_task_runs.review.notifications.manual_close_success.title'))
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title(__('scheduled_znuny_task_runs.review.notifications.manual_close_failed.title'))
                                ->body(__('scheduled_znuny_task_runs.review.notifications.manual_close_failed.body'))
                                ->warning()
                                ->send();
                        }
                    }),

                Action::make('open_ticket')
                    ->label(__('scheduled_znuny_task_runs.actions.open_ticket'))
                    ->iconButton()
                    ->tooltip(__('scheduled_znuny_task_runs.actions.open_ticket'))
                    ->icon('heroicon-o-ticket')
                    ->url(fn (ScheduledZnunyTaskRun $record): ?string => $record->ticket_id
                        ? app(ZnunyClient::class)->ticketUrl($record->ticket_id)
                        : null)
                    ->openUrlInNewTab()
                    ->visible(fn (ScheduledZnunyTaskRun $record) => ! empty($record->ticket_id)),

                Action::make('open_task')
                    ->label(__('scheduled_znuny_task_runs.actions.open_task'))
                    ->iconButton()
                    ->tooltip(__('scheduled_znuny_task_runs.actions.open_task'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (ScheduledZnunyTaskRun $record): ?string => $record->scheduled_znuny_task_id && ! $record->task?->trashed()
                        ? ScheduledZnunyTaskResource::getUrl('edit', ['record' => $record->scheduled_znuny_task_id])
                        : null)
                    ->hidden(fn (ScheduledZnunyTaskRun $record) => ! $record->scheduled_znuny_task_id || $record->task?->trashed()),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageScheduledZnunyTaskRuns::route('/'),
            'review' => Pages\ReviewScheduledZnunyTaskRunAttempt::route('/{record}/review'),
        ];
    }
}
