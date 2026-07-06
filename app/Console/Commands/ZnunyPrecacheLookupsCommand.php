<?php

namespace App\Console\Commands;

use App\Services\Znuny\ZnunyCachedLookupService;
use Illuminate\Console\Command;
use Throwable;

class ZnunyPrecacheLookupsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'znuny:precache-lookups';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Warm up Znuny UI lookup cache (queues, dictionaries, owner options, customer searches)';

    /**
     * Execute the console command.
     */
    public function handle(ZnunyCachedLookupService $lookupService)
    {
        $this->info('Starting Znuny UI lookup precache...');

        // Clear existing version to force refresh
        $lookupService->invalidateCache();

        $failures = 0;

        // 1. Dictionaries
        $this->info('Caching dictionary lists...');
        try {
            $states = $lookupService->getTicketStates();
            $this->line(' - Cached '.count($states).' states.');
        } catch (Throwable $e) {
            $this->error(' - Failed states: '.$e->getMessage());
            $failures++;
        }

        try {
            $priorities = $lookupService->getTicketPriorities();
            $this->line(' - Cached '.count($priorities).' priorities.');
        } catch (Throwable $e) {
            $this->error(' - Failed priorities: '.$e->getMessage());
            $failures++;
        }

        try {
            $types = $lookupService->getTicketTypes();
            $this->line(' - Cached '.count($types).' types.');
        } catch (Throwable $e) {
            $this->error(' - Failed types: '.$e->getMessage());
            $failures++;
        }

        // 2. Queues
        $this->info('Caching queue lists...');
        try {
            $allQueues = $lookupService->getAllQueues();
            $this->line(' - Cached '.count($allQueues).' total valid queues.');

            $filteredQueues = $lookupService->getFilteredQueueOptions();
            $this->line(' - Cached '.count($filteredQueues).' filtered queue options.');
        } catch (Throwable $e) {
            $this->error(' - Failed queues: '.$e->getMessage());
            $failures++;
            $filteredQueues = [];
        }

        // 3. Queue-dependent lookups
        $this->info('Caching queue-dependent owner and customer options...');
        $ownerSetsCached = 0;
        $customerSearchesCached = 0;
        $customerOptionsCount = 0;
        $searchTermsCount = 0;
        $templatesResolved = 0;

        $progress = $this->output->createProgressBar(count($filteredQueues));
        $progress->start();

        foreach ($filteredQueues as $queueName => $label) {
            try {
                $lookupService->getAssignableOwnerOptionsForQueue($queueName, true);
                $ownerSetsCached++;
            } catch (Throwable $e) {
                $failures++;
            }

            try {
                $terms = $lookupService->getCustomerUserSearchTerms($queueName);
                $searchTermsCount += count($terms);

                $customers = $lookupService->getCustomerUserPrimaryOptionsForQueue($queueName);
                $customerOptionsCount += count($customers);
                $customerSearchesCached++;
            } catch (Throwable $e) {
                $failures++;
            }

            try {
                $candidate = $lookupService->resolveTemplateCandidate($queueName);
                if ($candidate) {
                    $lookupService->getCustomerUserLabel($candidate);
                    $templatesResolved++;
                }
            } catch (Throwable $e) {
                $failures++;
            }

            $progress->advance();
        }

        $progress->finish();
        $this->newLine(2);

        $this->info('Summary:');
        $this->line(' - Queues cached: '.count($filteredQueues));
        $this->line(' - Owner option sets cached: '.$ownerSetsCached);
        $this->line(' - Customer primary searches cached: '.$customerSearchesCached);
        $this->line(' - Customer search terms generated: '.$searchTermsCount);
        $this->line(' - Customer options cached: '.$customerOptionsCount);
        $this->line(' - Template candidates resolved: '.$templatesResolved);
        $this->line(' - Failures: '.$failures);

        if ($failures > 0) {
            $this->warn('Precache finished with '.$failures.' failures.');

            return self::FAILURE;
        }

        $this->info('Precache completed successfully.');

        return self::SUCCESS;
    }
}
