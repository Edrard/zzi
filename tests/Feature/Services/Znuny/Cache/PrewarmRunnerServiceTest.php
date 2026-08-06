<?php

namespace Tests\Feature\Services\Znuny\Cache;

use App\Services\Znuny\Cache\PrewarmRunnerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Illuminate\Process\PendingProcess;
use Tests\TestCase;

class PrewarmRunnerServiceTest extends TestCase
{
    protected string $fakePhpPath;

    protected function setUp(): void
    {
        parent::setUp();
        Process::fake();

        $this->fakePhpPath = tempnam(sys_get_temp_dir(), 'fake-php-');
        chmod($this->fakePhpPath, 0755);
        Config::set('app.znuny_prewarm.php_cli_binary', $this->fakePhpPath);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->fakePhpPath)) {
            unlink($this->fakePhpPath);
        }
        parent::tearDown();
    }

    public function test_lock_contention_launches_no_process()
    {
        $lockMock = \Mockery::mock(\Illuminate\Cache\Lock::class);
        $lockMock->shouldReceive('get')->once()->andReturn(false);
        Cache::shouldReceive('lock')->once()->andReturn($lockMock);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals(['status' => 'skipped_locked', 'message' => null], $result);
        Process::assertNothingRan();
    }

    public function test_non_existent_binary_fails()
    {
        Config::set('app.znuny_prewarm.php_cli_binary', '/path/to/php');
        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');
        $this->assertEquals('failed', $result['status']);
        Process::assertNothingRan();
    }

    public function test_relative_binary_fails()
    {
        Config::set('app.znuny_prewarm.php_cli_binary', 'php');
        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');
        $this->assertEquals('failed', $result['status']);
        Process::assertNothingRan();
    }

    public function test_non_executable_binary_fails()
    {
        $file = tempnam(sys_get_temp_dir(), 'non-exec-');
        try {
            chmod($file, 0644);
            Config::set('app.znuny_prewarm.php_cli_binary', $file);
            $service = app(PrewarmRunnerService::class);
            $result = $service->run('queues');
            $this->assertEquals('failed', $result['status']);
            Process::assertNothingRan();
        } finally {
            if (file_exists($file)) unlink($file);
        }
    }

    public function test_fpm_binary_is_rejected()
    {
        $dir = sys_get_temp_dir() . '/' . uniqid('fpm_test_', true);
        mkdir($dir);
        $file1 = $dir . '/php-fpm';
        $file2 = $dir . '/php-fpm84';
        touch($file1);
        touch($file2);
        try {
            chmod($file1, 0755);
            chmod($file2, 0755);

            Config::set('app.znuny_prewarm.php_cli_binary', $file1);
            $service = app(PrewarmRunnerService::class);
            $result = $service->run('queues');
            $this->assertEquals('failed', $result['status']);
            Process::assertNothingRan();

            Config::set('app.znuny_prewarm.php_cli_binary', $file2);
            $result2 = $service->run('queues');
            $this->assertEquals('failed', $result2['status']);
            Process::assertNothingRan();
        } finally {
            if (file_exists($file1)) unlink($file1);
            if (file_exists($file2)) unlink($file2);
            if (is_dir($dir)) rmdir($dir);
        }
    }

    public function test_four_runner_lock_keys()
    {
        $datasets = ['queues', 'agents', 'lookups', 'customer_users'];
        foreach ($datasets as $dataset) {
            $lockMock = \Mockery::mock(\Illuminate\Cache\Lock::class);
            $lockMock->shouldReceive('get')->once()->andReturn(false); // fast exit
            Cache::shouldReceive('lock')
                ->once()
                ->with("znuny_prewarm_{$dataset}_runner_lock", \Mockery::any())
                ->andReturn($lockMock);

            $service = app(PrewarmRunnerService::class);
            $service->run($dataset);
        }
        $this->assertTrue(true);
    }

    public function test_process_invocation_and_timeouts_600()
    {
        Config::set('app.znuny_prewarm.process_timeout_seconds', 600);
        Config::set('app.znuny_prewarm.lock_expiry_grace_seconds', 60);

        Process::fake([
            '*' => Process::result(output: 'PREWARM_RESULT=success', exitCode: 0)
        ]);

        $lockMock = \Mockery::mock(\Illuminate\Cache\Lock::class);
        $lockMock->shouldReceive('get')->once()->andReturn(true);
        $lockMock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->once()->with('znuny_prewarm_queues_runner_lock', 660)->andReturn($lockMock);

        $service = app(PrewarmRunnerService::class);
        $service->run('queues');

        Process::assertRan(function (PendingProcess $process) {
            return $process->timeout === 600 && $process->command[0] === $this->fakePhpPath;
        });
    }

    public function test_process_invocation_and_timeouts_900()
    {
        Config::set('app.znuny_prewarm.process_timeout_seconds', 900);
        Config::set('app.znuny_prewarm.lock_expiry_grace_seconds', 60);

        Process::fake([
            '*' => Process::result(output: 'PREWARM_RESULT=success', exitCode: 0)
        ]);

        $lockMock = \Mockery::mock(\Illuminate\Cache\Lock::class);
        $lockMock->shouldReceive('get')->once()->andReturn(true);
        $lockMock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->once()->with('znuny_prewarm_queues_runner_lock', 960)->andReturn($lockMock);

        $service = app(PrewarmRunnerService::class);
        $service->run('queues');

        Process::assertRan(function (PendingProcess $process) {
            return $process->timeout === 900 && $process->command[0] === $this->fakePhpPath;
        });
    }

    public function test_process_invocation_and_timeouts_grace_0()
    {
        Config::set('app.znuny_prewarm.process_timeout_seconds', 600);
        Config::set('app.znuny_prewarm.lock_expiry_grace_seconds', 0); // grace 0

        Process::fake([
            '*' => Process::result(output: 'PREWARM_RESULT=success', exitCode: 0)
        ]);

        $lockMock = \Mockery::mock(\Illuminate\Cache\Lock::class);
        $lockMock->shouldReceive('get')->once()->andReturn(true);
        $lockMock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->once()->with('znuny_prewarm_queues_runner_lock', 600)->andReturn($lockMock);

        $service = app(PrewarmRunnerService::class);
        $service->run('queues');

        Process::assertRan(function (PendingProcess $process) {
            return $process->timeout === 600 && $process->command[0] === $this->fakePhpPath;
        });
    }

    public function test_nonzero_exit_plus_success_sentinel_fails()
    {
        Process::fake([
            '*' => Process::result(output: 'PREWARM_RESULT=success', exitCode: 1)
        ]);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('failed', $result['status']);
    }

    public function test_zero_exit_without_sentinel_fails()
    {
        Process::fake([
            '*' => Process::result(output: 'some unrelated output', exitCode: 0)
        ]);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('failed', $result['status']);
    }

    public function test_duplicate_success_sentinels_fails()
    {
        Process::fake([
            '*' => Process::result(output: "PREWARM_RESULT=success\nPREWARM_RESULT=success", exitCode: 0)
        ]);

        $lockMock = \Mockery::mock(\Illuminate\Cache\Lock::class);
        $lockMock->shouldReceive('get')->once()->andReturn(true);
        $lockMock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->once()->with('znuny_prewarm_queues_runner_lock', \Mockery::any())->andReturn($lockMock);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('failed', $result['status']);
    }

    public function test_contradictory_sentinels_fails()
    {
        Process::fake([
            '*' => Process::result(output: "PREWARM_RESULT=success\nPREWARM_RESULT=failed", exitCode: 0)
        ]);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('failed', $result['status']);
    }

    public function test_unknown_sentinel_fails()
    {
        Process::fake([
            '*' => Process::result(output: 'PREWARM_RESULT=weird', exitCode: 0)
        ]);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('failed', $result['status']);
    }

    public function test_exact_success_sentinel_succeeds()
    {
        Process::fake([
            '*' => Process::result(output: 'PREWARM_RESULT=success', exitCode: 0)
        ]);

        $lockMock = \Mockery::mock(\Illuminate\Cache\Lock::class);
        $lockMock->shouldReceive('get')->once()->andReturn(true);
        $lockMock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->once()->with('znuny_prewarm_queues_runner_lock', \Mockery::any())->andReturn($lockMock);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('success', $result['status']);
    }

    public function test_exact_skipped_sentinel_succeeds()
    {
        Process::fake([
            '*' => Process::result(output: 'PREWARM_RESULT=skipped_locked', exitCode: 0)
        ]);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('skipped_locked', $result['status']);
    }

    public function test_exact_failed_sentinel_fails()
    {
        Process::fake([
            '*' => Process::result(output: 'PREWARM_RESULT=failed', exitCode: 0)
        ]);

        $lockMock = \Mockery::mock(\Illuminate\Cache\Lock::class);
        $lockMock->shouldReceive('get')->once()->andReturn(true);
        $lockMock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->once()->with('znuny_prewarm_queues_runner_lock', \Mockery::any())->andReturn($lockMock);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('failed', $result['status']);
    }

    public function test_failed_result_emits_structured_log()
    {
        Process::fake([
            '*' => Process::result(output: 'PREWARM_RESULT=failed', exitCode: 0)
        ]);

        $lockMock = \Mockery::mock(\Illuminate\Cache\Lock::class);
        $lockMock->shouldReceive('get')->once()->andReturn(true);
        $lockMock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->once()->with('znuny_prewarm_queues_runner_lock', \Mockery::any())->andReturn($lockMock);

        \Illuminate\Support\Facades\Log::spy();

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues', 'scheduler');

        $this->assertEquals('failed', $result['status']);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
            ->once()
            ->with('Prewarm runner failed.', \Mockery::on(function ($context) {
                return $context['dataset'] === 'queues' &&
                       $context['source'] === 'scheduler' &&
                       $context['status'] === 'failed' &&
                       isset($context['message']);
            }));
    }

    public function test_timeout_returns_timeout()
    {
        Process::fake([
            '*' => function () {
                throw new \Illuminate\Process\Exceptions\ProcessTimedOutException(
                    \Mockery::mock(\Symfony\Component\Process\Exception\ProcessTimedOutException::class),
                    \Mockery::mock(\Illuminate\Contracts\Process\ProcessResult::class)
                );
            }
        ]);

        $lockMock = \Mockery::mock(\Illuminate\Cache\Lock::class);
        $lockMock->shouldReceive('get')->once()->andReturn(true);
        $lockMock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->once()->with('znuny_prewarm_queues_runner_lock', \Mockery::any())->andReturn($lockMock);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('timeout', $result['status']);
    }

    public function test_timeout_emits_structured_log()
    {
        Process::fake([
            '*' => function () {
                throw new \Illuminate\Process\Exceptions\ProcessTimedOutException(
                    \Mockery::mock(\Symfony\Component\Process\Exception\ProcessTimedOutException::class),
                    \Mockery::mock(\Illuminate\Contracts\Process\ProcessResult::class)
                );
            }
        ]);

        $lockMock = \Mockery::mock(\Illuminate\Cache\Lock::class);
        $lockMock->shouldReceive('get')->once()->andReturn(true);
        $lockMock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->once()->with('znuny_prewarm_queues_runner_lock', \Mockery::any())->andReturn($lockMock);

        \Illuminate\Support\Facades\Log::spy();

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues', 'manual');

        $this->assertEquals('timeout', $result['status']);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
            ->once()
            ->with('Prewarm runner timeout.', \Mockery::on(function ($context) {
                return $context['dataset'] === 'queues' &&
                       $context['source'] === 'manual' &&
                       $context['status'] === 'timeout' &&
                       isset($context['message']);
            }));
    }

    public function test_launch_exception_fails()
    {
        Process::fake([
            '*' => function () {
                throw new \Exception('Launch error');
            }
        ]);

        $lockMock = \Mockery::mock(\Illuminate\Cache\Lock::class);
        $lockMock->shouldReceive('get')->once()->andReturn(true);
        $lockMock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->once()->with('znuny_prewarm_queues_runner_lock', \Mockery::any())->andReturn($lockMock);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('failed', $result['status']);
    }

    public function test_secret_in_stdout_on_nonzero_exit_is_redacted()
    {
        Process::fake([
            '*' => Process::result(output: 'Here is stdout secret: Bearer mysecrettoken', exitCode: 1)
        ]);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('failed', $result['status']);
        $this->assertStringContainsString('***', $result['message']);
        $this->assertStringNotContainsString('mysecrettoken', $result['message']);
    }

    public function test_secret_in_stderr_is_redacted()
    {
        Process::fake([
            '*' => Process::result(output: '', exitCode: 1, errorOutput: 'Error token: Bearer mysecrettoken')
        ]);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('failed', $result['status']);
        $this->assertStringContainsString('***', $result['message']);
        $this->assertStringNotContainsString('mysecrettoken', $result['message']);
    }

    public function test_secret_in_launch_exception_is_redacted()
    {
        Process::fake([
            '*' => function () {
                throw new \Exception('Failed to launch because of Bearer mysecrettoken');
            }
        ]);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('failed', $result['status']);
        $this->assertStringContainsString('***', $result['message']);
        $this->assertStringNotContainsString('mysecrettoken', $result['message']);
    }

    public function test_returned_runner_message_is_capped_at_500_characters()
    {
        $longString = str_repeat('A', 1000);
        Process::fake([
            '*' => Process::result(output: $longString, exitCode: 1)
        ]);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('failed', $result['status']);
        $this->assertTrue(strlen($result['message']) <= 500);
    }

    public function test_success_with_leading_space_fails()
    {
        Process::fake([
            '*' => Process::result(output: ' PREWARM_RESULT=success', exitCode: 0)
        ]);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('failed', $result['status']);
    }

    public function test_success_plus_malformed_line_fails()
    {
        Process::fake([
            '*' => Process::result(output: "PREWARM_RESULT=success\nPREWARM_RESULT=success ", exitCode: 0)
        ]);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('failed', $result['status']);
    }

    public function test_exact_success_unix_newline_succeeds()
    {
        Process::fake([
            '*' => Process::result(output: "PREWARM_RESULT=success\n", exitCode: 0)
        ]);

        $lockMock = \Mockery::mock(\Illuminate\Cache\Lock::class);
        $lockMock->shouldReceive('get')->once()->andReturn(true);
        $lockMock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->once()->with('znuny_prewarm_queues_runner_lock', \Mockery::any())->andReturn($lockMock);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('success', $result['status']);
    }

    public function test_exact_success_crlf_succeeds()
    {
        Process::fake([
            '*' => Process::result(output: "PREWARM_RESULT=success\r\n", exitCode: 0)
        ]);

        $lockMock = \Mockery::mock(\Illuminate\Cache\Lock::class);
        $lockMock->shouldReceive('get')->once()->andReturn(true);
        $lockMock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->once()->with('znuny_prewarm_queues_runner_lock', \Mockery::any())->andReturn($lockMock);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('success', $result['status']);
    }

    public function test_success_with_trailing_space_fails()
    {
        Process::fake([
            '*' => Process::result(output: 'PREWARM_RESULT=success ', exitCode: 0)
        ]);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('failed', $result['status']);
    }

    public function test_success_with_trailing_tab_fails()
    {
        Process::fake([
            '*' => Process::result(output: "PREWARM_RESULT=success\t", exitCode: 0)
        ]);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('failed', $result['status']);
    }

    public function test_ordinary_stdout_before_exact_sentinel_succeeds()
    {
        Process::fake([
            '*' => Process::result(output: "some ordinary output line\nPREWARM_RESULT=success", exitCode: 0)
        ]);

        $lockMock = \Mockery::mock(\Illuminate\Cache\Lock::class);
        $lockMock->shouldReceive('get')->once()->andReturn(true);
        $lockMock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->once()->with('znuny_prewarm_queues_runner_lock', \Mockery::any())->andReturn($lockMock);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('success', $result['status']);
    }

    public function test_ordinary_stdout_after_exact_sentinel_succeeds()
    {
        Process::fake([
            '*' => Process::result(output: "PREWARM_RESULT=success\nsome trailing info line", exitCode: 0)
        ]);

        $lockMock = \Mockery::mock(\Illuminate\Cache\Lock::class);
        $lockMock->shouldReceive('get')->once()->andReturn(true);
        $lockMock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->once()->with('znuny_prewarm_queues_runner_lock', \Mockery::any())->andReturn($lockMock);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('success', $result['status']);
    }

    public function test_line_containing_prewarm_result_plus_valid_sentinel_fails()
    {
        // The line "ordinary prefix PREWARM_RESULT=success" contains PREWARM_RESULT= but is not an exact sentinel.
        // Together with a real sentinel the result must still fail due to hasInvalidSentinel.
        Process::fake([
            '*' => Process::result(output: "ordinary prefix PREWARM_RESULT=success\nPREWARM_RESULT=success", exitCode: 0)
        ]);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('failed', $result['status']);
    }

    public function test_success_with_bare_cr_fails()
    {
        // "PREWARM_RESULT=success\r" — lone CR is not normalised by the parser (only \r\n is).
        // Process::result() may normalise the string; use a closure returning a mocked ProcessResult
        // so that output() returns the exact bytes we intend.
        $fakeResult = \Mockery::mock(\Illuminate\Contracts\Process\ProcessResult::class);
        $fakeResult->shouldReceive('output')->andReturn("PREWARM_RESULT=success\r");
        $fakeResult->shouldReceive('errorOutput')->andReturn('');
        $fakeResult->shouldReceive('successful')->andReturn(true);
        $fakeResult->shouldReceive('failed')->andReturn(false);
        $fakeResult->shouldReceive('exitCode')->andReturn(0);
        $fakeResult->shouldReceive('command')->andReturn('artisan znuny:cache:warm-queues');
        $fakeResult->shouldReceive('seeInOutput')->andReturn(false);
        $fakeResult->shouldReceive('seeInErrorOutput')->andReturn(false);
        $fakeResult->shouldReceive('throw')->andReturnSelf();
        $fakeResult->shouldReceive('throwIf')->andReturnSelf();

        Process::fake([
            '*' => function () use ($fakeResult) {
                return $fakeResult;
            }
        ]);

        $lockMock = \Mockery::mock(\Illuminate\Cache\Lock::class);
        $lockMock->shouldReceive('get')->once()->andReturn(true);
        $lockMock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->once()->with('znuny_prewarm_queues_runner_lock', \Mockery::any())->andReturn($lockMock);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('failed', $result['status']);
    }

    public function test_cache_lock_creation_exception_fails()
    {
        Cache::shouldReceive('lock')->once()->andThrow(new \Exception('Redis connection lost with secret: Bearer token123'));

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('failed', $result['status']);
        $this->assertStringContainsString('***', $result['message']);
        $this->assertStringNotContainsString('token123', $result['message']);
    }

    public function test_lock_get_exception_fails()
    {
        $lockMock = \Mockery::mock(\Illuminate\Cache\Lock::class);
        $lockMock->shouldReceive('get')->once()->andThrow(new \Exception('Failed to get lock because Bearer token456'));
        Cache::shouldReceive('lock')->once()->andReturn($lockMock);

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues');

        $this->assertEquals('failed', $result['status']);
        $this->assertStringContainsString('***', $result['message']);
        $this->assertStringNotContainsString('token456', $result['message']);
    }

    public function test_release_exception_after_success_logs_but_returns_success()
    {
        Process::fake([
            '*' => Process::result(output: 'PREWARM_RESULT=success', exitCode: 0)
        ]);

        $lockMock = \Mockery::mock(\Illuminate\Cache\Lock::class);
        $lockMock->shouldReceive('get')->once()->andReturn(true);
        $lockMock->shouldReceive('release')->once()->andThrow(new \Exception('Redis failed release'));
        Cache::shouldReceive('lock')->once()->andReturn($lockMock);

        \Illuminate\Support\Facades\Log::spy();

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues', 'manual');

        $this->assertEquals('success', $result['status']);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
            ->once()
            ->with('Prewarm runner lock release failed.', \Mockery::on(function ($context) {
                return $context['dataset'] === 'queues' &&
                       $context['source'] === 'manual' &&
                       $context['status'] === 'success' &&
                       str_contains($context['message'], 'Redis failed release');
            }));
    }

    public function test_release_exception_sanitizes_secret_in_log()
    {
        Process::fake([
            '*' => Process::result(output: 'PREWARM_RESULT=success', exitCode: 0)
        ]);

        $lockMock = \Mockery::mock(\Illuminate\Cache\Lock::class);
        $lockMock->shouldReceive('get')->once()->andReturn(true);
        $lockMock->shouldReceive('release')->once()->andThrow(new \Exception('Failed release secret: Bearer secret789'));
        Cache::shouldReceive('lock')->once()->andReturn($lockMock);

        \Illuminate\Support\Facades\Log::spy();

        $service = app(PrewarmRunnerService::class);
        $result = $service->run('queues', 'manual');

        $this->assertEquals('success', $result['status']);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
            ->once()
            ->with('Prewarm runner lock release failed.', \Mockery::on(function ($context) {
                return $context['dataset'] === 'queues' &&
                       $context['source'] === 'manual' &&
                       $context['status'] === 'success' &&
                       str_contains($context['message'], '***') && !str_contains($context['message'], 'secret789');
            }));
    }
}
