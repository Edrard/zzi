<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;

class MailNotificationService
{
    public function sendTestEmail(array $configData): void
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
}
