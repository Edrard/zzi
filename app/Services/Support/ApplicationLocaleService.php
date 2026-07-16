<?php

namespace App\Services\Support;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Facades\App;

class ApplicationLocaleService
{
    private const SUPPORTED_LOCALES = ['uk', 'en'];

    private const DEFAULT_LOCALE = 'en';

    public function supportedLocales(): array
    {
        return self::SUPPORTED_LOCALES;
    }

    public function defaultLocale(): string
    {
        return self::DEFAULT_LOCALE;
    }

    public function isSupported(?string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    public function normalize(?string $locale): string
    {
        if ($this->isSupported($locale)) {
            return $locale;
        }

        return self::DEFAULT_LOCALE;
    }

    public function resolveSystem(): string
    {
        return $this->normalize(SettingsService::string('ui_locale'));
    }

    public function resolve(?User $user = null): string
    {
        $user = $user ?? auth()->user();

        if ($user && $this->isSupported($user->ui_locale)) {
            return $user->ui_locale;
        }

        return $this->resolveSystem();
    }

    public function apply(?string $locale = null): string
    {
        $resolved = $locale !== null ? $this->normalize($locale) : $this->resolve();
        App::setLocale($resolved);

        return $resolved;
    }

    public function options(): array
    {
        $options = [];

        foreach ($this->supportedLocales() as $locale) {
            $options[$locale] = __("common.locales.{$locale}");
        }

        return $options;
    }
}
