<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MailSettingsConditionalValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_smtp_fields_not_required_when_notifications_disabled(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm([
                'mail_notifications_enabled' => false,
                'mail_transport' => 'smtp',
                'mail_smtp_host' => '',
                'mail_smtp_port' => '',
                'mail_smtp_encryption' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors([
                'mail_smtp_host',
                'mail_smtp_port',
                'mail_smtp_encryption',
            ]);
    }

    public function test_smtp_fields_required_when_notifications_enabled(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm([
                'mail_notifications_enabled' => true,
                'mail_transport' => 'smtp',
                'mail_smtp_host' => '',
            ])
            ->call('save')
            ->assertHasFormErrors([
                'mail_smtp_host' => 'required',
            ]);
    }

    public function test_sendmail_path_not_required_when_notifications_disabled(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm([
                'mail_notifications_enabled' => false,
                'mail_transport' => 'sendmail',
                'mail_sendmail_path' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors([
                'mail_sendmail_path',
            ]);
    }

    public function test_sendmail_path_required_when_notifications_enabled(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm([
                'mail_notifications_enabled' => true,
                'mail_transport' => 'sendmail',
                'mail_sendmail_path' => '',
            ])
            ->call('save')
            ->assertHasFormErrors([
                'mail_sendmail_path' => 'required',
            ]);
    }
}
