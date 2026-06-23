<?php

namespace App\Filament\Resources\ZabbixTickets\Schemas;

use App\Models\ZabbixTicket;
use App\Services\Support\DateTimeDisplayService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ZabbixTicketInfolist
{
    private static function formatLabel(string $label): HtmlString
    {
        return new HtmlString('<span style="color: light-dark(#6b7280, #bbb); font-weight: 400; font-size: 0.875rem;">'.e($label).'</span>');
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'sm' => 2])
                    ->schema([
                        Group::make([
                            Section::make('Ticket')
                                ->compact()
                                ->schema([
                                    TextEntry::make('znuny_ticket_number')->label(self::formatLabel('Number'))->inlineLabel()->placeholder('-'),
                                    TextEntry::make('created_at')->label(self::formatLabel('Age'))->since()->inlineLabel()->placeholder('-'),
                                    TextEntry::make('manual_reopened_at')->label(self::formatLabel('Reopened at'))->dateTime()->inlineLabel()->visible(fn (ZabbixTicket $record) => $record->manual_reopened_at !== null),
                                    TextEntry::make('resolution_context')
                                        ->label(self::formatLabel('Context'))
                                        ->state(function (ZabbixTicket $record) {
                                            if ($record->manual_lifecycle_status === 'flapping') {
                                                return 'Flapping';
                                            }
                                            if ($record->manual_lifecycle_status === 'reopen_candidate') {
                                                return 'Manual reopen candidate';
                                            }
                                            if ($record->manual_lifecycle_status === 'reopened' || $record->manual_reopened_at !== null) {
                                                return 'Reopened';
                                            }
                                            $isClosed = strtolower($record->znuny_ticket_state_type ?? '') === 'closed' || str_contains(strtolower((string) $record->znuny_state_name), 'closed');
                                            if ($isClosed) {
                                                return 'Closed';
                                            }
                                            if ($record->manual_lifecycle_status === 'close_candidate') {
                                                return 'Ready';
                                            }
                                            if ($record->manual_lifecycle_status === 'resolved_waiting') {
                                                return 'Waiting for close delay';
                                            }
                                            if ($record->manual_lifecycle_status === 'cache_stale') {
                                                return 'Cache stale';
                                            }
                                            if ($record->manual_lifecycle_status === 'identity_missing') {
                                                return 'Missing Zabbix identity';
                                            }
                                            if ($record->manual_lifecycle_status === 'active' || $record->zabbix_problem_is_active === true) {
                                                return 'Zabbix problem active';
                                            }

                                            return 'Unknown';
                                        })
                                        ->badge()
                                        ->color(function (ZabbixTicket $record) {
                                            if ($record->manual_lifecycle_status === 'flapping') {
                                                return 'danger';
                                            }
                                            if ($record->manual_lifecycle_status === 'reopen_candidate') {
                                                return 'warning';
                                            }
                                            if ($record->manual_lifecycle_status === 'reopened' || $record->manual_reopened_at !== null) {
                                                return 'info';
                                            }
                                            $isClosed = strtolower($record->znuny_ticket_state_type ?? '') === 'closed' || str_contains(strtolower((string) $record->znuny_state_name), 'closed');
                                            if ($isClosed) {
                                                return 'gray';
                                            }
                                            if ($record->manual_lifecycle_status === 'close_candidate') {
                                                return 'success';
                                            }
                                            if ($record->manual_lifecycle_status === 'resolved_waiting') {
                                                return 'info';
                                            }
                                            if ($record->manual_lifecycle_status === 'cache_stale') {
                                                return 'warning';
                                            }
                                            if ($record->manual_lifecycle_status === 'active' || $record->zabbix_problem_is_active === true) {
                                                return 'danger';
                                            }

                                            return 'gray';
                                        })
                                        ->icon(function (ZabbixTicket $record) {
                                            if ($record->manual_lifecycle_status === 'flapping') {
                                                return 'heroicon-o-exclamation-triangle';
                                            }
                                            if ($record->manual_lifecycle_status === 'reopen_candidate') {
                                                return 'heroicon-o-arrow-path';
                                            }
                                            if ($record->manual_lifecycle_status === 'reopened' || $record->manual_reopened_at !== null) {
                                                return 'heroicon-o-arrow-uturn-left';
                                            }

                                            return null;
                                        })
                                        ->tooltip(function (ZabbixTicket $record) {
                                            if ($record->manual_lifecycle_status === 'flapping') {
                                                return 'Flapping problem detected.';
                                            }
                                            if ($record->manual_lifecycle_status === 'reopen_candidate') {
                                                return 'The Znuny ticket is closed, but the linked Zabbix problem is active again within the reopen window. Review manually.';
                                            }
                                            if ($record->manual_lifecycle_status === 'reopened' || $record->manual_reopened_at !== null) {
                                                return 'Manually reopened ticket.';
                                            }
                                            if ($record->manual_lifecycle_status === 'identity_missing') {
                                                return 'Missing Zabbix host/trigger identity; lifecycle cannot be evaluated safely.';
                                            }

                                            return null;
                                        })
                                        ->inlineLabel(),
                                    TextEntry::make('zabbix_problem_resolved_at')
                                        ->label(self::formatLabel('Resolved At'))
                                        ->state(fn (?ZabbixTicket $record) => $record && $record->zabbix_problem_resolved_at ? app(DateTimeDisplayService::class)->formatDateTimeWithTimezone($record->zabbix_problem_resolved_at) : null)
                                        ->visible(fn (?ZabbixTicket $record) => $record && ! empty($record->zabbix_problem_resolved_at))
                                        ->inlineLabel(),
                                    TextEntry::make('manual_close_eligible_at')
                                        ->label(self::formatLabel('Auto-Close At'))
                                        ->state(fn (?ZabbixTicket $record) => $record && $record->manual_close_eligible_at ? app(DateTimeDisplayService::class)->formatDateTimeWithTimezone($record->manual_close_eligible_at) : null)
                                        ->visible(fn (?ZabbixTicket $record) => $record && ! empty($record->manual_close_eligible_at))
                                        ->inlineLabel(),
                                    TextEntry::make('znuny_ticket_closed_at')
                                        ->label(self::formatLabel('Closed At'))
                                        ->state(fn (?ZabbixTicket $record) => $record && $record->znuny_ticket_closed_at ? app(DateTimeDisplayService::class)->formatDateTimeWithTimezone($record->znuny_ticket_closed_at) : null)
                                        ->visible(fn (?ZabbixTicket $record) => $record && ! empty($record->znuny_ticket_closed_at))
                                        ->inlineLabel(),
                                    TextEntry::make('manual_flap_count')
                                        ->label(self::formatLabel('Flap Count'))
                                        ->visible(fn (?ZabbixTicket $record) => $record && $record->manual_flap_count > 0)
                                        ->inlineLabel(),
                                    TextEntry::make('manual_last_flap_counted_at')
                                        ->label(self::formatLabel('Last Flap At'))
                                        ->state(fn (?ZabbixTicket $record) => $record && $record->manual_last_flap_counted_at ? app(DateTimeDisplayService::class)->formatDateTimeWithTimezone($record->manual_last_flap_counted_at) : null)
                                        ->visible(fn (?ZabbixTicket $record) => $record && ! empty($record->manual_last_flap_counted_at))
                                        ->inlineLabel(),
                                ])->columns(1),

                            Section::make('Zabbix')
                                ->compact()
                                ->schema([
                                    TextEntry::make('zabbix_host_name')->label(self::formatLabel('Host'))->inlineLabel()->placeholder('-'),
                                    TextEntry::make('zabbix_problem_name')->label(self::formatLabel('Problem'))->inlineLabel()->placeholder('-'),
                                ])->columns(1),
                        ]),

                        Group::make([
                            Section::make('Znuny Snapshot')
                                ->compact()
                                ->schema([
                                    TextEntry::make('znuny_queue_name')->label(self::formatLabel('Queue'))->inlineLabel()->placeholder('-'),
                                    TextEntry::make('znuny_owner_name')->label(self::formatLabel('Owner'))->inlineLabel()->placeholder('-'),
                                    TextEntry::make('znuny_priority')->label(self::formatLabel('Priority'))->inlineLabel()->placeholder('-'),
                                    TextEntry::make('znuny_state_name')->label(self::formatLabel('State'))->inlineLabel()->placeholder('-'),
                                ])->columns(1),

                            Section::make('Sync')
                                ->compact()
                                ->schema([
                                    TextEntry::make('znuny_ticket_last_checked_at')
                                        ->label(self::formatLabel('Last Checked'))
                                        ->state(fn (ZabbixTicket $record) => app(DateTimeDisplayService::class)->formatDateTimeWithTimezone($record->znuny_ticket_last_checked_at))
                                        ->inlineLabel()->placeholder('-'),
                                    TextEntry::make('znuny_ticket_last_synced_at')
                                        ->label(self::formatLabel('Last Synced'))
                                        ->state(fn (ZabbixTicket $record) => app(DateTimeDisplayService::class)->formatDateTimeWithTimezone($record->znuny_ticket_last_synced_at))
                                        ->inlineLabel()->placeholder('-'),
                                    TextEntry::make('znuny_ticket_sync_error')->label(self::formatLabel('Sync Error'))
                                        ->color('danger')
                                        ->inlineLabel()
                                        ->placeholder('-'),
                                ])->columns(1),
                        ]),
                    ]),
            ]);
    }
}
