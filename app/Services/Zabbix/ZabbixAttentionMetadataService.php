<?php

namespace App\Services\Zabbix;

use Illuminate\Support\Facades\Redis;

class ZabbixAttentionMetadataService
{
    public function recalculateCachedProblems(): void
    {
        $cache = app(ZabbixProblemCache::class);
        $problems = $cache->all();

        if (empty($problems)) {
            return;
        }

        $matcher = ZabbixAttentionFilterMatcher::load();

        $updatedProblems = [];
        foreach ($problems as $problem) {
            $metadata = $matcher->match($problem);
            $problem['attention_matched'] = $metadata['attention_matched'];
            $problem['attention_filter_ids'] = $metadata['attention_filter_ids'];
            $problem['attention_filter_names'] = $metadata['attention_filter_names'];

            $updatedProblems[] = $problem;
        }

        $lastPoll = $cache->lastPoll();
        $ttl = $lastPoll['ttl_seconds'] ?? (3 * 60);

        // Calculate remaining TTL of index
        $indexTtl = Redis::ttl('zabbix:problems:index');

        // Redis::ttl returns -1 if key exists but has no associated expire, and -2 if key does not exist.
        if ($indexTtl > 0) {
            $cache->putMany($updatedProblems, $indexTtl);
        } else {
            $cache->putMany($updatedProblems, $ttl);
        }
    }
}
