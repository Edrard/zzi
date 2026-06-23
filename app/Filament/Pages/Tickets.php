<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Tickets extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Znuny';

    protected static ?string $navigationLabel = 'Ticket Workspace';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.tickets';
}
