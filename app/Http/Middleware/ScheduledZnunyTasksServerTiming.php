<?php

namespace App\Http\Middleware;

use App\Support\ScheduledZnunyTasksRequestProfiler;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduledZnunyTasksServerTiming
{
    public function handle(Request $request, Closure $next)
    {
        $path = trim($request->path(), '/');

        if ($request->method() !== 'GET' || $path !== 'admin/scheduled-znuny-tasks') {
            return $next($request);
        }

        if ($request->hasHeader('X-Livewire')) {
            return $next($request);
        }

        $profiler = new ScheduledZnunyTasksRequestProfiler;
        app()->instance(ScheduledZnunyTasksRequestProfiler::class, $profiler);
        $profiler->activate();

        DB::listen(function ($query) use ($profiler) {
            $profiler->addDatabaseQuery($query->time);
        });

        $start = hrtime(true);
        $response = $next($request);
        $end = hrtime(true);

        $appTotalMs = ($end - $start) / 1e6;

        $durations = $profiler->getDurations();
        $counts = $profiler->getCounts();

        $dbMs = $profiler->getDbCumulativeMs();
        $prepareMs = $durations['prepare_options'] ?? 0.0;
        $queueMs = $durations['queue_lookup'] ?? 0.0;
        $customerMs = $durations['customer_lookup'] ?? 0.0;
        $ownerMs = $durations['owner_lookup'] ?? 0.0;
        $cronMs = $durations['cron_display'] ?? 0.0;
        $filterQueueMs = $durations['filter_queue'] ?? 0.0;
        $filterOwnerMs = $durations['filter_owner'] ?? 0.0;

        $remainderMs = max(0, $appTotalMs - $prepareMs - $cronMs - $filterQueueMs - $filterOwnerMs);

        $timing = [
            sprintf('app_total;dur=%F', $appTotalMs),
            sprintf('db;dur=%F', $dbMs),
            sprintf('prepare_options;dur=%F', $prepareMs),
            sprintf('queue_lookup;dur=%F', $queueMs),
            sprintf('customer_lookup;dur=%F', $customerMs),
            sprintf('owner_lookup;dur=%F', $ownerMs),
            sprintf('cron_display;dur=%F', $cronMs),
            sprintf('filter_queue;dur=%F', $filterQueueMs),
            sprintf('filter_owner;dur=%F', $filterOwnerMs),
            sprintf('remainder;dur=%F', $remainderMs),
        ];

        $response->headers->set('Server-Timing', implode(', ', $timing));

        $response->headers->set('X-Scheduled-Znuny-Profile-Db-Queries', (string) $profiler->getDbQueryCount());

        $bytes = 0;
        if (method_exists($response, 'getContent') && is_string($response->getContent())) {
            $bytes = strlen($response->getContent());
        }
        $response->headers->set('X-Scheduled-Znuny-Profile-Response-Bytes', (string) $bytes);

        $response->headers->set('X-Scheduled-Znuny-Profile-Prepare-Calls', (string) ($counts['prepare_options'] ?? 0));
        $response->headers->set('X-Scheduled-Znuny-Profile-Customer-Lookup-Calls', (string) ($counts['customer_lookup'] ?? 0));
        $response->headers->set('X-Scheduled-Znuny-Profile-Owner-Lookup-Calls', (string) ($counts['owner_lookup'] ?? 0));
        $response->headers->set('X-Scheduled-Znuny-Profile-Cron-Calls', (string) ($counts['cron_display'] ?? 0));

        return $response;
    }
}
