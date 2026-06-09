<?php

namespace App\Filament\Resources\DailyStatistics\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class DailyStatisticInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('date')->date(),
                TextEntry::make('zabbix_problems_seen')->numeric(),
                TextEntry::make('tickets_created')->numeric(),
                TextEntry::make('tickets_reopened')->numeric(),
                TextEntry::make('tickets_auto_closed')->numeric(),
                TextEntry::make('tickets_manual_created')->numeric(),
                TextEntry::make('pattern_matched')->numeric(),
                TextEntry::make('pattern_unmatched')->numeric(),
                TextEntry::make('failed_actions')->numeric(),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('updated_at')->dateTime(),
            ]);
    }
}
