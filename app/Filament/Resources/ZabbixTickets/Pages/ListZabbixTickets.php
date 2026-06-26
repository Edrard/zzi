<?php

namespace App\Filament\Resources\ZabbixTickets\Pages;

use App\Filament\Resources\ZabbixTickets\ZabbixTicketResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Artisan;

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
                        $syncExitCode = Artisan::call('znuny:sync-linked-tickets', ['--manual' => true]);
                        $syncOutput = trim(Artisan::output());

                        if ($syncExitCode === 0) {
                            $evalExitCode = Artisan::call('znuny:evaluate-manual-ticket-lifecycle');
                            $evalOutput = trim(Artisan::output());

                            $message = "Sync command completed.\n";
                            if ($syncOutput) {
                                $message .= $syncOutput."\n";
                            }

                            if ($evalExitCode === 0) {
                                $message .= 'Lifecycle evaluation completed.';
                                Notification::make()
                                    ->title('Sync successful')
                                    ->body($message)
                                    ->success()
                                    ->send();
                            } else {
                                $message .= "Lifecycle evaluation failed.\n".$evalOutput;
                                Notification::make()
                                    ->title('Sync completed with errors')
                                    ->body($message)
                                    ->danger()
                                    ->send();
                            }
                        } else {
                            $message = "The sync command failed to complete.\n".$syncOutput;
                            Notification::make()
                                ->title('Sync failed')
                                ->body($message)
                                ->danger()
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
