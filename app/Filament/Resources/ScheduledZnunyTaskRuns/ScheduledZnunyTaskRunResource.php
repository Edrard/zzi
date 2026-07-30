<?php

namespace App\Filament\Resources\ScheduledZnunyTaskRuns;

use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Filament\Resources\ScheduledZnunyTaskRuns\Pages\ManageScheduledZnunyTaskRuns;
use App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource;
use App\Models\ScheduledZnunyTaskRun;
use App\Services\Support\DateTimeDisplayService;
use App\Services\Znuny\ZnunyClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
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
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('scheduled_znuny_task_runs.empty_state'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('scheduled_znuny_task_runs.table.created_at'))
                    ->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->formatLocalizedDateTime($state))
                    ->sortable(),
                TextColumn::make('task_name_snapshot')
                    ->label(__('scheduled_znuny_task_runs.table.task_name_snapshot'))
                    ->searchable(),
                TextColumn::make('run_type')
                    ->label(__('scheduled_znuny_task_runs.table.run_type'))
                    ->formatStateUsing(fn (?string $state): ?string => static::runTypeLabel($state))
                    ->searchable(),
                TextColumn::make('scheduled_for')
                    ->label(__('scheduled_znuny_task_runs.table.scheduled_for'))
                    ->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->formatLocalizedDateTime($state))
                    ->sortable(),
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
                TextColumn::make('duration_ms')
                    ->label(__('scheduled_znuny_task_runs.table.duration_ms'))
                    ->formatStateUsing(fn ($state) => $state === null ? null : round(abs((int) $state) / 1000, 1).' '.__('scheduled_znuny_task_runs.units.sec'))
                    ->sortable(),
                TextColumn::make('status')
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
                    })
                    ->searchable(),
                TextColumn::make('ticket_number')
                    ->label(__('scheduled_znuny_task_runs.table.ticket_number'))
                    ->searchable(),
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
            ->recordActions([
                ViewAction::make(),

                Action::make('review_attempt')
                    ->label(__('scheduled_znuny_task_runs.actions.review_attempt'))
                    ->icon('heroicon-o-magnifying-glass')
                    ->url(fn (ScheduledZnunyTaskRun $record): string => static::getUrl('review', ['record' => $record]))
                    ->visible(function (ScheduledZnunyTaskRun $record) {
                        return $record->status === 'uncertain'
                            && $record->latestZnunyTicketCreationAttempt !== null
                            && $record->latestZnunyTicketCreationAttempt->status === ZnunyTicketCreationAttemptStatus::Uncertain;
                    }),

                Action::make('open_ticket')
                    ->label(__('scheduled_znuny_task_runs.actions.open_ticket'))
                    ->icon('heroicon-o-ticket')
                    ->url(fn (ScheduledZnunyTaskRun $record): ?string => $record->ticket_id
                        ? app(ZnunyClient::class)->ticketUrl($record->ticket_id)
                        : null)
                    ->openUrlInNewTab()
                    ->visible(fn (ScheduledZnunyTaskRun $record) => ! empty($record->ticket_id)),

                Action::make('open_task')
                    ->label(__('scheduled_znuny_task_runs.actions.open_task'))
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
