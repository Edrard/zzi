<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class DailyStatistics extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected string $view = 'filament.pages.daily-statistics';
}
