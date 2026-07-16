<?php

namespace App\Filament\Resources\ScheduledZnunyTaskRuns;

use App\Filament\Resources\ScheduledZnunyTaskRuns\Pages\ManageScheduledZnunyTaskRuns;
use App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource;
use App\Models\ScheduledZnunyTaskRun;
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

class ScheduledZnunyTaskRunResource extends Resource
{
    protected static ?string $model = ScheduledZnunyTaskRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Scheduler Log';

    protected static ?string $modelLabel = 'Scheduler Log';

    protected static ?string $pluralModelLabel = 'Scheduler Logs';

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
        return $schema
            ->components([
                Section::make('Run Information')
                    ->schema([
                        TextEntry::make('task_name_snapshot')->label('Task'),
                        TextEntry::make('run_type'),
                        TextEntry::make('status')
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
                        TextEntry::make('scheduled_for')->dateTime()->timezone(fn ($record) => $record?->task?->timezone ?? config('app.timezone')),
                        TextEntry::make('started_at')->dateTime()->timezone(fn ($record) => $record?->task?->timezone ?? config('app.timezone')),
                        TextEntry::make('finished_at')->dateTime()->timezone(fn ($record) => $record?->task?->timezone ?? config('app.timezone')),
                        TextEntry::make('duration_ms')->label('Execution time')->formatStateUsing(fn ($state) => $state === null ? null : round(abs((int) $state) / 1000, 1).' sec'),
                    ])->columns(3),
                Section::make('Ticket Details')
                    ->schema([
                        TextEntry::make('ticket_id'),
                        TextEntry::make('ticket_number'),
                    ])->columns(2),
                Section::make('Errors')
                    ->schema([
                        TextEntry::make('error_summary'),
                        TextEntry::make('error_details')->columnSpanFull(),
                    ]),
                Section::make('Snapshots')
                    ->schema([
                        TextEntry::make('payload_snapshot')->columnSpanFull(),
                        TextEntry::make('response_snapshot')->columnSpanFull(),
                    ])->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('task_name_snapshot')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime()
                    ->sortable()
                    ->timezone(fn ($record) => $record?->task?->timezone ?? config('app.timezone')),
                TextColumn::make('task_name_snapshot')
                    ->label('Task')
                    ->searchable(),
                TextColumn::make('run_type')
                    ->searchable(),
                TextColumn::make('scheduled_for')
                    ->dateTime()
                    ->sortable()
                    ->timezone(fn ($record) => $record?->task?->timezone ?? config('app.timezone')),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->timezone(fn ($record) => $record?->task?->timezone ?? config('app.timezone')),
                TextColumn::make('finished_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->timezone(fn ($record) => $record?->task?->timezone ?? config('app.timezone')),
                TextColumn::make('duration_ms')
                    ->label('Execution time')
                    ->formatStateUsing(fn ($state) => $state === null ? null : round(abs((int) $state) / 1000, 1).' sec')
                    ->sortable(),
                TextColumn::make('status')
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
                    ->searchable(),
                TextColumn::make('error_summary')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('scheduled_znuny_task_id')
                    ->label('Task')
                    ->relationship('task', 'name'),
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'running' => 'Running',
                        'success' => 'Success',
                        'failed' => 'Failed',
                        'skipped' => 'Skipped',
                        'duplicate' => 'Duplicate',
                        'uncertain' => 'Uncertain',
                    ]),
                SelectFilter::make('run_type')
                    ->options([
                        'scheduled' => 'Scheduled',
                        'manual' => 'Manual',
                        'catch_up' => 'Catch Up',
                        'manual_retry' => 'Manual Retry',
                    ]),
                TernaryFilter::make('has_ticket')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('ticket_number'),
                        false: fn ($query) => $query->whereNull('ticket_number'),
                    ),
                TernaryFilter::make('has_error')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('error_summary'),
                        false: fn ($query) => $query->whereNull('error_summary'),
                    ),
                Filter::make('created_at')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
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

                Action::make('requeue_failed_run')
                    ->label('Requeue Run')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('danger')
                    ->visible(fn (ScheduledZnunyTaskRun $record) => $record->status === 'failed')
                    ->requiresConfirmation()
                    ->action(function (ScheduledZnunyTaskRun $record) {
                        ScheduledZnunyTaskRun::create([
                            'scheduled_znuny_task_id' => $record->scheduled_znuny_task_id,
                            'task_name_snapshot' => $record->task_name_snapshot,
                            'run_type' => 'manual_retry',
                            'status' => 'pending',
                            'scheduled_for' => now('UTC')->toDateTimeString(),
                            'created_by' => auth()->id(),
                        ]);
                        Notification::make()->title('Run Requeued')->body('A new pending run has been created.')->success()->send();
                    }),

                Action::make('resolve_uncertain_run')
                    ->label('Resolve Run')
                    ->icon('heroicon-o-check-circle')
                    ->color('warning')
                    ->visible(fn (ScheduledZnunyTaskRun $record) => $record->status === 'uncertain')
                    ->form([
                        Textarea::make('note')
                            ->label('Manual Review Note')
                            ->required()
                            ->helperText('Explain how this uncertain run was resolved manually in Znuny.'),
                    ])
                    ->action(function (ScheduledZnunyTaskRun $record, array $data) {
                        $record->update([
                            'status' => 'skipped',
                            'error_summary' => 'Uncertain run manually reviewed; no automatic retry performed.',
                            'error_details' => "Note: {$data['note']}\nOriginal error: ".$record->error_details,
                        ]);

                        Notification::make()->title('Run Resolved')->success()->send();
                    }),

                Action::make('open_ticket')
                    ->label('Open Ticket')
                    ->icon('heroicon-o-ticket')
                    ->url(fn (ScheduledZnunyTaskRun $record): ?string => $record->ticket_id
                        ? app(ZnunyClient::class)->ticketUrl($record->ticket_id)
                        : null)
                    ->openUrlInNewTab()
                    ->visible(fn (ScheduledZnunyTaskRun $record) => ! empty($record->ticket_id)),

                Action::make('open_task')
                    ->label('Open Task')
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
        ];
    }
}
