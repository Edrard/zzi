<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Users extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected string $view = 'filament.pages.users';
}
