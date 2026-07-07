<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Services\UserLandingPageService;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        /** @var User|null $user */
        $user = auth()->user();

        if (! $user) {
            return redirect()->to('/admin/login');
        }

        $service = app(UserLandingPageService::class);
        $url = $service->resolveUrlForUser($user, $user->default_landing_page);

        return redirect()->to($url);
    }
}
