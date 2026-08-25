<?php

namespace App\Filament\Resources\ZabbixTickets\Pages;

use App\Filament\Resources\ZabbixTickets\ZabbixTicketResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Artisan;

class ListZabbixTickets extends ListRecords
{
    protected static string $resource = ZabbixTicketResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('zabbix_tickets.navigation.plural');
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync_tickets')
                ->label(__('zabbix_tickets.actions.sync_tickets.label'))
                ->icon('heroicon-o-cloud-arrow-down')
                ->action(function () {
                    try {
                        $syncExitCode = Artisan::call('znuny:sync-linked-tickets', ['--manual' => true]);
                        $syncOutput = trim(Artisan::output());

                        if ($syncExitCode === 0) {
                            $evalExitCode = Artisan::call('znuny:evaluate-manual-ticket-lifecycle');
                            $evalOutput = trim(Artisan::output());

                            $message = __('zabbix_tickets.actions.sync_tickets.notifications.success_completed')."\n";
                            if ($syncOutput) {
                                $message .= $syncOutput."\n";
                            }

                            if ($evalExitCode === 0) {
                                $message .= __('zabbix_tickets.actions.sync_tickets.notifications.lifecycle_completed');
                                Notification::make()
                                    ->title(__('zabbix_tickets.actions.sync_tickets.notifications.success_title'))
                                    ->body($message)
                                    ->success()
                                    ->send();
                            } else {
                                $message .= __('zabbix_tickets.actions.sync_tickets.notifications.lifecycle_failed')."\n".$evalOutput;
                                Notification::make()
                                    ->title(__('zabbix_tickets.actions.sync_tickets.notifications.errors_title'))
                                    ->body($message)
                                    ->danger()
                                    ->send();
                            }
                        } else {
                            $message = __('zabbix_tickets.actions.sync_tickets.notifications.failed_incomplete')."\n".$syncOutput;
                            Notification::make()
                                ->title(__('zabbix_tickets.actions.sync_tickets.notifications.failed_title'))
                                ->body($message)
                                ->danger()
                                ->send();
                        }
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title(__('zabbix_tickets.actions.sync_tickets.notifications.failed_title'))
                            ->body(__('zabbix_tickets.actions.sync_tickets.notifications.failed_error'))
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
