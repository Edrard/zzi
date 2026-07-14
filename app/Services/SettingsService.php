<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class SettingsService
{
    private const SECRET_KEYS = [
        'zabbix_api_token',
        'znuny_password',
        'mail_smtp_password',
    ];

    public static function isSecretKey(string $key): bool
    {
        return in_array($key, self::SECRET_KEYS, true);
    }

    public static function isEncryptedValue(string $value): bool
    {
        return str_starts_with($value, 'enc:v1:');
    }

    public static function encryptForStorage(string $key, string $plaintext): string
    {
        if (! self::isSecretKey($key)) {
            return $plaintext;
        }

        return 'enc:v1:'.Crypt::encryptString($plaintext);
    }

    public static function decryptStoredSecret(string $key, string $value): string
    {
        if (! self::isSecretKey($key)) {
            return $value;
        }

        if (self::isEncryptedValue($value)) {
            try {
                $payload = substr($value, 7);

                return Crypt::decryptString($payload);
            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to decrypt sensitive setting for key: {$key}");
            }
        }

        return $value;
    }

    private static array $requestCache = [];

    private static bool $allLoaded = false;

    public static function clearRequestCache(): void
    {
        self::$requestCache = [];
        self::$allLoaded = false;
    }

    public static function clearAllCaches(): void
    {
        self::clearRequestCache();
        Cache::forget('app_settings_all');
    }

    private static function loadAllSettings(): void
    {
        if (self::$allLoaded) {
            return;
        }

        $allSettings = Cache::rememberForever('app_settings_all', function () {
            return Setting::all(['key', 'value', 'type'])->keyBy('key')->toArray();
        });

        foreach ($allSettings as $key => $attributes) {
            self::$requestCache[$key] = (object) $attributes;
        }

        self::$allLoaded = true;
    }

    private static function getSettingRecord(string $key)
    {
        self::loadAllSettings();

        if (array_key_exists($key, self::$requestCache)) {
            return self::$requestCache[$key];
        }

        // If it was not in the all-settings cache, it doesn't exist.
        // We cache the null to avoid any weirdness, though array_key_exists handles it if we did.
        self::$requestCache[$key] = null;

        return null;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = self::getSettingRecord($key);

        if (! $setting) {
            return $default;
        }

        return match ($setting->type) {
            'boolean' => static::parseBool($setting->value, $default),
            'integer' => static::parseInt($setting->value, $default),
            'json' => static::parseJson($setting->value, $default),
            default => static::decryptStoredSecret($setting->key, $setting->value ?? $default),
        };
    }

    public static function string(string $key, ?string $default = null): ?string
    {
        $setting = self::getSettingRecord($key);

        if (! $setting || $setting->value === null) {
            return $default;
        }

        return static::decryptStoredSecret($key, (string) $setting->value);
    }

    public static function int(string $key, ?int $default = null): ?int
    {
        $setting = self::getSettingRecord($key);

        if (! $setting) {
            return $default;
        }

        return static::parseInt($setting->value, $default);
    }

    public static function bool(string $key, ?bool $default = null): ?bool
    {
        $setting = self::getSettingRecord($key);

        if (! $setting) {
            return $default;
        }

        return static::parseBool($setting->value, $default);
    }

    public static function json(string $key, mixed $default = null): mixed
    {
        $setting = self::getSettingRecord($key);

        if (! $setting) {
            return $default;
        }

        return static::parseJson($setting->value, $default);
    }

    protected static function parseBool(mixed $value, mixed $default): mixed
    {
        if ($value === 'true' || $value === '1' || $value === 1 || $value === true) {
            return true;
        }

        if ($value === 'false' || $value === '0' || $value === 0 || $value === false) {
            return false;
        }

        return $default;
    }

    protected static function parseInt(mixed $value, mixed $default): mixed
    {
        $filtered = filter_var($value, FILTER_VALIDATE_INT);

        if ($filtered !== false) {
            return $filtered;
        }

        return $default;
    }

    protected static function parseJson(mixed $value, mixed $default): mixed
    {
        if (! is_string($value)) {
            return $default;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $default;
    }

    public static function cleanupEnabled(): bool
    {
        return static::bool('cleanup_enabled', true) ?? true;
    }

    public static function cleanupBatchSize(): int
    {
        return static::int('cleanup_batch_size', 1000) ?? 1000;
    }

    public static function retentionResolvedDays(): int
    {
        return static::int('retention_resolved_days', 90) ?? 90;
    }

    public static function retentionClosedTicketsDays(): int
    {
        return static::int('retention_closed_tickets_days', 180) ?? 180;
    }

    public static function retentionActionLogsDays(): int
    {
        return static::int('retention_action_logs_days', 365) ?? 365;
    }

    public static function retentionFailedJobsDays(): int
    {
        return static::int('retention_failed_jobs_days', 30) ?? 30;
    }

    public static function defaultCloseDelayHours(): int
    {
        return static::int('default_close_delay_hours', 4) ?? 4;
    }

    public static function defaultReopenWindowHours(): int
    {
        return static::int('default_reopen_window_hours', 24) ?? 24;
    }
}
