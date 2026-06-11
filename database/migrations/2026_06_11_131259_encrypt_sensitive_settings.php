<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PREFIX = 'enc:v1:';

    private const SECRET_KEYS = [
        'zabbix_api_token',
        'znuny_password',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::SECRET_KEYS as $key) {
            $setting = DB::table('settings')->where('key', $key)->first();

            if (! $setting || $setting->value === null || $setting->value === '') {
                continue;
            }

            if (str_starts_with($setting->value, self::PREFIX)) {
                continue;
            }

            $encrypted = self::PREFIX.Crypt::encryptString((string) $setting->value);

            DB::table('settings')->where('key', $key)->update(['value' => $encrypted]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::SECRET_KEYS as $key) {
            $setting = DB::table('settings')->where('key', $key)->first();

            if (! $setting || $setting->value === null || $setting->value === '') {
                continue;
            }

            if (! str_starts_with($setting->value, self::PREFIX)) {
                continue;
            }

            try {
                $payload = substr($setting->value, strlen(self::PREFIX));
                $plaintext = Crypt::decryptString($payload);
            } catch (Exception $e) {
                throw new RuntimeException("Failed to decrypt sensitive setting for key: {$key} during migration rollback.");
            }

            DB::table('settings')->where('key', $key)->update(['value' => $plaintext]);
        }
    }
};
