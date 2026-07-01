<?php

namespace App\Filament\Resources\ZabbixTickets\Schemas;

use App\Filament\Support\TicketDetailsPayload;
use App\Services\Support\DateTimeDisplayService;
use App\Services\Znuny\ZnunyTicketArticleCacheService;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
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
                                    TextEntry::make('znuny_ticket_number')->label(self::formatLabel('Number'))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->znuny_ticket_number)->inlineLabel()->placeholder('-'),
                                    TextEntry::make('title')->label(self::formatLabel('Title'))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->title)->inlineLabel()->visible(fn ($record) => TicketDetailsPayload::fromRecord($record)->title !== null)->placeholder('-'),
                                    TextEntry::make('created_at')->label(self::formatLabel('Created/Age'))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->created_at)->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->diffForHumans($state))->inlineLabel()->placeholder('-'),
                                    TextEntry::make('changed_at')->label(self::formatLabel('Changed'))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->changed_at)->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->diffForHumans($state))->inlineLabel()->visible(fn ($record) => TicketDetailsPayload::fromRecord($record)->changed_at !== null),
                                    TextEntry::make('manual_reopened_at')->label(self::formatLabel('Reopened at'))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->manual_reopened_at)->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->formatDateTime($state))->inlineLabel()->visible(fn ($record) => TicketDetailsPayload::fromRecord($record)->manual_reopened_at !== null),
                                    TextEntry::make('resolution_context')
                                        ->label(self::formatLabel('Context'))
                                        ->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->resolution_context['label'] ?? null)
                                        ->badge()
                                        ->color(fn ($record) => TicketDetailsPayload::fromRecord($record)->resolution_context['color'] ?? 'gray')
                                        ->icon(fn ($record) => TicketDetailsPayload::fromRecord($record)->resolution_context['icon'] ?? null)
                                        ->tooltip(fn ($record) => TicketDetailsPayload::fromRecord($record)->resolution_context['tooltip'] ?? null)
                                        ->inlineLabel(),
                                    TextEntry::make('zabbix_problem_resolved_at')
                                        ->label(self::formatLabel('Resolved At'))
                                        ->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->zabbix_problem_resolved_at ? app(DateTimeDisplayService::class)->formatDateTime(TicketDetailsPayload::fromRecord($record)->zabbix_problem_resolved_at) : null)
                                        ->visible(fn ($record) => ! empty(TicketDetailsPayload::fromRecord($record)->zabbix_problem_resolved_at))
                                        ->inlineLabel(),
                                    TextEntry::make('manual_close_eligible_at')
                                        ->label(self::formatLabel('Auto-Close At'))
                                        ->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->manual_close_eligible_at ? app(DateTimeDisplayService::class)->formatDateTime(TicketDetailsPayload::fromRecord($record)->manual_close_eligible_at) : null)
                                        ->visible(fn ($record) => ! empty(TicketDetailsPayload::fromRecord($record)->manual_close_eligible_at))
                                        ->inlineLabel(),
                                    TextEntry::make('znuny_ticket_closed_at')
                                        ->label(self::formatLabel('Closed At'))
                                        ->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->znuny_ticket_closed_at ? app(DateTimeDisplayService::class)->formatDateTime(TicketDetailsPayload::fromRecord($record)->znuny_ticket_closed_at) : null)
                                        ->visible(fn ($record) => ! empty(TicketDetailsPayload::fromRecord($record)->znuny_ticket_closed_at))
                                        ->inlineLabel(),
                                    TextEntry::make('manual_flap_count')
                                        ->label(self::formatLabel('Flap Count'))
                                        ->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->manual_flap_count)
                                        ->visible(fn ($record) => TicketDetailsPayload::fromRecord($record)->manual_flap_count > 0)
                                        ->inlineLabel(),
                                    TextEntry::make('manual_last_flap_counted_at')
                                        ->label(self::formatLabel('Last Flap At'))
                                        ->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->manual_last_flap_counted_at ? app(DateTimeDisplayService::class)->formatDateTime(TicketDetailsPayload::fromRecord($record)->manual_last_flap_counted_at) : null)
                                        ->visible(fn ($record) => ! empty(TicketDetailsPayload::fromRecord($record)->manual_last_flap_counted_at))
                                        ->inlineLabel(),
                                ])->columns(1),
                        ]),

                        Group::make([
                            Section::make('Znuny Attributes')
                                ->compact()
                                ->schema([
                                    TextEntry::make('znuny_queue_name')->label(self::formatLabel('Queue'))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->znuny_queue_name)->inlineLabel()->placeholder('-'),
                                    TextEntry::make('znuny_owner_name')->label(self::formatLabel('Owner'))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->znuny_owner_name)->inlineLabel()->placeholder('-'),
                                    TextEntry::make('customer_user')->label(self::formatLabel('Customer'))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->customer_user)->inlineLabel()->visible(fn ($record) => TicketDetailsPayload::fromRecord($record)->customer_user !== null)->placeholder('-'),
                                    TextEntry::make('znuny_priority')->label(self::formatLabel('Priority'))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->znuny_priority)->inlineLabel()->placeholder('-'),
                                    TextEntry::make('znuny_state_name')->label(self::formatLabel('State'))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->znuny_state_name)->inlineLabel()->placeholder('-'),
                                    TextEntry::make('lock_status')->label(self::formatLabel('Lock status'))->state(function ($record) {
                                        $lock = TicketDetailsPayload::fromRecord($record)->lock;
                                        if ($lock === 'lock') {
                                            return 'Locked';
                                        } elseif ($lock === 'unlock') {
                                            return 'Unlocked';
                                        }

                                        return 'Unknown';
                                    })->inlineLabel(),
                                    TextEntry::make('last_article')->label(self::formatLabel('Last Article'))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->last_article)->formatStateUsing(fn ($state) => app(DateTimeDisplayService::class)->formatDateTime($state))->inlineLabel()->visible(fn ($record) => TicketDetailsPayload::fromRecord($record)->last_article !== null)->placeholder('-'),
                                ])->columns(1),

                            Section::make('Zabbix')
                                ->compact()
                                ->visible(fn ($record) => TicketDetailsPayload::fromRecord($record)->has_zabbix_link)
                                ->schema([
                                    TextEntry::make('zabbix_host_name')->label(self::formatLabel('Host'))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->zabbix_host_name)->inlineLabel()->placeholder('-'),
                                    TextEntry::make('zabbix_problem_name')->label(self::formatLabel('Problem'))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->zabbix_problem_name)->inlineLabel()->placeholder('-'),
                                    TextEntry::make('zabbix_event_id')->label(self::formatLabel('Event ID'))->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->zabbix_event_id)->inlineLabel()->visible(fn ($record) => TicketDetailsPayload::fromRecord($record)->zabbix_event_id !== null)->placeholder('-'),
                                ])->columns(1),

                            Section::make('Sync')
                                ->compact()
                                ->visible(fn ($record) => TicketDetailsPayload::fromRecord($record)->has_sync_section)
                                ->schema([
                                    TextEntry::make('znuny_ticket_last_checked_at')
                                        ->label(self::formatLabel('Last Checked'))
                                        ->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->znuny_ticket_last_checked_at ? app(DateTimeDisplayService::class)->formatDateTime(TicketDetailsPayload::fromRecord($record)->znuny_ticket_last_checked_at) : null)
                                        ->inlineLabel()->placeholder('-'),
                                    TextEntry::make('znuny_ticket_last_synced_at')
                                        ->label(self::formatLabel('Last Synced'))
                                        ->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->znuny_ticket_last_synced_at ? app(DateTimeDisplayService::class)->formatDateTime(TicketDetailsPayload::fromRecord($record)->znuny_ticket_last_synced_at) : null)
                                        ->inlineLabel()->placeholder('-'),
                                    TextEntry::make('znuny_ticket_sync_error')->label(self::formatLabel('Sync Error'))
                                        ->state(fn ($record) => TicketDetailsPayload::fromRecord($record)->znuny_ticket_sync_error)
                                        ->color('danger')
                                        ->inlineLabel()
                                        ->placeholder('-'),
                                ])->columns(1),
                        ]),
                    ]),
                Section::make('Articles / Notes')
                    ->compact()
                    ->schema([
                        ViewEntry::make('articles_notes')
                            ->hiddenLabel()
                            ->getStateUsing(function ($record) {
                                $payload = TicketDetailsPayload::fromRecord($record);
                                if (! $payload->znuny_ticket_id) {
                                    return [];
                                }

                                return app(ZnunyTicketArticleCacheService::class)->get($payload->znuny_ticket_id);
                            })
                            ->view('filament.infolists.articles-accordion'),
                    ]),
            ]);
    }
}
