<?php

namespace Tests\Feature\Filament\Pages;

use App\Filament\Pages\ZnunyDataStatus;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\Znuny\Cache\PrewarmRunnerService;
use App\Services\Znuny\Cache\ZnunyAgentCacheReadService;
use App\Services\Znuny\Cache\ZnunyCustomerUserCacheReadService;
use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use App\Services\Znuny\Cache\ZnunyQueueCacheReadService;
use App\Services\Znuny\ZnunyInlineImageWarmerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ZnunyDataStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_authorization()
    {
        $nonAdmin = User::factory()->create(['role' => 'user']);
        $this->actingAs($nonAdmin)->get(ZnunyDataStatus::getUrl())->assertStatus(403);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(ZnunyDataStatus::getUrl())->assertStatus(200);
    }

    public function test_page_renders_full_metadata_and_translated_labels()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $baseMetadata = [
            'item_count' => 10,
            'last_attempt_at' => now()->toDateTimeString(),
            'last_successful_refresh_at' => now()->toDateTimeString(),
            'active_generation' => 'gen1',
            'last_error' => null,
        ];

        $queueServiceMock = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueServiceMock->shouldReceive('getMetadata')->andReturn(array_merge($baseMetadata, ['status' => 'ready']));
        $queueServiceMock->shouldReceive('getSnapshot')->andReturn([]);
        $this->instance(ZnunyQueueCacheReadService::class, $queueServiceMock);

        $agentServiceMock = Mockery::mock(ZnunyAgentCacheReadService::class);
        $agentServiceMock->shouldReceive('getMetadata')->andReturn(array_merge($baseMetadata, ['status' => 'refreshing']));
        $this->instance(ZnunyAgentCacheReadService::class, $agentServiceMock);

        $lookupServiceMock = Mockery::mock(ZnunyLookupCacheReadService::class);
        $lookupServiceMock->shouldReceive('getMetadata')->andReturn(array_merge($baseMetadata, ['status' => 'failed', 'last_error' => 'API Timeout']));
        $this->instance(ZnunyLookupCacheReadService::class, $lookupServiceMock);

        $customerUserServiceMock = Mockery::mock(ZnunyCustomerUserCacheReadService::class);
        $customerUserServiceMock->shouldReceive('getMetadata')->andReturn(array_merge($baseMetadata, ['status' => 'missing', 'active_generation' => '']));
        $this->instance(ZnunyCustomerUserCacheReadService::class, $customerUserServiceMock);

        Setting::updateOrCreate(['key' => 'znuny_prewarm_queues_interval_minutes'], ['value' => 5, 'type' => 'integer']);
        Setting::updateOrCreate(['key' => 'znuny_prewarm_agents_interval_minutes'], ['value' => 5, 'type' => 'integer']);
        Setting::updateOrCreate(['key' => 'znuny_prewarm_lookups_interval_minutes'], ['value' => 60, 'type' => 'integer']);
        Setting::updateOrCreate(['key' => 'znuny_prewarm_customer_users_interval_minutes'], ['value' => 30, 'type' => 'integer']);
        SettingsService::clearAllCaches();

        $runnerMock = Mockery::mock(PrewarmRunnerService::class);
        $runnerMock->shouldReceive('run')->never();
        $this->instance(PrewarmRunnerService::class, $runnerMock);

        app()->setLocale('uk');

        Livewire::actingAs($admin)
            ->test(ZnunyDataStatus::class)
            ->assertSee('Стан даних Znuny') // Title
            ->assertSee('Черги') // Dataset queues
            ->assertSee('Агенти та доступ до черг') // Dataset agents
            ->assertSee('Довідники') // Dataset lookups
            ->assertSee('CustomerUsers за чергами') // Dataset customer_users
            ->assertSee('Назва набору даних') // Dataset name field
            ->assertSee('Внутрішній ключ')
            ->assertSee('Статус')
            ->assertSee('Остання спроба')
            ->assertSee('Останнє успішне оновлення')
            ->assertSee('Активне покоління')
            ->assertSee('Інтервал оновлення')
            ->assertSee('Остання помилка')
            ->assertSee('Кількість нормалізованих черг.')
            ->assertSee('Кількість агентів. Матриця доступу агентів до черг зберігається в наборі даних, але окремо не рахується.')
            ->assertSee(__('znuny_data_status.descriptions.customer_users'))
            ->assertSee(__('znuny_data_status.descriptions.lookups'))
            ->assertSee('queues')
            ->assertSee('agents')
            ->assertSee('lookups')
            ->assertSee('customer_users')
            ->assertSee('Готовий') // translated ready
            ->assertSee('Оновлюється') // translated refreshing
            ->assertSee('Помилка') // translated failed
            ->assertSee('Відсутній') // translated missing
            ->assertSee('API Timeout')
            ->assertSee('5 хв.')
            ->assertSee('60 хв.')
            ->assertSee('30 хв.')
            ->assertDontSee('Znuny Cache Status')
            ->assertDontSee('unknown');
    }

    public function test_actions_call_runner_and_redirect_to_self()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $baseMetadata = [
            'status' => 'ready',
            'item_count' => 10,
            'last_attempt_at' => now()->toDateTimeString(),
            'last_successful_refresh_at' => now()->toDateTimeString(),
            'active_generation' => 'gen1',
            'last_error' => null,
        ];

        $queueServiceMock = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueServiceMock->shouldReceive('getMetadata')->andReturn($baseMetadata);
        $queueServiceMock->shouldReceive('getSnapshot')->andReturn([]);
        $this->instance(ZnunyQueueCacheReadService::class, $queueServiceMock);

        $agentServiceMock = Mockery::mock(ZnunyAgentCacheReadService::class);
        $agentServiceMock->shouldReceive('getMetadata')->andReturn($baseMetadata);
        $agentServiceMock->shouldReceive('getSnapshot')->andReturn([]);
        $this->instance(ZnunyAgentCacheReadService::class, $agentServiceMock);

        $lookupServiceMock = Mockery::mock(ZnunyLookupCacheReadService::class);
        $lookupServiceMock->shouldReceive('getMetadata')->andReturn($baseMetadata);
        $lookupServiceMock->shouldReceive('getSnapshot')->andReturn([]);
        $this->instance(ZnunyLookupCacheReadService::class, $lookupServiceMock);

        $customerUserServiceMock = Mockery::mock(ZnunyCustomerUserCacheReadService::class);
        $customerUserServiceMock->shouldReceive('getMetadata')->andReturn($baseMetadata);
        $customerUserServiceMock->shouldReceive('getSnapshot')->andReturn([]);
        $this->instance(ZnunyCustomerUserCacheReadService::class, $customerUserServiceMock);

        app()->setLocale('uk');

        // Test lookups - manual action and audit log
        $runnerMock = Mockery::mock(PrewarmRunnerService::class);
        $runnerMock->shouldReceive('run')->with('lookups', 'manual')->once()->andReturn(['status' => 'success', 'message' => null]);
        $this->instance(PrewarmRunnerService::class, $runnerMock);

        Livewire::actingAs($admin)
            ->test(ZnunyDataStatus::class)
            ->callInfolistAction('lookups_section', 'rewarm_lookups')
            ->assertNotified('Набір "Довідники" успішно оновлено')
            ->assertRedirect(ZnunyDataStatus::getUrl());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'znuny_prewarm_manual_refresh',
            'entity_type' => 'znuny_prewarm_dataset',
            'context->dataset' => 'lookups',
            'context->status' => 'success',
            'context->source' => 'manual',
            'user_id' => $admin->id,
        ]);

        $matchingAuditRows = AuditLog::query()
            ->where('action', 'znuny_prewarm_manual_refresh')
            ->where('entity_type', 'znuny_prewarm_dataset')
            ->where('user_id', $admin->id)
            ->get()
            ->filter(static function (AuditLog $auditLog): bool {
                $context = is_array($auditLog->context) ? $auditLog->context : [];

                return ($context['dataset'] ?? null) === 'lookups'
                    && ($context['status'] ?? null) === 'success'
                    && ($context['source'] ?? null) === 'manual';
            });

        $this->assertCount(1, $matchingAuditRows);
    }

    public function test_audit_logger_failure_does_not_block_redirect_or_notification()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $baseMetadata = [
            'status' => 'ready',
            'item_count' => 10,
            'last_attempt_at' => now()->toDateTimeString(),
            'last_successful_refresh_at' => now()->toDateTimeString(),
            'active_generation' => 'gen1',
            'last_error' => null,
        ];

        $queueServiceMock = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueServiceMock->shouldReceive('getMetadata')->andReturn($baseMetadata);
        $queueServiceMock->shouldReceive('getSnapshot')->andReturn([]);
        $this->instance(ZnunyQueueCacheReadService::class, $queueServiceMock);

        $agentServiceMock = Mockery::mock(ZnunyAgentCacheReadService::class);
        $agentServiceMock->shouldReceive('getMetadata')->andReturn($baseMetadata);
        $agentServiceMock->shouldReceive('getSnapshot')->andReturn([]);
        $this->instance(ZnunyAgentCacheReadService::class, $agentServiceMock);

        $lookupServiceMock = Mockery::mock(ZnunyLookupCacheReadService::class);
        $lookupServiceMock->shouldReceive('getMetadata')->andReturn($baseMetadata);
        $lookupServiceMock->shouldReceive('getSnapshot')->andReturn([]);
        $this->instance(ZnunyLookupCacheReadService::class, $lookupServiceMock);

        $customerUserServiceMock = Mockery::mock(ZnunyCustomerUserCacheReadService::class);
        $customerUserServiceMock->shouldReceive('getMetadata')->andReturn($baseMetadata);
        $customerUserServiceMock->shouldReceive('getSnapshot')->andReturn([]);
        $this->instance(ZnunyCustomerUserCacheReadService::class, $customerUserServiceMock);

        app()->setLocale('uk');

        $runnerMock = Mockery::mock(PrewarmRunnerService::class);
        $runnerMock->shouldReceive('run')->with('lookups', 'manual')->once()->andReturn(['status' => 'success']);
        $this->instance(PrewarmRunnerService::class, $runnerMock);

        Log::spy();

        // Force an exception during audit logging
        AuditLog::saving(function () {
            throw new \Exception('DB Offline Bearer fake-secret');
        });

        Livewire::actingAs($admin)
            ->test(ZnunyDataStatus::class)
            ->callInfolistAction('lookups_section', 'rewarm_lookups')
            ->assertNotified('Набір "Довідники" успішно оновлено')
            ->assertRedirect(ZnunyDataStatus::getUrl());

        Log::shouldHaveReceived('error')
            ->once()
            ->with('AuditLogger failed during manual refresh.', Mockery::on(function ($context) {
                return $context['dataset'] === 'lookups'
                    && $context['status'] === 'success'
                    && $context['source'] === 'manual'
                    && str_contains($context['message'], 'DB Offline')
                    && ! str_contains($context['message'], 'fake-secret')
                    && str_contains($context['message'], '***');
            }));

        AuditLog::flushEventListeners(); // Clean up the event listener
    }

    public function test_ready_metadata_ignores_missing_snapshot()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $baseMetadata = [
            'item_count' => 10,
            'last_attempt_at' => now()->toDateTimeString(),
            'last_successful_refresh_at' => now()->toDateTimeString(),
            'active_generation' => 'gen1',
            'last_error' => null,
            'status' => 'ready', // Ready metadata!
        ];

        $queueServiceMock = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueServiceMock->shouldReceive('getMetadata')->andReturn($baseMetadata);
        $queueServiceMock->shouldReceive('getSnapshot')->never();
        $this->instance(ZnunyQueueCacheReadService::class, $queueServiceMock);

        $agentServiceMock = Mockery::mock(ZnunyAgentCacheReadService::class);
        $agentServiceMock->shouldReceive('getMetadata')->andReturn($baseMetadata);
        $agentServiceMock->shouldReceive('getSnapshot')->never();
        $this->instance(ZnunyAgentCacheReadService::class, $agentServiceMock);

        $lookupServiceMock = Mockery::mock(ZnunyLookupCacheReadService::class);
        $lookupServiceMock->shouldReceive('getMetadata')->andReturn($baseMetadata);
        $lookupServiceMock->shouldReceive('getSnapshot')->never();
        $this->instance(ZnunyLookupCacheReadService::class, $lookupServiceMock);

        $customerUserServiceMock = Mockery::mock(ZnunyCustomerUserCacheReadService::class);
        $customerUserServiceMock->shouldReceive('getMetadata')->andReturn($baseMetadata);
        $customerUserServiceMock->shouldReceive('getSnapshot')->never();
        $this->instance(ZnunyCustomerUserCacheReadService::class, $customerUserServiceMock);

        app()->setLocale('uk');

        Livewire::actingAs($admin)
            ->test(ZnunyDataStatus::class)
            ->assertDontSee('Застарілий') // not stale
            ->assertSee('Готовий');       // all 4 are ready
    }

    public function test_stale_metadata_renders_stale_status()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $baseMetadata = [
            'item_count' => 10,
            'last_attempt_at' => now()->toDateTimeString(),
            'last_successful_refresh_at' => now()->toDateTimeString(),
            'active_generation' => 'gen1',
            'last_error' => null,
            'status' => 'ready', // base ready
        ];

        $staleMetadata = array_merge($baseMetadata, ['status' => 'stale']);

        $queueServiceMock = Mockery::mock(ZnunyQueueCacheReadService::class);
        $queueServiceMock->shouldReceive('getMetadata')->andReturn($staleMetadata); // Stale metadata
        $queueServiceMock->shouldReceive('getSnapshot')->never();
        $this->instance(ZnunyQueueCacheReadService::class, $queueServiceMock);

        $agentServiceMock = Mockery::mock(ZnunyAgentCacheReadService::class);
        $agentServiceMock->shouldReceive('getMetadata')->andReturn($baseMetadata);
        $agentServiceMock->shouldReceive('getSnapshot')->never();
        $this->instance(ZnunyAgentCacheReadService::class, $agentServiceMock);

        $lookupServiceMock = Mockery::mock(ZnunyLookupCacheReadService::class);
        $lookupServiceMock->shouldReceive('getMetadata')->andReturn($baseMetadata);
        $lookupServiceMock->shouldReceive('getSnapshot')->never();
        $this->instance(ZnunyLookupCacheReadService::class, $lookupServiceMock);

        $customerUserServiceMock = Mockery::mock(ZnunyCustomerUserCacheReadService::class);
        $customerUserServiceMock->shouldReceive('getMetadata')->andReturn($baseMetadata);
        $customerUserServiceMock->shouldReceive('getSnapshot')->never();
        $this->instance(ZnunyCustomerUserCacheReadService::class, $customerUserServiceMock);

        app()->setLocale('uk');

        Livewire::actingAs($admin)
            ->test(ZnunyDataStatus::class)
            ->assertSee('Застарілий') // queue is stale
            ->assertSee('Готовий');   // other 3 are ready
    }

    public function test_audit_log_labels_are_translated()
    {
        $this->assertEquals(
            'Znuny reference dataset',
            AuditLogResource::entityTypeLabel('znuny_prewarm_dataset')
        );
        $this->assertEquals(
            'Znuny reference data refreshed manually',
            AuditLogResource::actionLabel('znuny_prewarm_manual_refresh')
        );

        app()->setLocale('uk');

        $this->assertEquals(
            'Набір довідкових даних Znuny',
            AuditLogResource::entityTypeLabel('znuny_prewarm_dataset')
        );
        $this->assertEquals(
            'Довідкові дані Znuny оновлено вручну',
            AuditLogResource::actionLabel('znuny_prewarm_manual_refresh')
        );

        app()->setLocale('en'); // restore
    }

    private function mockReferenceDatasetsForInlineImageStatus(string $status = 'failed'): void
    {
        $metadata = [
            'item_count' => 10,
            'last_attempt_at' => now()->toDateTimeString(),
            'last_successful_refresh_at' => now()->toDateTimeString(),
            'active_generation' => 'gen1',
            'last_error' => $status === 'failed' ? 'Reference dataset failure' : null,
            'status' => $status,
        ];

        foreach ([
            ZnunyQueueCacheReadService::class,
            ZnunyAgentCacheReadService::class,
            ZnunyLookupCacheReadService::class,
            ZnunyCustomerUserCacheReadService::class,
        ] as $serviceClass) {
            $service = Mockery::mock($serviceClass);
            $service->shouldReceive('getMetadata')->andReturn($metadata);
            $this->instance($serviceClass, $service);
        }
    }

    private function configureInlineImageStatusSettings(bool $enabled, int $interval = 5, int $ttl = 60): void
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_inline_image_warmer_enabled'],
            ['value' => $enabled ? '1' : '0', 'type' => 'boolean']
        );
        Setting::updateOrCreate(
            ['key' => 'znuny_inline_image_warmer_interval_minutes'],
            ['value' => $interval, 'type' => 'integer']
        );
        Setting::updateOrCreate(
            ['key' => 'znuny_inline_image_cache_ttl_minutes'],
            ['value' => $ttl, 'type' => 'integer']
        );

        SettingsService::clearAllCaches();

        config()->set('znuny.inline_image_warmer_batch_size', 50);
        config()->set('znuny.inline_image_warmer_hot_percentage', 10);
    }

    private function mockInlineImageRedis(mixed $lastRunAt, mixed $tailOffset = 0, int $dbSize = 0): void
    {
        Redis::shouldReceive('get')
            ->with('znuny:inline_image_warmer:last_run_at')
            ->andReturn($lastRunAt);
        Redis::shouldReceive('get')
            ->with('znuny:inline_image_warmer:tail_offset')
            ->andReturn($tailOffset);

        $connection = Mockery::mock();
        $connection->shouldReceive('dbSize')->andReturn($dbSize);

        Redis::shouldReceive('connection')
            ->with('inline_images')
            ->andReturn($connection);
        Redis::makePartial();
    }

    public function test_inline_image_section_renders_disabled_state_fields_and_action(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mockReferenceDatasetsForInlineImageStatus();
        $this->configureInlineImageStatusSettings(false, 5, 60);
        $this->mockInlineImageRedis(null, 42, 1234);

        app()->setLocale('uk');

        Livewire::actingAs($admin)
            ->test(ZnunyDataStatus::class)
            ->assertSee('Кеш inline-зображень')
            ->assertSee('inline_images')
            ->assertSee('Вимкнено')
            ->assertSee('1234')
            ->assertSee('42')
            ->assertSee('5 хв.')
            ->assertSee('60 хв.')
            ->assertSee('50 / 10% hot')
            ->assertSee('Кількість закешованих inline-зображень.')
            ->assertSee('Позиція ротаційного обходу tail-частини тікетів.')
            ->assertSee('Максимальний пакет / частка найактивніших тікетів.')
            ->assertSee('Оновити зараз');

        app()->setLocale('en');
    }

    public function test_inline_image_status_is_pending_when_enabled_without_success_marker(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mockReferenceDatasetsForInlineImageStatus();
        $this->configureInlineImageStatusSettings(true);
        $this->mockInlineImageRedis(null, null, 0);

        app()->setLocale('uk');

        Livewire::actingAs($admin)
            ->test(ZnunyDataStatus::class)
            ->assertSee('Очікує запуску')
            ->assertSee('Ніколи');

        app()->setLocale('en');
    }

    public function test_inline_image_status_is_ready_for_recent_success_marker(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $now = Carbon::create(2026, 8, 23, 20, 0, 0, 'Europe/Kyiv');
        Carbon::setTestNow($now);

        try {
            $lastRunAt = $now->copy()->subMinute()->timestamp;

            $this->mockReferenceDatasetsForInlineImageStatus();
            $this->configureInlineImageStatusSettings(true, 5, 60);
            $this->mockInlineImageRedis($lastRunAt, 50, 13);

            app()->setLocale('uk');
            $expectedRelative = Carbon::createFromTimestamp($lastRunAt)->diffForHumans();

            Livewire::actingAs($admin)
                ->test(ZnunyDataStatus::class)
                ->assertSee('Готовий')
                ->assertSee($expectedRelative)
                ->assertDontSee('Очікує запуску')
                ->assertDontSee('Прострочено');
        } finally {
            Carbon::setTestNow();
            app()->setLocale('en');
        }
    }

    public function test_inline_image_status_is_stale_after_configured_interval(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $now = Carbon::create(2026, 8, 23, 20, 0, 0, 'Europe/Kyiv');
        Carbon::setTestNow($now);

        try {
            $this->mockReferenceDatasetsForInlineImageStatus();
            $this->configureInlineImageStatusSettings(true, 5, 60);
            $this->mockInlineImageRedis($now->copy()->subMinutes(6)->timestamp, 100, 34);

            app()->setLocale('uk');

            Livewire::actingAs($admin)
                ->test(ZnunyDataStatus::class)
                ->assertSee('Прострочено');
        } finally {
            Carbon::setTestNow();
            app()->setLocale('en');
        }
    }

    public function test_inline_image_malformed_markers_degrade_to_unknown_without_crashing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mockReferenceDatasetsForInlineImageStatus();
        $this->configureInlineImageStatusSettings(true);
        $this->mockInlineImageRedis('not-a-timestamp', '-5', 10);

        app()->setLocale('uk');

        Livewire::actingAs($admin)
            ->test(ZnunyDataStatus::class)
            ->assertSee('Невідомий')
            ->assertSee('Невідомо');

        app()->setLocale('en');
    }

    public function test_inline_image_operational_redis_failure_degrades_to_unknown_without_crashing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mockReferenceDatasetsForInlineImageStatus();
        $this->configureInlineImageStatusSettings(true);

        Redis::shouldReceive('get')
            ->andThrow(new \RuntimeException('Redis unavailable'));

        $connection = Mockery::mock();
        $connection->shouldReceive('dbSize')->andReturn(10);
        Redis::shouldReceive('connection')
            ->with('inline_images')
            ->andReturn($connection);
        Redis::makePartial();

        app()->setLocale('uk');

        Livewire::actingAs($admin)
            ->test(ZnunyDataStatus::class)
            ->assertSee('Невідомий')
            ->assertSee('Невідомо');

        app()->setLocale('en');
    }

    public function test_inline_image_cache_redis_failure_degrades_count_and_status_to_unknown(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mockReferenceDatasetsForInlineImageStatus();
        $this->configureInlineImageStatusSettings(true);

        Redis::shouldReceive('get')
            ->with('znuny:inline_image_warmer:last_run_at')
            ->andReturn(time());
        Redis::shouldReceive('get')
            ->with('znuny:inline_image_warmer:tail_offset')
            ->andReturn(50);
        Redis::shouldReceive('connection')
            ->with('inline_images')
            ->andThrow(new \RuntimeException('Inline Redis unavailable'));
        Redis::makePartial();

        app()->setLocale('uk');

        Livewire::actingAs($admin)
            ->test(ZnunyDataStatus::class)
            ->assertSee('Невідомий')
            ->assertSee('Невідомо');

        app()->setLocale('en');
    }

    public function test_inline_image_batch_and_hot_values_come_from_config(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mockReferenceDatasetsForInlineImageStatus();
        $this->configureInlineImageStatusSettings(false);
        $this->mockInlineImageRedis(null, 0, 0);

        Setting::updateOrCreate(
            ['key' => 'znuny_inline_image_warmer_batch_size'],
            ['value' => 999, 'type' => 'integer']
        );
        Setting::updateOrCreate(
            ['key' => 'znuny_inline_image_warmer_hot_percentage'],
            ['value' => 99, 'type' => 'integer']
        );
        SettingsService::clearAllCaches();

        config()->set('znuny.inline_image_warmer_batch_size', 17);
        config()->set('znuny.inline_image_warmer_hot_percentage', 23);

        Livewire::actingAs($admin)
            ->test(ZnunyDataStatus::class)
            ->assertSee('17 / 23% hot')
            ->assertDontSee('999 / 99% hot');
    }

    public function test_inline_image_action_does_not_invoke_warmer_when_disabled(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mockReferenceDatasetsForInlineImageStatus();
        $this->configureInlineImageStatusSettings(false);
        $this->mockInlineImageRedis(null, 0, 0);

        $warmer = Mockery::mock(ZnunyInlineImageWarmerService::class);
        $warmer->shouldReceive('warm')->never();
        $this->instance(ZnunyInlineImageWarmerService::class, $warmer);

        app()->setLocale('uk');

        Livewire::actingAs($admin)
            ->test(ZnunyDataStatus::class)
            ->callInfolistAction('inline_images_section', 'rewarm_inline_images')
            ->assertNotified('Прогрів "Кеш inline-зображень" вимкнено')
            ->assertRedirect(ZnunyDataStatus::getUrl());

        app()->setLocale('en');
    }

    public function test_inline_image_action_invokes_existing_warmer_once_and_reports_success(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mockReferenceDatasetsForInlineImageStatus();
        $this->configureInlineImageStatusSettings(true);
        $this->mockInlineImageRedis(time(), 50, 13);

        $warmer = Mockery::mock(ZnunyInlineImageWarmerService::class);
        $warmer->shouldReceive('warm')->once()->andReturn([
            'status' => 'success',
            'errors' => 0,
        ]);
        $this->instance(ZnunyInlineImageWarmerService::class, $warmer);

        app()->setLocale('uk');

        Livewire::actingAs($admin)
            ->test(ZnunyDataStatus::class)
            ->callInfolistAction('inline_images_section', 'rewarm_inline_images')
            ->assertNotified('Набір "Кеш inline-зображень" успішно оновлено')
            ->assertRedirect(ZnunyDataStatus::getUrl());

        app()->setLocale('en');
    }

    public function test_inline_image_action_reports_partial_errors_as_warning(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mockReferenceDatasetsForInlineImageStatus();
        $this->configureInlineImageStatusSettings(true);
        $this->mockInlineImageRedis(time(), 50, 13);

        $warmer = Mockery::mock(ZnunyInlineImageWarmerService::class);
        $warmer->shouldReceive('warm')->once()->andReturn([
            'status' => 'success',
            'errors' => 2,
        ]);
        $this->instance(ZnunyInlineImageWarmerService::class, $warmer);

        app()->setLocale('uk');

        Livewire::actingAs($admin)
            ->test(ZnunyDataStatus::class)
            ->callInfolistAction('inline_images_section', 'rewarm_inline_images')
            ->assertNotified('Прогрів "Кеш inline-зображень" завершено з попередженнями')
            ->assertRedirect(ZnunyDataStatus::getUrl());

        app()->setLocale('en');
    }

    public function test_inline_image_action_reports_configuration_skip_without_raw_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mockReferenceDatasetsForInlineImageStatus();
        $this->configureInlineImageStatusSettings(true);
        $this->mockInlineImageRedis(time(), 50, 13);

        $warmer = Mockery::mock(ZnunyInlineImageWarmerService::class);
        $warmer->shouldReceive('warm')->once()->andReturn([
            'status' => 'no active state types configured',
            'errors' => 0,
        ]);
        $this->instance(ZnunyInlineImageWarmerService::class, $warmer);

        app()->setLocale('uk');

        Livewire::actingAs($admin)
            ->test(ZnunyDataStatus::class)
            ->callInfolistAction('inline_images_section', 'rewarm_inline_images')
            ->assertNotified('Прогрів "Кеш inline-зображень" не виконано')
            ->assertRedirect(ZnunyDataStatus::getUrl());

        app()->setLocale('en');
    }

    public function test_inline_image_action_handles_throwable_with_safe_failure_notification(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->mockReferenceDatasetsForInlineImageStatus();
        $this->configureInlineImageStatusSettings(true);
        $this->mockInlineImageRedis(time(), 50, 13);

        $warmer = Mockery::mock(ZnunyInlineImageWarmerService::class);
        $warmer->shouldReceive('warm')
            ->once()
            ->andThrow(new \RuntimeException('Znuny failed Authorization: Bearer fake-secret-token'));
        $this->instance(ZnunyInlineImageWarmerService::class, $warmer);

        Log::spy();
        app()->setLocale('uk');

        Livewire::actingAs($admin)
            ->test(ZnunyDataStatus::class)
            ->callInfolistAction('inline_images_section', 'rewarm_inline_images')
            ->assertNotified('Помилка оновлення "Кеш inline-зображень"')
            ->assertRedirect(ZnunyDataStatus::getUrl());

        Log::shouldHaveReceived('error')
            ->once()
            ->with('Inline image warmer manual refresh failed.', Mockery::on(function (array $context): bool {
                return ($context['dataset'] ?? null) === 'inline_images'
                    && ($context['source'] ?? null) === 'manual'
                    && ! str_contains((string) ($context['message'] ?? ''), 'fake-secret-token');
            }));

        app()->setLocale('en');
    }
}
