<?php

namespace Tests\Unit\Services\Znuny;

use App\Models\AuditLog;
use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyCustomerUserQuickCreateService;
use App\Services\Znuny\ZnunyTicketCacheReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ZnunyCustomerUserQuickCreateServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $auditBaselineId = 0;

    private ZnunyClient $client;

    private ZnunyLookupCacheReadService $lookupCache;

    private ZnunyTicketCacheReconciliationService $reconciliationService;

    private ZnunyCustomerUserQuickCreateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->createMock(ZnunyClient::class);
        $this->lookupCache = $this->createMock(ZnunyLookupCacheReadService::class);
        $this->reconciliationService = $this->createMock(ZnunyTicketCacheReconciliationService::class);

        $this->service = new ZnunyCustomerUserQuickCreateService(
            $this->client,
            $this->lookupCache,
            $this->reconciliationService
        );

        $this->auditBaselineId = (int) (AuditLog::query()->max('id') ?? 0);
    }

    public function test_fails_if_email_is_missing()
    {
        $result = $this->service->createCustomerUser('login', '', 'first', 'last', 'comp', 1);

        $this->assertFalse($result['success']);
        $this->assertEquals(__('zabbix_tickets.details_modal.customer_user_quick_create.validation.email_required'), $result['message']);
        $this->assertEquals(0, AuditLog::query()->where('id', '>', $this->auditBaselineId)->count());
    }

    public function test_fails_if_email_is_invalid()
    {
        $result = $this->service->createCustomerUser('login', 'invalid', 'first', 'last', 'comp', 1);

        $this->assertFalse($result['success']);
        $this->assertEquals(__('zabbix_tickets.details_modal.customer_user_quick_create.validation.invalid_email'), $result['message']);
        $this->assertEquals(0, AuditLog::query()->where('id', '>', $this->auditBaselineId)->count());
    }

    public function test_fails_if_company_not_in_directory()
    {
        $this->lookupCache->method('hasCustomerCompany')->with('invalid_comp')->willReturn(false);

        $result = $this->service->createCustomerUser('login', 'valid@example.com', 'first', 'last', 'invalid_comp', 1);

        $this->assertFalse($result['success']);
        $this->assertEquals(__('zabbix_tickets.details_modal.customer_user_quick_create.validation.company_unavailable'), $result['message']);
        $this->assertEquals(0, AuditLog::query()->where('id', '>', $this->auditBaselineId)->count());
    }

    public function test_existing_user_idempotent_success()
    {
        $this->lookupCache->method('hasCustomerCompany')->willReturn(true);

        $this->client->expects($this->once())
            ->method('getCustomerUser')
            ->with('user1')
            ->willReturn(['found' => true, 'customer_id' => 'comp1', 'login' => 'User1']);

        $this->client->expects($this->never())->method('createCustomerUser');

        $this->reconciliationService->expects($this->once())
            ->method('reconcileCustomerUser')
            ->with('user1', 'User1', 'comp1', 1);

        $result = $this->service->createCustomerUser('user1', 'email@example.com', 'first', 'last', 'comp2', 1);

        $this->assertTrue($result['success']);
        $this->assertEquals(__('zabbix_tickets.details_modal.customer_user_quick_create.notifications.already_exists'), $result['message']);

        $this->assertEquals(1, AuditLog::query()->where('id', '>', $this->auditBaselineId)->count());
        $log = AuditLog::query()->where('id', '>', $this->auditBaselineId)->orderBy('id')->first();
        $this->assertEquals('znuny.customer_user.create_failed', $log->action);
        $this->assertEquals('znuny_customer_user', $log->entity_type);
        $this->assertEquals('User1', $log->entity_id);
        $this->assertEquals('already_exists', $log->context['failure_stage']);
        $this->assertEquals('customer_user_already_exists', $log->context['failure_reason']);
    }

    public function test_create_success()
    {
        $this->lookupCache->method('hasCustomerCompany')->willReturn(true);

        $this->client->expects($this->once())
            ->method('getCustomerUser')
            ->with('user1')
            ->willReturn(['found' => false]);

        $this->client->expects($this->once())
            ->method('createCustomerUser')
            ->with([
                'Login' => 'user1',
                'Email' => 'email@example.com',
                'FirstName' => 'first',
                'LastName' => 'last',
                'CustomerID' => 'comp1',
            ])
            ->willReturn(['created' => true, 'customer_id' => 'comp1', 'login' => 'User1']);

        $this->reconciliationService->expects($this->once())
            ->method('reconcileCustomerUser')
            ->with('user1', 'User1', 'comp1', 1);

        $result = $this->service->createCustomerUser('user1', 'email@example.com', 'first', 'last', 'comp1', 1);

        $this->assertTrue($result['success']);

        $this->assertEquals(1, AuditLog::query()->where('id', '>', $this->auditBaselineId)->count());
        $log = AuditLog::query()->where('id', '>', $this->auditBaselineId)->orderBy('id')->first();
        $this->assertEquals('znuny.customer_user.created', $log->action);
        $this->assertEquals('znuny_customer_user', $log->entity_type);
        $this->assertEquals('User1', $log->entity_id);
        $this->assertEquals('email@example.com', $log->context['email']);
    }

    public function test_duplicate_race_retry_success()
    {
        $this->lookupCache->method('hasCustomerCompany')->willReturn(true);

        $this->client->expects($this->exactly(2))
            ->method('getCustomerUser')
            ->willReturnOnConsecutiveCalls(
                ['found' => false],
                ['found' => true, 'customer_id' => 'comp1', 'login' => 'User1']
            );

        $this->client->expects($this->once())
            ->method('createCustomerUser')
            ->willReturn(['created' => false, 'errors' => ['Duplicate Login']]);

        $this->reconciliationService->expects($this->once())
            ->method('reconcileCustomerUser')
            ->with('user1', 'User1', 'comp1', 1);

        $result = $this->service->createCustomerUser('user1', 'email@example.com', 'first', 'last', 'comp1', 1);

        $this->assertTrue($result['success']);

        $this->assertEquals(1, AuditLog::query()->where('id', '>', $this->auditBaselineId)->count());
        $log = AuditLog::query()->where('id', '>', $this->auditBaselineId)->orderBy('id')->first();
        $this->assertEquals('znuny.customer_user.create_failed', $log->action);
        $this->assertEquals('znuny_customer_user', $log->entity_type);
        $this->assertEquals('User1', $log->entity_id);
        $this->assertEquals('already_exists', $log->context['failure_stage']);
        $this->assertEquals('customer_user_already_exists', $log->context['failure_reason']);
    }

    public function test_create_failure_no_race()
    {
        $this->lookupCache->method('hasCustomerCompany')->willReturn(true);

        $this->client->expects($this->once())
            ->method('getCustomerUser')
            ->willReturn(['found' => false]);

        $this->client->expects($this->once())
            ->method('createCustomerUser')
            ->willReturn(['created' => false, 'errors' => ['Some API Error']]);

        $this->reconciliationService->expects($this->never())->method('reconcileCustomerUser');

        $result = $this->service->createCustomerUser('user1', 'email@example.com', 'first', 'last', 'comp1', 1);

        $this->assertFalse($result['success']);
        $this->assertEquals(__('zabbix_tickets.details_modal.customer_user_quick_create.errors.api_error', ['error' => 'Some API Error']), $result['message']);

        $this->assertEquals(1, AuditLog::query()->where('id', '>', $this->auditBaselineId)->count());
        $log = AuditLog::query()->where('id', '>', $this->auditBaselineId)->orderBy('id')->first();
        $this->assertEquals('znuny.customer_user.create_failed', $log->action);
        $this->assertEquals('create', $log->context['failure_stage']);
    }

    public function test_create_transport_failure()
    {
        $this->lookupCache->method('hasCustomerCompany')->willReturn(true);
        $this->client->expects($this->once())
            ->method('getCustomerUser')
            ->willReturn(['found' => false]);

        $this->client->expects($this->once())
            ->method('createCustomerUser')
            ->willThrowException(new \Exception('Connection timeout'));

        $result = $this->service->createCustomerUser('user1', 'email@example.com', 'first', 'last', 'comp1', 1);

        $this->assertFalse($result['success']);
        $this->assertEquals(__('zabbix_tickets.details_modal.customer_user_quick_create.errors.create_transport_failure'), $result['message']);

        $this->assertEquals(1, AuditLog::query()->where('id', '>', $this->auditBaselineId)->count());
        $log = AuditLog::query()->where('id', '>', $this->auditBaselineId)->orderBy('id')->first();
        $this->assertEquals('znuny.customer_user.create_failed', $log->action);
        $this->assertEquals('create', $log->context['failure_stage']);
    }

    public function test_lookup_transport_failure()
    {
        $this->lookupCache->method('hasCustomerCompany')->willReturn(true);
        $this->client->expects($this->once())
            ->method('getCustomerUser')
            ->willThrowException(new \Exception('Connection timeout'));

        $result = $this->service->createCustomerUser('user1', 'email@example.com', 'first', 'last', 'comp1', 1);

        $this->assertFalse($result['success']);
        $this->assertEquals(__('zabbix_tickets.details_modal.customer_user_quick_create.errors.lookup_transport_failure'), $result['message']);

        $this->assertEquals(1, AuditLog::query()->where('id', '>', $this->auditBaselineId)->count());
        $log = AuditLog::query()->where('id', '>', $this->auditBaselineId)->orderBy('id')->first();
        $this->assertEquals('znuny.customer_user.create_failed', $log->action);
        $this->assertEquals('lookup', $log->context['failure_stage']);
    }

    public function test_audit_failure_does_not_break_successful_create()
    {
        $this->lookupCache->method('hasCustomerCompany')->willReturn(true);

        $this->client->expects($this->once())
            ->method('getCustomerUser')
            ->willReturn(['found' => false]);

        $this->client->expects($this->once())
            ->method('createCustomerUser')
            ->willReturn(['created' => true, 'customer_id' => 'comp1', 'login' => 'User1']);

        Log::shouldReceive('error')
            ->once()
            ->with('Failed to write Audit Log for Znuny Quick Create', \Mockery::hasKey('exception'));

        AuditLog::saving(function () {
            throw new \Exception('Simulated DB Failure');
        });

        $result = $this->service->createCustomerUser('user1', 'email@example.com', 'first', 'last', 'comp1', 1);

        $this->assertTrue($result['success']);
        // Verify no audit log was actually saved due to the exception
        $this->assertEquals(0, AuditLog::query()->where('id', '>', $this->auditBaselineId)->count());
    }
}
