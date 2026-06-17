<?php

namespace App\Services\Znuny;

use App\Services\Zabbix\ZabbixProblemFormatter;

class ZnunyTicketTextBuilder
{
    private ZabbixProblemFormatter $formatter;

    public function __construct(ZabbixProblemFormatter $formatter)
    {
        $this->formatter = $formatter;
    }

    /**
     * @param  array<string, mixed>  $problem
     * @return array{title: string, article_subject: string, article_body: string}
     */
    public function build(array $problem): array
    {
        $title = $problem['name'] ?? 'Unknown Problem';
        $articleSubject = 'Zabbix problem details';

        $body = [];
        $body[] = 'Problem: '.$title;

        $hosts = $problem['hosts'] ?? [];
        $host = reset($hosts);
        $displayName = $host['name'] ?? 'Unknown host';
        $technicalName = $host['host'] ?? 'Unknown host';

        $body[] = 'Display Name: '.$displayName;
        $body[] = 'Host Name: '.$technicalName;

        if (! empty($problem['host_ip'])) {
            $body[] = 'IP Address: '.$problem['host_ip'];
        }

        $severityValue = (int) ($problem['severity'] ?? 0);
        $severityLabel = $this->formatter->getSeverityFallback($severityValue);
        $body[] = 'Severity: '.$severityLabel;

        $clock = $problem['clock'] ?? null;
        $startedAt = $clock ? date('Y-m-d H:i:s', (int) $clock) : 'N/A';
        $body[] = 'Started At: '.$startedAt;

        $ageSeconds = $clock ? max(0, time() - $clock) : 0;
        $ageFormatted = $this->formatter->formatAge($ageSeconds);
        $body[] = 'Current Age: '.$ageFormatted;

        if (! empty($problem['opdata'])) {
            $body[] = '';
            $body[] = 'Operational Data: '.$problem['opdata'];
        }

        if (! empty($problem['tags']) && is_array($problem['tags'])) {
            $tagsStr = [];
            foreach ($problem['tags'] as $tag) {
                if (isset($tag['tag'])) {
                    $tagVal = isset($tag['value']) && $tag['value'] !== '' ? $tag['tag'].'='.$tag['value'] : $tag['tag'];
                    $tagsStr[] = $tagVal;
                }
            }
            if (! empty($tagsStr)) {
                $body[] = '';
                $body[] = 'Tags: '.implode(', ', $tagsStr);
            }
        }

        $body[] = '';
        $body[] = 'Created manually by Zabbix Znuny Integration.';

        return [
            'title' => $title,
            'article_subject' => $articleSubject,
            'article_body' => implode("\n", $body),
        ];
    }
}
