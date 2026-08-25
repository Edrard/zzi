<?php

namespace App\Services\Zabbix;

use App\Models\ZabbixProblemFilter;
use Illuminate\Database\Eloquent\Collection;

class ZabbixProblemFilterMatcher
{
    protected Collection $filters;

    public function __construct(Collection $filters)
    {
        $this->filters = $filters;
    }

    public static function load(): self
    {
        return new self(ZabbixProblemFilter::where('enabled', true)->get());
    }

    public function exclude(array $problem): bool
    {
        foreach ($this->filters as $filter) {
            $matched = false;

            if ($filter->field === 'host') {
                $valuesToMatch = [$problem['host_name'] ?? ''];
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
                $valuesToMatch = array_unique($valuesToMatch);

                foreach ($valuesToMatch as $value) {
                    if ($this->matches($filter, $value)) {
                        $matched = true;
                        break;
                    }
                }
            } else {
                $valueToMatch = $problem['name'] ?? '';
                $matched = $this->matches($filter, $valueToMatch);
            }

            if ($matched) {
                return true;
            }
        }

        return false;
    }

    protected function matches(ZabbixProblemFilter $filter, string $value): bool
    {
        if ($filter->match_type === 'regex') {
            return @preg_match($filter->pattern, $value) === 1;
        }

        if ($filter->case_sensitive) {
            return str_contains($value, $filter->pattern);
        }

        return stripos($value, $filter->pattern) !== false;
    }
}
