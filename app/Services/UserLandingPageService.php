<?php

namespace App\Services;

use App\Models\User;
use Filament\Facades\Filament;

class UserLandingPageService
{
    /**
     * Get the configured valid landing pages from config.
     */
    public function configuredAvailableKeys(): array
    {
        $env = config('app.available_landing_pages', 'current-zabbix-problems,znuny-ticket-workspace,zabbix-tickets,create-ticket');

        if (! is_string($env) || empty(trim($env))) {
            return ['current-zabbix-problems', 'znuny-ticket-workspace', 'zabbix-tickets', 'create-ticket'];
        }

        $keys = array_values(array_filter(array_map('trim', explode(',', $env))));

        return array_filter($keys, function ($key) {
            return ! str_starts_with($key, 'http') && ! str_starts_with($key, '/') && ! str_contains($key, '://');
        });
    }

    /**
     * Build candidate maps from Filament.
     */
    protected function getDiscoveredFilamentCandidates(): array
    {
        $panel = Filament::getPanel('admin');
        $candidates = [];

        foreach ($panel->getPages() as $pageClass) {
            $slug = $pageClass::getSlug();
            $candidates[$slug] = [
                'label' => $pageClass::getNavigationLabel() ?? $pageClass::getTitle() ?? $slug,
                'url' => fn () => $pageClass::getUrl(),
                'access' => fn () => $pageClass::canAccess(),
            ];
        }

        foreach ($panel->getResources() as $resourceClass) {
            $slug = $resourceClass::getSlug();
            $candidates[$slug] = [
                'label' => $resourceClass::getNavigationLabel() ?? $slug,
                'url' => fn () => $resourceClass::getUrl('index'),
                'access' => fn () => $resourceClass::canViewAny(),
            ];
        }

        return $candidates;
    }

    /**
     * Discover available options from Filament based on the given user and env config.
     */
    public function availableOptionsForUser(User $user): array
    {
        $configuredKeys = $this->configuredAvailableKeys();
        $candidates = $this->getDiscoveredFilamentCandidates();

        $options = [];

        // Special case for create-ticket viewer access rule from previous logic
        if ($user->role === 'viewer') {
            if (isset($candidates['create-ticket'])) {
                $candidates['create-ticket']['access'] = fn () => false;
            }
        }

        foreach ($configuredKeys as $key) {
            if (isset($candidates[$key]) && $candidates[$key]['access']()) {
                $options[$key] = $candidates[$key]['label'];
            }
        }

        if (empty($options)) {
            $options['current-zabbix-problems'] = 'Current Problems';
        }

        return $options;
    }

    public function isAllowedForUser(User $user, string $key): bool
    {
        $options = $this->availableOptionsForUser($user);

        return array_key_exists($key, $options);
    }

    public function normalizeKeyForUser(User $user, ?string $key): string
    {
        $fallback = config('app.default_landing_page', 'current-zabbix-problems');

        if (! $key || ! $this->isAllowedForUser($user, $key)) {
            $key = $fallback;
        }

        if (! $this->isAllowedForUser($user, $key)) {
            $key = 'current-zabbix-problems';
        }

        return $key;
    }

    public function resolveUrlForUser(User $user, ?string $key = null): string
    {
        $key = $this->normalizeKeyForUser($user, $key);
        $candidates = $this->getDiscoveredFilamentCandidates();

        if (isset($candidates[$key])) {
            return $candidates[$key]['url']();
        }

        return route('filament.admin.pages.current-zabbix-problems');
    }
}
