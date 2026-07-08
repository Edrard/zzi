<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailNotificationService
{
    public function sendWarning(string $subject, string $body): void
    {
        $this->sendEmail('Warning', $subject, $body);
    }

    public function sendAlarm(string $subject, string $body): void
    {
        $this->sendEmail('ALARM', $subject, $body);
    }

    private function sendEmail(string $prefix, string $subject, string $body): void
    {
        if (! SettingsService::bool('mail_notifications_enabled')) {
            return;
        }

        $configData = [
            'mail_transport' => SettingsService::string('mail_transport'),
            'mail_smtp_host' => SettingsService::string('mail_smtp_host'),
            'mail_smtp_port' => SettingsService::int('mail_smtp_port', 25),
            'mail_smtp_encryption' => SettingsService::string('mail_smtp_encryption'),
            'mail_smtp_username' => SettingsService::string('mail_smtp_username'),
            'mail_smtp_password' => SettingsService::string('mail_smtp_password'),
            'mail_smtp_timeout_seconds' => SettingsService::int('mail_smtp_timeout_seconds', 15),
            'mail_sendmail_path' => SettingsService::string('mail_sendmail_path'),
            'mail_from_address' => SettingsService::string('mail_from_address'),
            'mail_from_name' => SettingsService::string('mail_from_name'),
            'mail_admin_recipients' => SettingsService::string('mail_admin_recipients'),
        ];

        try {
            $this->applyConfig($configData);

            $recipientsStr = $configData['mail_admin_recipients'] ?? '';
            $recipients = array_filter(array_map('trim', explode(',', $recipientsStr)));

            if (empty($recipients)) {
                return; // Do not crash, just don't send
            }

            Mail::raw($body, function ($message) use ($recipients, $prefix, $subject) {
                $message->to($recipients)
                    ->subject("[{$prefix}] {$subject}");
            });
        } catch (\Throwable $e) {
            Log::error('Failed to send email notification: '.$e->getMessage());
        }
    }

    public function sendTestEmail(array $configData): void
    {
        $this->applyConfig($configData);

        $recipientsStr = $configData['mail_admin_recipients'] ?? '';
        $recipients = array_filter(array_map('trim', explode(',', $recipientsStr)));

        if (empty($recipients)) {
            throw new \Exception('No admin recipients configured.');
        }

        // Send a simple raw test email
        Mail::raw('This is a test email from the Zabbix Znuny Integration scheduler settings.', function ($message) use ($recipients) {
            $message->to($recipients)
                ->subject('Test Email - Zabbix Znuny Integration');
        });
    }

    private function applyConfig(array $configData): void
    {
        $transport = $configData['mail_transport'] ?? 'smtp';

        // Dynamically set config
        config(['mail.default' => $transport]);

        if ($transport === 'smtp') {
            config([
                'mail.mailers.smtp.host' => $configData['mail_smtp_host'] ?? '',
                'mail.mailers.smtp.port' => $configData['mail_smtp_port'] ?? 25,
                'mail.mailers.smtp.encryption' => $configData['mail_smtp_encryption'] ?? 'tls',
                'mail.mailers.smtp.username' => $configData['mail_smtp_username'] ?? '',
                'mail.mailers.smtp.password' => $configData['mail_smtp_password'] ?? '',
                'mail.mailers.smtp.timeout' => $configData['mail_smtp_timeout_seconds'] ?? 15,
                'mail.mailers.smtp.local_domain' => env('MAIL_EHLO_DOMAIN'),
            ]);
        } elseif ($transport === 'sendmail') {
            config([
                'mail.mailers.sendmail.path' => $configData['mail_sendmail_path'] ?? '/usr/sbin/sendmail -bs -i',
            ]);
        }

        config([
            'mail.from.address' => $configData['mail_from_address'] ?? 'noreply@example.com',
            'mail.from.name' => $configData['mail_from_name'] ?? 'System Alerts',
        ]);
    }
}
