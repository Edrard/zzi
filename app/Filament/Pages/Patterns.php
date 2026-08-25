<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class Patterns extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.automation');
    }

    protected static ?int $navigationSort = 1;

    public function getTitle(): string|Htmlable
    {
        return __('navigation.resources.patterns.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.patterns.plural');
    }

    protected string $view = 'filament.pages.patterns';

    public static function canAccess(): bool
    {
        return false;
    }
}
