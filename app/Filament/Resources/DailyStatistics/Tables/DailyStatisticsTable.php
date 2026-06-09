<?php

namespace App\Filament\Resources\DailyStatistics\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DailyStatisticsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')->date()->sortable(),
                TextColumn::make('zabbix_problems_seen')->sortable(),
                TextColumn::make('tickets_created')->sortable(),
                TextColumn::make('tickets_reopened')->sortable(),
                TextColumn::make('tickets_auto_closed')->sortable(),
                TextColumn::make('tickets_manual_created')->sortable(),
                TextColumn::make('pattern_matched')->sortable(),
                TextColumn::make('pattern_unmatched')->sortable(),
                TextColumn::make('failed_actions')->sortable(),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                //
            ]);
    }
}
