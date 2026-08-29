<?php

namespace App\Services\Znuny;

use App\Services\SettingsService;

class ZnunyCustomerUserUrlService
{
    public function getEditUrl(?string $login): ?string
    {
        if (empty($login)) {
            return null;
        }

        $template = trim((string) SettingsService::string('znuny_customer_user_url_template', ''));

        if (empty($template) || !str_contains($template, '{customer_user_login}')) {
            return null;
        }

        if (!str_starts_with($template, 'http://') && !str_starts_with($template, 'https://')) {
            return null;
        }

        return str_replace('{customer_user_login}', urlencode($login), $template);
    }
}
