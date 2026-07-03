<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_06_11_131259_encrypt_sensitive_settings.php');
    }

    public function test_non_sensitive_setting_remains_plaintext()
    {
        $value = 'http://example.com';
        $prepared = SettingsService::encryptForStorage('zabbix_api_url', $value);
        $this->assertEquals($value, $prepared);

        $decrypted = SettingsService::decryptStoredSecret('zabbix_api_url', $value);
        $this->assertEquals($value, $decrypted);
    }

    public function test_sensitive_plaintext_is_prepared_for_storage()
    {
        $secret = 'super-secret';
        $prepared = SettingsService::encryptForStorage('znuny_password', $secret);

        $this->assertStringStartsWith('enc:v1:', $prepared);
        $this->assertStringNotContainsString($secret, $prepared);
    }

    public function test_sensitive_stored_ciphertext_is_transparently_decrypted()
    {
        $secret = 'my-token';
        $encrypted = SettingsService::encryptForStorage('zabbix_api_token', $secret);

        Setting::updateOrCreate(['key' => 'zabbix_api_token'], ['value' => $encrypted, 'type' => 'string']);

        $this->assertEquals($secret, SettingsService::string('zabbix_api_token'));
    }

    public function test_legacy_plaintext_sensitive_value_is_still_readable()
    {
        $secret = 'legacy-secret';
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => $secret, 'type' => 'string']);

        $this->assertEquals($secret, SettingsService::string('znuny_password'));
    }

    public function test_plaintext_beginning_with_prefix_is_encrypted_normally()
    {
        $plaintext = 'enc:v1:fake-payload';
        $prepared = SettingsService::encryptForStorage('znuny_password', $plaintext);

        $this->assertStringStartsWith('enc:v1:', $prepared);
        $this->assertNotEquals($plaintext, $prepared); // It should not be stored directly

        // Decrypts back to the exact original plaintext
        $decrypted = SettingsService::decryptStoredSecret('znuny_password', $prepared);
        $this->assertEquals($plaintext, $decrypted);
    }

    public function test_corrupted_encrypted_payload_throws_safe_exception()
    {
        $corrupted = 'enc:v1:invalid-payload-that-fails-decryption';
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => $corrupted, 'type' => 'string']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to decrypt sensitive setting for key: znuny_password');

        SettingsService::string('znuny_password');
    }

    public function test_settings_form_save()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $secret = 'old-secret';
        $encrypted = SettingsService::encryptForStorage('znuny_password', $secret);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => $encrypted, 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'zabbix_api_url'], ['value' => 'http://old.com', 'type' => 'string']);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->assertSet('data.znuny_password', '')
            ->fillForm([
                'znuny_username' => 'testuser',
                'znuny_api_url' => 'http://api',
                'znuny_web_url' => 'http://web',
                'znuny_ticket_url_template' => 'url',
                'znuny_api_verify_ssl' => true,
                'znuny_api_timeout' => 10,
                'cleanup_enabled' => true,
                'cleanup_batch_size' => 1000,
                'retention_action_logs_days' => 30,
                'retention_closed_tickets_days' => 30,
                'retention_failed_jobs_days' => 30,
                'retention_resolved_days' => 30,

                'zabbix_api_url' => 'http://new.com', // changed non-secret
                'zabbix_api_token' => '',
                'zabbix_api_timeout' => 10,
                'zabbix_api_verify_ssl' => true,
                'zabbix_poll_interval_minutes' => 5,
                'zabbix_problem_cache_ttl_minutes' => 5,
                'zabbix_problem_limit' => 100,
                'zabbix_exclude_suppressed_problems' => true,
                'default_close_delay_hours' => 4,
                'default_reopen_window_hours' => 24,
                'znuny_password' => 'old-secret', // unchanged secret
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        // Ensure unchanged secret did not rewrite ciphertext or log audit
        $this->assertEquals($encrypted, Setting::where('key', 'znuny_password')->value('value'));
        $this->assertEquals('http://new.com', Setting::where('key', 'zabbix_api_url')->value('value'));

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm([
                'znuny_username' => 'testuser',
                'znuny_api_url' => 'http://api',
                'znuny_web_url' => 'http://web',
                'znuny_ticket_url_template' => 'url',
                'znuny_api_verify_ssl' => true,
                'znuny_api_timeout' => 10,
                'cleanup_enabled' => true,
                'cleanup_batch_size' => 1000,
                'retention_action_logs_days' => 30,
                'retention_closed_tickets_days' => 30,
                'retention_failed_jobs_days' => 30,
                'retention_resolved_days' => 30,

                'zabbix_api_url' => 'http://new.com',
                'zabbix_api_token' => '',
                'zabbix_api_timeout' => 10,
                'zabbix_api_verify_ssl' => true,
                'zabbix_poll_interval_minutes' => 5,
                'zabbix_problem_cache_ttl_minutes' => 5,
                'zabbix_problem_limit' => 100,
                'zabbix_exclude_suppressed_problems' => true,
                'default_close_delay_hours' => 4,
                'default_reopen_window_hours' => 24,
                'znuny_password' => 'new-secret', // changed secret
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $newValue = Setting::where('key', 'znuny_password')->value('value');
        $this->assertStringStartsWith('enc:v1:', $newValue);
        $this->assertNotEquals($encrypted, $newValue);
        $this->assertEquals('new-secret', SettingsService::string('znuny_password'));

        // Check audit log contains [redacted]
        $log = AuditLog::where('action', 'settings.updated')->latest('id')->first();
        $this->assertNotNull($log);
        $changes = collect($log->context['changes']);
        $secretChange = $changes->firstWhere('key', 'znuny_password');

        $this->assertEquals('[redacted]', $secretChange['old_value']);
        $this->assertEquals('[redacted]', $secretChange['new_value']);
    }

    public function test_migration_up()
    {
        $alreadyEncrypted = 'enc:v1:should-not-change';

        DB::table('settings')->updateOrInsert(['key' => 'znuny_password'], ['value' => 'plain1', 'type' => 'string', 'description' => '']);
        DB::table('settings')->updateOrInsert(['key' => 'zabbix_api_token'], ['value' => $alreadyEncrypted, 'type' => 'string', 'description' => '']);
        DB::table('settings')->updateOrInsert(['key' => 'zabbix_api_url'], ['value' => 'http://api', 'type' => 'string', 'description' => '']);

        $this->migration->up();

        $this->assertStringStartsWith('enc:v1:', DB::table('settings')->where('key', 'znuny_password')->value('value'));
        $this->assertEquals('plain1', SettingsService::string('znuny_password'));

        // Leaves already encrypted value unchanged
        $this->assertEquals($alreadyEncrypted, DB::table('settings')->where('key', 'zabbix_api_token')->value('value'));
        $this->assertEquals('http://api', DB::table('settings')->where('key', 'zabbix_api_url')->value('value'));
    }

    public function test_migration_down()
    {
        $encrypted = SettingsService::encryptForStorage('znuny_password', 'plain1');

        DB::table('settings')->updateOrInsert(['key' => 'znuny_password'], ['value' => $encrypted, 'type' => 'string', 'description' => '']);
        DB::table('settings')->updateOrInsert(['key' => 'zabbix_api_url'], ['value' => 'http://api', 'type' => 'string', 'description' => '']);

        $this->migration->down();

        $this->assertEquals('plain1', DB::table('settings')->where('key', 'znuny_password')->value('value'));
        $this->assertEquals('http://api', DB::table('settings')->where('key', 'zabbix_api_url')->value('value'));
    }
}
