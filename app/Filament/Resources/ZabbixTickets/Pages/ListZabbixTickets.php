<?php

namespace App\Filament\Resources\ZabbixTickets\Pages;

use App\Filament\Resources\ZabbixTickets\ZabbixTicketResource;
use App\Services\Znuny\ZnunyLinkedTicketSyncService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListZabbixTickets extends ListRecords
{
    protected static string $resource = ZabbixTicketResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync_tickets')
                ->label('Sync Tickets')
                ->icon('heroicon-o-cloud-arrow-down')
                ->action(function () {
                    try {
                        $service = app(ZnunyLinkedTicketSyncService::class);
                        $result = $service->sync(0, null, true);

                        $message = sprintf(
                            'Sync completed: %d scanned, %d updated, %d reconciled, %d unchanged, %d missing, %d failed.',
                            $result['scanned'] ?? 0,
                            $result['synced'] ?? 0,
                            $result['reconciled'] ?? 0,
                            $result['unchanged'] ?? 0,
                            $result['missing'] ?? 0,
                            $result['failed'] ?? 0
                        );

                        if (($result['failed'] ?? 0) > 0) {
                            Notification::make()
                                ->title('Sync completed with errors')
                                ->body($message)
                                ->danger()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Sync successful')
                                ->body($message)
                                ->success()
                                ->send();
                        }
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Sync failed')
                            ->body('An error occurred during synchronization.')
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
