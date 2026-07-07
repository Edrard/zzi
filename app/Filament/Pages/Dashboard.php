<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\UserLandingPageService;
use Filament\Pages\Page;

/**
 * Dashboard Redirect Shim
 *
 * This class acts as a redirect shim for the /admin root URL.
 * It does not contain any widgets or work area, but rather resolves
 * the user's preferred landing page and redirects them there.
 */
class Dashboard extends Page
{
    protected static ?string $slug = '/';

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-home';
    }

    protected static bool $shouldRegisterNavigation = false;

    public function mount()
    {
        /** @var User $user */
        $user = auth()->user();

        $service = app(UserLandingPageService::class);
        $url = $service->resolveUrlForUser($user, $user->default_landing_page);

        // Avoid infinite loop if resolved URL points back to root /admin
        // or /admin/
        if (trim(parse_url($url, PHP_URL_PATH), '/') === 'admin') {
            return redirect()->route('filament.admin.pages.current-zabbix-problems');
        }

        return redirect()->to($url);
    }
}
