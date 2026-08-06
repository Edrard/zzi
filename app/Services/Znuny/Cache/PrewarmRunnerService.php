<?php

namespace App\Services\Znuny\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Process\Exceptions\ProcessTimedOutException;

class PrewarmRunnerService
{
    private const DATASETS = [
        'queues' => [
            'command' => 'znuny:cache:warm-queues',
            'interval_key' => 'znuny_prewarm_queues_interval_minutes',
            'default_interval' => 5,
            'lock_key' => 'znuny_prewarm_queues_runner_lock',
        ],
        'agents' => [
            'command' => 'znuny:cache:warm-agents',
            'interval_key' => 'znuny_prewarm_agents_interval_minutes',
            'default_interval' => 5,
            'lock_key' => 'znuny_prewarm_agents_runner_lock',
        ],
        'lookups' => [
            'command' => 'znuny:cache:warm-lookups',
            'interval_key' => 'znuny_prewarm_lookups_interval_minutes',
            'default_interval' => 60,
            'lock_key' => 'znuny_prewarm_lookups_runner_lock',
        ],
        'customer_users' => [
            'command' => 'znuny:cache:warm-customer-users',
            'interval_key' => 'znuny_prewarm_customer_users_interval_minutes',
            'default_interval' => 30,
            'lock_key' => 'znuny_prewarm_customer_users_runner_lock',
        ],
    ];

    private PrewarmErrorSanitizer $sanitizer;

    public function __construct(PrewarmErrorSanitizer $sanitizer)
    {
        $this->sanitizer = $sanitizer;
    }

    /**
     * Run the prewarm command for the given dataset.
     * Returns normalized result: ['status' => 'success'|'failed'|'timeout'|'skipped_locked', 'message' => ?string]
     */
    public function run(string $dataset, string $source = 'unknown'): array
    {
        if (! isset(self::DATASETS[$dataset])) {
            $result = ['status' => 'failed', 'message' => 'Invalid dataset.'];
            \Illuminate\Support\Facades\Log::error('Prewarm runner failed.', ['dataset' => $dataset, 'source' => $source, 'status' => $result['status'], 'message' => $result['message']]);
            return $result;
        }

        $phpCliBinary = config('app.znuny_prewarm.php_cli_binary');
        $phpCliBinary = is_string($phpCliBinary) ? trim($phpCliBinary) : '';

        if ($phpCliBinary === '' || !str_starts_with($phpCliBinary, '/')) {
            $result = ['status' => 'failed', 'message' => 'Configured PHP CLI binary is invalid or not executable.'];
            \Illuminate\Support\Facades\Log::error('Prewarm runner failed.', ['dataset' => $dataset, 'source' => $source, 'status' => $result['status'], 'message' => $result['message']]);
            return $result;
        }

        if (!is_file($phpCliBinary) || !is_executable($phpCliBinary)) {
            $result = ['status' => 'failed', 'message' => 'Configured PHP CLI binary is invalid or not executable.'];
            \Illuminate\Support\Facades\Log::error('Prewarm runner failed.', ['dataset' => $dataset, 'source' => $source, 'status' => $result['status'], 'message' => $result['message']]);
            return $result;
        }

        $basename = basename($phpCliBinary);
        if ($basename === 'php-fpm' || str_starts_with($basename, 'php-fpm')) {
            $result = ['status' => 'failed', 'message' => 'Configured PHP CLI binary is invalid or not executable.'];
            \Illuminate\Support\Facades\Log::error('Prewarm runner failed.', ['dataset' => $dataset, 'source' => $source, 'status' => $result['status'], 'message' => $result['message']]);
            return $result;
        }

        $config = self::DATASETS[$dataset];
        $timeout = max(1, (int) config(
            'app.znuny_prewarm.process_timeout_seconds',
            600,
        ));

        $grace = max(0, (int) config(
            'app.znuny_prewarm.lock_expiry_grace_seconds',
            60,
        ));

        $effectiveLockExpiry = $timeout + $grace;

        try {
            $lock = Cache::lock($config['lock_key'], $effectiveLockExpiry);

            if (! $lock->get()) {
                $result = ['status' => 'skipped_locked', 'message' => null];
                \Illuminate\Support\Facades\Log::notice('Prewarm runner skipped locked.', ['dataset' => $dataset, 'source' => $source, 'status' => $result['status'], 'message' => $result['message']]);
                return $result;
            }
        } catch (\Throwable $e) {
            $result = [
                'status' => 'failed',
                'message' => $this->sanitizer->sanitize('Lock acquisition failed: ' . $e->getMessage())
            ];
            \Illuminate\Support\Facades\Log::error('Prewarm runner failed.', ['dataset' => $dataset, 'source' => $source, 'status' => $result['status'], 'message' => $result['message']]);
            return $result;
        }

        $finalResult = null;

        try {
            $commandArray = [
                $phpCliBinary,
                base_path('artisan'),
                $config['command']
            ];

            $result = Process::timeout($timeout)->run($commandArray);

            $output = $result->output();

            $lines = explode("\n", str_replace("\r\n", "\n", $output));
            $sentinels = [];
            $hasInvalidSentinel = false;
            $allowedMap = [
                'PREWARM_RESULT=success' => 'success',
                'PREWARM_RESULT=skipped_locked' => 'skipped_locked',
                'PREWARM_RESULT=failed' => 'failed',
            ];
            foreach ($lines as $line) {
                if (isset($allowedMap[$line])) {
                    $sentinels[] = $allowedMap[$line];

                    continue;
                }

                if (str_contains($line, 'PREWARM_RESULT=')) {
                    $hasInvalidSentinel = true;
                }
            }

            if (! $result->successful()) {
                $errorMsg = trim($result->errorOutput() . ' ' . $output);
                $finalResult = [
                    'status' => 'failed',
                    'message' => $this->sanitizer->sanitize($errorMsg ?: 'Process exited with non-zero status.')
                ];
                \Illuminate\Support\Facades\Log::error('Prewarm runner failed.', ['dataset' => $dataset, 'source' => $source, 'status' => $finalResult['status'], 'message' => $finalResult['message']]);
                return $finalResult;
            }
            if (count($sentinels) !== 1 || $hasInvalidSentinel) {
                $finalResult = [
                    'status' => 'failed',
                    'message' => 'Missing, duplicate, or contradictory sentinel in output.'
                ];
                \Illuminate\Support\Facades\Log::error('Prewarm runner failed.', ['dataset' => $dataset, 'source' => $source, 'status' => $finalResult['status'], 'message' => $finalResult['message']]);
                return $finalResult;
            }

            $sentinel = $sentinels[0];

            if ($sentinel === 'success') {
                $finalResult = ['status' => 'success', 'message' => null];
                return $finalResult;
            }
            if ($sentinel === 'skipped_locked') {
                $finalResult = ['status' => 'skipped_locked', 'message' => null];
                \Illuminate\Support\Facades\Log::notice('Prewarm runner skipped locked.', ['dataset' => $dataset, 'source' => $source, 'status' => $finalResult['status'], 'message' => $finalResult['message']]);
                return $finalResult;
            }
            if ($sentinel === 'failed') {
                $finalResult = [
                    'status' => 'failed',
                    'message' => 'Command reported failure.'
                ];
                \Illuminate\Support\Facades\Log::error('Prewarm runner failed.', ['dataset' => $dataset, 'source' => $source, 'status' => $finalResult['status'], 'message' => $finalResult['message']]);
                return $finalResult;
            }

            $finalResult = [
                'status' => 'failed',
                'message' => 'Unknown sentinel value.'
            ];
            \Illuminate\Support\Facades\Log::error('Prewarm runner failed.', ['dataset' => $dataset, 'source' => $source, 'status' => $finalResult['status'], 'message' => $finalResult['message']]);
            return $finalResult;
        } catch (ProcessTimedOutException $e) {
            $finalResult = [
                'status' => 'timeout',
                'message' => $this->sanitizer->sanitize('Process timed out.')
            ];
            \Illuminate\Support\Facades\Log::error('Prewarm runner timeout.', ['dataset' => $dataset, 'source' => $source, 'status' => $finalResult['status'], 'message' => $finalResult['message']]);
            return $finalResult;
        } catch (\Throwable $e) {
            $finalResult = [
                'status' => 'failed',
                'message' => $this->sanitizer->sanitize($e->getMessage())
            ];
            \Illuminate\Support\Facades\Log::error('Prewarm runner exception.', ['dataset' => $dataset, 'source' => $source, 'status' => $finalResult['status'], 'message' => $finalResult['message']]);
            return $finalResult;
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $e) {
                $sanitizedReleaseMessage = $this->sanitizer->sanitize($e->getMessage());
                \Illuminate\Support\Facades\Log::error('Prewarm runner lock release failed.', [
                    'dataset' => $dataset,
                    'source' => $source,
                    'status' => $finalResult['status'] ?? 'failed',
                    'message' => $sanitizedReleaseMessage,
                ]);

                if ($finalResult === null) {
                    $finalResult = [
                        'status' => 'failed',
                        'message' => $this->sanitizer->sanitize('Lock release failed: ' . $e->getMessage())
                    ];
                    return $finalResult;
                }
            }
        }
    }
}
