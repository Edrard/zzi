<?php

namespace App\Filament\Resources\DailyStatistics\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DailyStatisticInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('date')->disabled(),
                TextInput::make('zabbix_problems_seen')->disabled(),
                TextInput::make('tickets_created')->disabled(),
                TextInput::make('tickets_reopened')->disabled(),
                TextInput::make('tickets_auto_closed')->disabled(),
                TextInput::make('tickets_manual_created')->disabled(),
                TextInput::make('pattern_matched')->disabled(),
                TextInput::make('pattern_unmatched')->disabled(),
                TextInput::make('failed_actions')->disabled(),
                TextInput::make('created_at')->disabled(),
                TextInput::make('updated_at')->disabled(),
            ]);
    }
}
