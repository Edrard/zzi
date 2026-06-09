<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Tickets extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected string $view = 'filament.pages.tickets';
}
