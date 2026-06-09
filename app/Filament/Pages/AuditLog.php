<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class AuditLog extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected string $view = 'filament.pages.audit-log';
}
