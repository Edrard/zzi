<?php

namespace App\Services\Znuny;

use App\Services\SettingsService;

class ZnunyTicketDefaultRuleService
{
    /**
     * Detects the Znuny Queue from a Zabbix host name using the regex from settings.
     */
    public function detectQueueFromHost(string $hostName): ?string
    {
        $regex = SettingsService::string('znuny_queue_from_host_regex');

        if (empty($regex)) {
            return null;
        }

        $pattern = '~'.str_replace('~', '\~', $regex).'~u';

        try {
            if (preg_match($pattern, $hostName, $matches) !== false) {
                if (isset($matches['queue']) && $matches['queue'] !== '') {
                    return $matches['queue'];
                }
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /**
     * Generates a CustomerUser login from a detected Queue using the template from settings.
     */
    public function customerUserFromQueue(string $queue): ?string
    {
        $template = SettingsService::string('znuny_customer_user_from_queue_template');

        if (empty($template)) {
            return null;
        }

        if (! str_contains($template, '<queue>')) {
            return null;
        }

        return str_replace('<queue>', $queue, $template);
    }

    /**
     * Resolves both Queue and CustomerUser candidates from a Zabbix host name.
     */
    public function resolveCandidates(string $hostName): array
    {
        $result = [
            'host_name' => $hostName,
            'queue' => null,
            'customer_user' => null,
            'warnings' => [],
        ];

        $queue = $this->detectQueueFromHost($hostName);

        if ($queue === null) {
            $result['warnings'][] = 'Queue could not be detected from host name.';

            return $result;
        }

        $result['queue'] = $queue;

        $customerUser = $this->customerUserFromQueue($queue);

        if ($customerUser === null) {
            $result['warnings'][] = 'CustomerUser could not be generated from queue template.';
        } else {
            $result['customer_user'] = $customerUser;
        }

        return $result;
    }
}
