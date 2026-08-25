<?php

namespace App\Console\Commands;

use App\Models\ZnunyOwnerSuggestionObservation;
use App\Services\OwnerSuggestion\OwnerSuggestionStatsRebuilder;
use App\Services\OwnerSuggestion\ProblemNameNormalizer;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SeedOwnerSuggestionDemoCommand extends Command
{
    protected $signature = 'owner-suggestion:seed-demo
                            {--queue=Social product : The queue name for the demo data}
                            {--problem= : The Zabbix problem name for the demo data}
                            {--owner-logins= : Comma-separated list of owner logins in ranked order}
                            {--clear : Safely delete all demo rows for the given problem and queue}';

    protected $description = 'Seed demo raw observations for owner suggestion development';

    public function handle(OwnerSuggestionStatsRebuilder $rebuilder, ProblemNameNormalizer $normalizer): int
    {
        $queueName = $this->option('queue') ?? 'Social product';
        $problemName = $this->option('problem');

        if (empty($problemName)) {
            $this->error('The --problem option is required.');

            return self::FAILURE;
        }

        $normalizedProblemKey = $normalizer->normalize($problemName);

        if (empty($normalizedProblemKey)) {
            $this->error('Failed to normalize problem name.');

            return self::FAILURE;
        }

        $isClearMode = $this->option('clear');
        $ownerLoginsOption = $this->option('owner-logins');
        $logins = [];

        if (! $isClearMode) {
            if (empty($ownerLoginsOption)) {
                $this->error('The --owner-logins option is required.');

                return self::FAILURE;
            }

            $logins = array_values(array_unique(array_filter(array_map('trim', explode(',', $ownerLoginsOption)))));

            if (empty($logins)) {
                $this->error('The --owner-logins option must contain at least one valid login.');

                return self::FAILURE;
            }
        }

        $demoKey = substr(sha1($queueName.'|'.$problemName), 0, 12);
        $demoPrefix = "owner-suggestion-demo-{$demoKey}-";

        $deletedCount = ZnunyOwnerSuggestionObservation::where('zabbix_event_id', 'like', "{$demoPrefix}%")->delete();

        if ($isClearMode) {
            $rebuilder->rebuild();

            $this->info('Demo data cleaned successfully.');
            $this->info("Problem name: {$problemName}");
            $this->info("Queue: {$queueName}");
            $this->info("Demo prefix: {$demoPrefix}");
            $this->info("Cleanup: deleted {$deletedCount} demo observations.");
            $this->info('Stats written: Rebuild complete.');

            return self::SUCCESS;
        }

        $observations = [];
        $now = Carbon::now();

        $candidates = [];
        foreach ($logins as $index => $login) {
            $count = max(1, 30 - ($index * 10));
            $candidates[$login] = $count;
        }

        $totalInserted = 0;

        foreach ($candidates as $login => $count) {
            for ($i = 0; $i < $count; $i++) {
                $observations[] = [
                    'zabbix_event_id' => $demoPrefix.uniqid(),
                    'problem_name' => $problemName,
                    'normalized_problem_key' => $normalizedProblemKey,
                    'queue_name' => $queueName,
                    'owner_id' => null,
                    'owner_login' => $login,
                    'created_at' => $now->copy()->subMinutes(rand(1, 1440))->format('Y-m-d H:i:s'),
                    'updated_at' => $now->copy()->subMinutes(rand(1, 1440))->format('Y-m-d H:i:s'),
                ];
                $totalInserted++;
            }
        }

        foreach (array_chunk($observations, 100) as $chunk) {
            ZnunyOwnerSuggestionObservation::insert($chunk);
        }

        $rebuilder->rebuild();

        $this->info('Demo data seeded successfully.');
        $this->info("Problem name: {$problemName}");
        $this->info("Queue: {$queueName}");
        $this->info("Demo prefix: {$demoPrefix}");
        $this->info('Owner logins in rank order: '.implode(', ', $logins));
        if ($deletedCount > 0) {
            $this->info("Cleanup: deleted {$deletedCount} old demo observations.");
        }
        $this->info("Observations inserted: {$totalInserted}");
        $this->info('Stats written: Rebuild complete.');

        return self::SUCCESS;
    }
}
