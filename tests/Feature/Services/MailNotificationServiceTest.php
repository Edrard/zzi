<?php

namespace Tests\Feature\Services;

use App\Models\Setting;
use App\Services\MailNotificationService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('app:ensure-settings-defaults');
    }

    public function test_sendmail_config_applies_correctly()
    {
        Setting::updateOrCreate(['key' => 'mail_notifications_enabled'], ['value' => 'true']);
        Setting::updateOrCreate(['key' => 'mail_transport'], ['value' => 'sendmail']);
        Setting::updateOrCreate(['key' => 'mail_sendmail_path'], ['value' => '/test/sendmail']);
        Setting::updateOrCreate(['key' => 'mail_admin_recipients'], ['value' => 'admin@test.local']);
        Setting::updateOrCreate(['key' => 'mail_from_address'], ['value' => 'from@test.local']);

        SettingsService::clearAllCaches();

        Mail::shouldReceive('raw')
            ->once()
            ->withArgs(function ($body, $callback) {
                return str_contains($body, 'Body') && is_callable($callback);
            });

        $service = app(MailNotificationService::class);
        $service->sendWarning('Test Warning', 'Body');

        $this->assertEquals('sendmail', config('mail.default'));
        $this->assertEquals('/test/sendmail', config('mail.mailers.sendmail.path'));
        $this->assertEquals('from@test.local', config('mail.from.address'));
    }

    public function test_smtp_config_applies_correctly()
    {
        Setting::updateOrCreate(['key' => 'mail_notifications_enabled'], ['value' => 'true']);
        Setting::updateOrCreate(['key' => 'mail_transport'], ['value' => 'smtp']);
        Setting::updateOrCreate(['key' => 'mail_smtp_host'], ['value' => 'smtp.test.local']);
        Setting::updateOrCreate(['key' => 'mail_smtp_port'], ['value' => '2525']);
        Setting::updateOrCreate(['key' => 'mail_smtp_encryption'], ['value' => 'tls']);
        Setting::updateOrCreate(['key' => 'mail_smtp_username'], ['value' => 'testuser']);

        $secret = 'testpass';
        $encrypted = SettingsService::encryptForStorage('mail_smtp_password', $secret);
        Setting::updateOrCreate(['key' => 'mail_smtp_password'], ['value' => $encrypted]);

        Setting::updateOrCreate(['key' => 'mail_admin_recipients'], ['value' => 'admin@test.local']);
        Setting::updateOrCreate(['key' => 'mail_from_address'], ['value' => 'from@test.local']);

        SettingsService::clearAllCaches();

        Mail::shouldReceive('raw')
            ->once()
            ->withArgs(function ($body, $callback) {
                return str_contains($body, 'Body') && is_callable($callback);
            });

        $service = app(MailNotificationService::class);
        $service->sendAlarm('Test Alarm', 'Body');

        $this->assertEquals('smtp', config('mail.default'));
        $this->assertEquals('smtp.test.local', config('mail.mailers.smtp.host'));
        $this->assertEquals(2525, config('mail.mailers.smtp.port'));
        $this->assertEquals('tls', config('mail.mailers.smtp.encryption'));
        $this->assertEquals('testuser', config('mail.mailers.smtp.username'));

        // Verify runtime config receives the decrypted plaintext
        $this->assertEquals('testpass', config('mail.mailers.smtp.password'));

        // Verify the database still contains ciphertext
        $this->assertEquals($encrypted, Setting::where('key', 'mail_smtp_password')->value('value'));
    }

    public function test_disabled_notifications_prevent_email()
    {
        Setting::updateOrCreate(['key' => 'mail_notifications_enabled'], ['value' => 'false']);
        Setting::updateOrCreate(['key' => 'mail_admin_recipients'], ['value' => 'admin@test.local']);

        SettingsService::clearAllCaches();

        Mail::shouldReceive('raw')->never();

        $service = app(MailNotificationService::class);
        $service->sendWarning('Test Warning', 'Body');
    }
}
