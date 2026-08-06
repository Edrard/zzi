<?php

namespace Tests\Feature\Filament\Pages;

use App\Filament\Pages\ZnunyDataStatus;
use App\Models\User;
use App\Services\SettingsService;
use Tests\TestCase;
use Livewire\Livewire;
use App\Services\Znuny\Cache\PrewarmRunnerService;
use App\Services\Znuny\Cache\ZnunyQueueCacheReadService;
use App\Services\Znuny\Cache\ZnunyAgentCacheReadService;
use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use App\Services\Znuny\Cache\ZnunyCustomerUserCacheReadService;
use Mockery;

class ZnunyDataStatusTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
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

        \App\Models\Setting::updateOrCreate(['key' => 'znuny_prewarm_queues_interval_minutes'], ['value' => 5, 'type' => 'integer']);
        \App\Models\Setting::updateOrCreate(['key' => 'znuny_prewarm_agents_interval_minutes'], ['value' => 5, 'type' => 'integer']);
        \App\Models\Setting::updateOrCreate(['key' => 'znuny_prewarm_lookups_interval_minutes'], ['value' => 60, 'type' => 'integer']);
        \App\Models\Setting::updateOrCreate(['key' => 'znuny_prewarm_customer_users_interval_minutes'], ['value' => 30, 'type' => 'integer']);
        \App\Services\SettingsService::clearAllCaches();

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

        $matchingAuditRows = \App\Models\AuditLog::query()
            ->where('action', 'znuny_prewarm_manual_refresh')
            ->where('entity_type', 'znuny_prewarm_dataset')
            ->where('user_id', $admin->id)
            ->get()
            ->filter(static function (\App\Models\AuditLog $auditLog): bool {
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

        \Illuminate\Support\Facades\Log::spy();

        // Force an exception during audit logging
        \App\Models\AuditLog::saving(function () {
            throw new \Exception('DB Offline Bearer fake-secret');
        });

        Livewire::actingAs($admin)
            ->test(ZnunyDataStatus::class)
            ->callInfolistAction('lookups_section', 'rewarm_lookups')
            ->assertNotified('Набір "Довідники" успішно оновлено')
            ->assertRedirect(ZnunyDataStatus::getUrl());

        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
            ->once()
            ->with('AuditLogger failed during manual refresh.', \Mockery::on(function ($context) {
                return $context['dataset'] === 'lookups'
                    && $context['status'] === 'success'
                    && $context['source'] === 'manual'
                    && str_contains($context['message'], 'DB Offline')
                    && !str_contains($context['message'], 'fake-secret')
                    && str_contains($context['message'], '***');
            }));

        \App\Models\AuditLog::flushEventListeners(); // Clean up the event listener
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
            \App\Filament\Resources\AuditLogs\AuditLogResource::entityTypeLabel('znuny_prewarm_dataset')
        );
        $this->assertEquals(
            'Znuny reference data refreshed manually',
            \App\Filament\Resources\AuditLogs\AuditLogResource::actionLabel('znuny_prewarm_manual_refresh')
        );

        app()->setLocale('uk');

        $this->assertEquals(
            'Набір довідкових даних Znuny',
            \App\Filament\Resources\AuditLogs\AuditLogResource::entityTypeLabel('znuny_prewarm_dataset')
        );
        $this->assertEquals(
            'Довідкові дані Znuny оновлено вручну',
            \App\Filament\Resources\AuditLogs\AuditLogResource::actionLabel('znuny_prewarm_manual_refresh')
        );

        app()->setLocale('en'); // restore
    }
}
