<?php

namespace App\Services\Zabbix;

use App\Models\AttentionFilter;
use Illuminate\Database\Eloquent\Collection;

class ZabbixAttentionFilterMatcher
{
    protected Collection $filters;

    public function __construct(Collection $filters)
    {
        $this->filters = $filters;
    }

    public static function load(): self
    {
        return new self(AttentionFilter::where('enabled', true)->get());
    }

    public function match(array $problem): array
    {
        $matchedIds = [];
        $matchedNames = [];

        foreach ($this->filters as $filter) {
            $matched = false;

            $valuesToMatch = [];
            $valuesToMatch[] = $problem['host_name'] ?? '';
            $valuesToMatch[] = $problem['name'] ?? '';

            if (isset($problem['hosts']) && is_array($problem['hosts'])) {
                foreach ($problem['hosts'] as $hostInfo) {
                    if (isset($hostInfo['name'])) {
                        $valuesToMatch[] = $hostInfo['name'];
                    }
                    if (isset($hostInfo['host'])) {
                        $valuesToMatch[] = $hostInfo['host'];
                    }
                }
            }
            $valuesToMatch = array_unique(array_filter($valuesToMatch));

            foreach ($valuesToMatch as $value) {
                if ($this->matchesPattern($filter, $value)) {
                    $matched = true;
                    break;
                }
            }

            if ($matched) {
                $matchedIds[] = $filter->id;
                $matchedNames[] = $filter->name;
            }
        }

        return [
            'attention_matched' => count($matchedIds) > 0,
            'attention_filter_ids' => $matchedIds,
            'attention_filter_names' => $matchedNames,
        ];
    }

    protected function matchesPattern(AttentionFilter $filter, string $value): bool
    {
        return @preg_match($filter->pattern, $value) === 1;
    }
}
