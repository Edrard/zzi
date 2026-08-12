<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Services\MailNotificationService;
use App\Services\SettingsService;
use Filament\Forms\Components\ToggleButtons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsMailTabTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('app:ensure-settings-defaults');
    }

    public function test_mail_schema_is_rendered_in_correct_order_and_visibility()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Setting::updateOrCreate(['key' => 'mail_unknown_future_setting'], ['value' => 'test', 'type' => 'string']);

        $component = Livewire::actingAs($admin)->test(Settings::class);

        $component->set('data.mail_transport', 'sendmail');
        $schema = $component->instance()->getForm('form')->getComponents();

        $mailFieldsOrder = [];
        $toggleButtonsComponent = null;

        $search = function ($components, $inMailTab = false) use (&$search, &$mailFieldsOrder, &$toggleButtonsComponent) {
            foreach ($components as $c) {
                $type = class_basename($c);
                $name = method_exists($c, 'getName') ? $c->getName() : null;
                $label = method_exists($c, 'getLabel') ? $c->getLabel() : null;

                if ($type === 'Tab' && $label === 'Mail') {
                    $inMailTab = true;
                }

                if ($inMailTab && $name === 'mail_transport') {
                    $toggleButtonsComponent = $c;
                }

                if ($inMailTab && $name && str_starts_with($name, 'mail_')) {
                    $mailFieldsOrder[] = $name;
                }

                if (method_exists($c, 'getChildComponents')) {
                    $search($c->getChildComponents(), $inMailTab);
                }
            }
        };

        $search($schema);

        $this->assertInstanceOf(ToggleButtons::class, $toggleButtonsComponent);
        $this->assertEquals(['sendmail', 'smtp'], array_keys($toggleButtonsComponent->getOptions()));

        $component->assertSee('Sendmail Configuration');
        $component->assertDontSee('SMTP Configuration');

        $expectedSendmailOrder = [
            'mail_notifications_enabled',
            'mail_transport',
            'mail_admin_recipients',
            'mail_from_address',
            'mail_from_name',
            'mail_sendmail_path',
            'mail_unknown_future_setting',
        ];

        $this->assertEquals($expectedSendmailOrder, $mailFieldsOrder);

        $component->set('data.mail_transport', 'smtp');
        $schema = $component->instance()->getForm('form')->getComponents();

        $mailFieldsOrder = [];
        $toggleButtonsComponent = null;

        $search($schema);

        $component->assertDontSee('Sendmail Configuration');
        $component->assertSee('SMTP Configuration');

        $expectedSmtpOrder = [
            'mail_notifications_enabled',
            'mail_transport',
            'mail_admin_recipients',
            'mail_from_address',
            'mail_from_name',
            'mail_smtp_host',
            'mail_smtp_port',
            'mail_smtp_encryption',
            'mail_smtp_username',
            'mail_smtp_password',
            'mail_smtp_password_clear',
            'mail_smtp_timeout_seconds',
            'mail_unknown_future_setting',
        ];

        $this->assertEquals($expectedSmtpOrder, $mailFieldsOrder);
    }

    public function test_sendmail_enforces_validation()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $component = Livewire::actingAs($admin)->test(Settings::class)
            ->set('data.mail_notifications_enabled', true)
            ->set('data.mail_transport', 'sendmail')
            ->set('data.mail_sendmail_path', '')
            ->call('save');

        $component->assertHasFormErrors(['mail_sendmail_path' => 'required']);
        $component->assertHasNoFormErrors(['mail_smtp_host' => 'required']);
    }

    public function test_smtp_enforces_validation()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $component = Livewire::actingAs($admin)->test(Settings::class)
            ->set('data.mail_notifications_enabled', true)
            ->set('data.mail_transport', 'smtp')
            ->set('data.mail_smtp_host', '')
            ->set('data.mail_sendmail_path', '')
            ->call('save');

        $component->assertHasFormErrors(['mail_smtp_host' => 'required']);
        $component->assertHasNoFormErrors(['mail_sendmail_path' => 'required']);
    }

    public function test_saving_sendmail_does_not_erase_smtp_configuration()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Setting::updateOrCreate(['key' => 'mail_smtp_host'], ['value' => 'smtp.test']);
        Setting::updateOrCreate(['key' => 'mail_sendmail_path'], ['value' => '/test/sendmail']);

        Livewire::actingAs($admin)->test(Settings::class)
            ->fillForm([
                'zabbix_api_url' => 'http://example.com',
                'znuny_username' => 'user',
                'znuny_api_url' => 'http://example.com',
                'znuny_web_url' => 'http://example.com',
                'mail_transport' => 'sendmail',
                'mail_sendmail_path' => '/new/sendmail',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals('/new/sendmail', Setting::where('key', 'mail_sendmail_path')->value('value'));
        $this->assertEquals('smtp.test', Setting::where('key', 'mail_smtp_host')->value('value'));
    }

    public function test_saving_smtp_does_not_erase_sendmail_configuration()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Setting::updateOrCreate(['key' => 'mail_smtp_host'], ['value' => 'smtp.test']);
        Setting::updateOrCreate(['key' => 'mail_sendmail_path'], ['value' => '/test/sendmail']);

        Livewire::actingAs($admin)->test(Settings::class)
            ->fillForm([
                'zabbix_api_url' => 'http://example.com',
                'znuny_username' => 'user',
                'znuny_api_url' => 'http://example.com',
                'znuny_web_url' => 'http://example.com',
                'mail_transport' => 'smtp',
                'mail_smtp_host' => 'new.smtp.test',
                'mail_smtp_port' => 25,
                'mail_smtp_encryption' => 'tls',
                'mail_smtp_timeout_seconds' => 15,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals('new.smtp.test', Setting::where('key', 'mail_smtp_host')->value('value'));
        $this->assertEquals('/test/sendmail', Setting::where('key', 'mail_sendmail_path')->value('value'));
    }

    public function test_test_email_action_smtp_unauthenticated()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $mock = $this->mock(MailNotificationService::class);
        $mock->shouldReceive('sendTestEmail')->once();

        Livewire::actingAs($admin)->test(Settings::class)
            ->fillForm([
                'mail_transport' => 'smtp',
                'mail_from_address' => 'from@test.local',
                'mail_admin_recipients' => 'admin@test.local',
                'mail_smtp_host' => 'smtp.test.local',
                'mail_smtp_username' => '',
                'mail_smtp_password' => '',
            ])
            ->call('testMailConnectionAction')
            ->assertNotified('Test Email Sent');
    }

    public function test_test_email_action_smtp_decrypts_existing_password()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $secret = 'secret123';
        $encrypted = SettingsService::encryptForStorage('mail_smtp_password', $secret);
        Setting::updateOrCreate(['key' => 'mail_smtp_password'], ['value' => $encrypted, 'type' => 'string']);
        SettingsService::clearAllCaches();

        $mock = $this->mock(MailNotificationService::class);
        $mock->shouldReceive('sendTestEmail')
            ->withArgs(function ($data) use ($secret) {
                return $data['mail_smtp_password'] === $secret;
            })
            ->once();

        Livewire::actingAs($admin)->test(Settings::class)
            ->fillForm([
                'mail_transport' => 'smtp',
                'mail_from_address' => 'from@test.local',
                'mail_admin_recipients' => 'admin@test.local',
                'mail_smtp_host' => 'smtp.test.local',
                'mail_smtp_password' => '', // blank in form
            ])
            ->call('testMailConnectionAction')
            ->assertNotified('Test Email Sent');
    }

    public function test_test_email_action_smtp_new_password_overrides()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $mock = $this->mock(MailNotificationService::class);
        $mock->shouldReceive('sendTestEmail')
            ->withArgs(function ($data) {
                return $data['mail_smtp_password'] === 'new-secret';
            })
            ->once();

        Livewire::actingAs($admin)->test(Settings::class)
            ->fillForm([
                'mail_transport' => 'smtp',
                'mail_from_address' => 'from@test.local',
                'mail_admin_recipients' => 'admin@test.local',
                'mail_smtp_host' => 'smtp.test.local',
                'mail_smtp_password' => 'new-secret',
            ])
            ->call('testMailConnectionAction')
            ->assertNotified('Test Email Sent');
    }

    public function test_test_email_action_smtp_clear_password_toggle()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $secret = 'secret123';
        $encrypted = SettingsService::encryptForStorage('mail_smtp_password', $secret);
        Setting::updateOrCreate(['key' => 'mail_smtp_password'], ['value' => $encrypted, 'type' => 'string']);
        SettingsService::clearAllCaches();

        $mock = $this->mock(MailNotificationService::class);
        $mock->shouldReceive('sendTestEmail')
            ->withArgs(function ($data) {
                return $data['mail_smtp_password'] === '';
            })
            ->once();

        Livewire::actingAs($admin)->test(Settings::class)
            ->fillForm([
                'mail_transport' => 'smtp',
                'mail_from_address' => 'from@test.local',
                'mail_admin_recipients' => 'admin@test.local',
                'mail_smtp_host' => 'smtp.test.local',
                'mail_smtp_password' => '',
                'mail_smtp_password_clear' => true,
            ])
            ->call('testMailConnectionAction')
            ->assertNotified('Test Email Sent');
    }

    public function test_test_email_action_sendmail_ignores_smtp_fields()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $mock = $this->mock(MailNotificationService::class);
        $mock->shouldReceive('sendTestEmail')->once();

        Livewire::actingAs($admin)->test(Settings::class)
            ->fillForm([
                'mail_transport' => 'sendmail',
                'mail_from_address' => 'from@test.local',
                'mail_admin_recipients' => 'admin@test.local',
                'mail_sendmail_path' => '/bin/sendmail',
                'mail_smtp_host' => '', // empty
            ])
            ->call('testMailConnectionAction')
            ->assertNotified('Test Email Sent');
    }

    public function test_test_email_action_smtp_ignores_sendmail_path()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $mock = $this->mock(MailNotificationService::class);
        $mock->shouldReceive('sendTestEmail')->once();

        Livewire::actingAs($admin)->test(Settings::class)
            ->fillForm([
                'mail_transport' => 'smtp',
                'mail_from_address' => 'from@test.local',
                'mail_admin_recipients' => 'admin@test.local',
                'mail_smtp_host' => 'smtp.test',
                'mail_sendmail_path' => '', // empty
            ])
            ->call('testMailConnectionAction')
            ->assertNotified('Test Email Sent');
    }

    public function test_test_email_action_disabled_notifications_allowed()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $mock = $this->mock(MailNotificationService::class);
        $mock->shouldReceive('sendTestEmail')->once();

        Livewire::actingAs($admin)->test(Settings::class)
            ->fillForm([
                'mail_notifications_enabled' => false,
                'mail_transport' => 'sendmail',
                'mail_from_address' => 'from@test.local',
                'mail_admin_recipients' => 'admin@test.local',
                'mail_sendmail_path' => '/bin/sendmail',
            ])
            ->call('testMailConnectionAction')
            ->assertNotified('Test Email Sent');
    }

    public function test_test_email_action_unsupported_transport_rejected()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $mock = $this->mock(MailNotificationService::class);
        $mock->shouldNotReceive('sendTestEmail');

        Livewire::actingAs($admin)->test(Settings::class)
            ->fillForm([
                'mail_transport' => 'invalid-transport',
                'mail_from_address' => 'from@test.local',
                'mail_admin_recipients' => 'admin@test.local',
            ])
            ->call('testMailConnectionAction')
            ->assertNotified('Test Email Failed');
    }
}
