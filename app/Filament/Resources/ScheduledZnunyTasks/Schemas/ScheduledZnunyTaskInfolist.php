<?php

namespace App\Filament\Resources\ScheduledZnunyTasks\Schemas;

use App\Models\ScheduledZnunyTask;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ScheduledZnunyTaskInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                IconEntry::make('enabled')
                    ->boolean(),
                TextEntry::make('name'),
                TextEntry::make('cron_expression')
                    ->placeholder('-'),
                TextEntry::make('timezone')
                    ->placeholder('-'),
                TextEntry::make('next_run_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('queue_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('queue_name')
                    ->placeholder('-'),
                TextEntry::make('owner_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('owner_login')
                    ->placeholder('-'),
                TextEntry::make('customer_user_login')
                    ->placeholder('-'),
                TextEntry::make('type_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('type_name')
                    ->placeholder('-'),
                TextEntry::make('priority_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('priority_name')
                    ->placeholder('-'),
                TextEntry::make('state_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('state_name')
                    ->placeholder('-'),
                TextEntry::make('service_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('service_name')
                    ->placeholder('-'),
                TextEntry::make('sla_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('sla_name')
                    ->placeholder('-'),
                TextEntry::make('subject')
                    ->placeholder('-'),
                TextEntry::make('body')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('last_run_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('last_success_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('last_failure_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('last_status')
                    ->placeholder('-'),
                TextEntry::make('last_ticket_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('last_ticket_number')
                    ->placeholder('-'),
                TextEntry::make('last_error_summary')
                    ->placeholder('-'),
                TextEntry::make('created_by')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('updated_by')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('deleted_at')
                    ->dateTime()
                    ->visible(fn (ScheduledZnunyTask $record): bool => $record->trashed()),
            ]);
    }
}
