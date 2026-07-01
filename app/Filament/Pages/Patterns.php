<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Patterns extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Automation';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.patterns';

    public static function canAccess(): bool
    {
        return false;
    }
}
