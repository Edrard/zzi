<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Patterns extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Automation';

    protected string $view = 'filament.pages.patterns';
}
