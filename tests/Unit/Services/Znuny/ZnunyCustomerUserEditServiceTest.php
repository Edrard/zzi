<?php

namespace Tests\Unit\Services\Znuny;

use App\Models\AuditLog;
use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyCustomerUserEditService;
use App\Services\Znuny\ZnunyTicketCacheReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ZnunyCustomerUserEditServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $auditBaselineId = 0;

    private ZnunyClient $client;

    private ZnunyLookupCacheReadService $lookupCache;

    private ZnunyTicketCacheReconciliationService $reconciliationService;

    private ZnunyCustomerUserEditService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->createMock(ZnunyClient::class);
        $this->lookupCache = $this->createMock(ZnunyLookupCacheReadService::class);
        $this->reconciliationService = $this->createMock(ZnunyTicketCacheReconciliationService::class);

        $this->service = new ZnunyCustomerUserEditService(
            $this->client,
            $this->lookupCache,
            $this->reconciliationService,
        );

        $this->auditBaselineId = (int) (AuditLog::query()->max('id') ?? 0);
    }

    public function test_authoritative_prefill_success_does_not_write_audit(): void
    {
        $this->client->expects($this->once())
            ->method('getCustomerUser')
            ->with('user1')
            ->willReturn($this->authoritativeUser());

        $result = $this->service->getCustomerUser('user1');

        $this->assertTrue($result['success']);
        $this->assertSame([
            'login' => 'User1',
            'email' => 'old@example.com',
            'first_name' => 'Old',
            'last_name' => 'Name',
            'customer_id' => 'comp1',
        ], $result['data']);
        $this->assertSame(0, $this->newAuditRows()->count());
    }

    public function test_prefill_not_found_does_not_write_audit(): void
    {
        $this->client->expects($this->once())
            ->method('getCustomerUser')
            ->willReturn(['found' => false]);

        $result = $this->service->getCustomerUser('user1');

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->newAuditRows()->count());
    }

    public function test_no_change_submit_performs_no_patch_and_writes_one_success_audit(): void
    {
        $this->expectAuthoritativeLookup();
        $this->lookupCache->expects($this->once())
            ->method('hasCustomerCompany')
            ->with('comp1')
            ->willReturn(true);
        $this->client->expects($this->never())->method('updateCustomerUser');
        $this->reconciliationService->expects($this->never())->method('reconcileCustomerUser');

        $result = $this->service->updateCustomerUser('user1', $this->submittedValues(), 59360);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['no_changes']);

        $log = $this->singleNewAudit();
        $this->assertSame('znuny.customer_user.updated', $log->action);
        $this->assertSame('znuny_customer_user', $log->entity_type);
        $this->assertSame('User1', $log->entity_id);
        $this->assertSame('ticket_customer_user_edit', $log->context['source']);
        $this->assertSame([], $log->context['changed_fields']);
        $this->assertSame([], $log->context['old']);
        $this->assertSame([], $log->context['new']);
        $this->assertTrue($log->context['no_changes']);
    }

    public function test_sensitive_unknown_fields_are_ignored_when_login_is_unchanged(): void
    {
        $this->expectAuthoritativeLookup();
        $this->lookupCache->method('hasCustomerCompany')->willReturn(true);
        $this->client->expects($this->never())->method('updateCustomerUser');

        $result = $this->service->updateCustomerUser('user1', [
            ...$this->submittedValues(),
            'Login' => 'User1',
            'Password' => 'secret-value',
            'token' => 'secret-token',
            'ArbitraryExtra' => 'ignored',
        ], 59360);

        $this->assertTrue($result['success']);

        $encoded = json_encode($this->singleNewAudit()->context);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('secret-value', $encoded);
        $this->assertStringNotContainsString('secret-token', $encoded);
        $this->assertStringNotContainsString('ArbitraryExtra', $encoded);
    }

    public function test_login_rename_sends_new_login_reconciles_old_to_new_and_audits_delta(): void
    {
        $this->expectAuthoritativeLookup();
        $this->lookupCache->method('hasCustomerCompany')->with('comp1')->willReturn(true);

        $this->client->expects($this->once())
            ->method('updateCustomerUser')
            ->with('user1', ['Login' => 'renamed@example.com'])
            ->willReturn([
                'updated' => true,
                'login' => 'renamed@example.com',
                'customer_id' => 'comp1',
                'errors' => [],
            ]);

        $this->reconciliationService->expects($this->once())
            ->method('reconcileCustomerUser')
            ->with('user1', 'renamed@example.com', 'comp1', 59360);

        $result = $this->service->updateCustomerUser('user1', [
            ...$this->submittedValues(),
            'Login' => 'renamed@example.com',
        ], 59360);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['no_changes']);

        $log = $this->singleNewAudit();
        $this->assertSame('znuny.customer_user.updated', $log->action);
        $this->assertSame('renamed@example.com', $log->entity_id);
        $this->assertSame('renamed@example.com', $log->context['customer_user_login']);
        $this->assertSame(['Login'], $log->context['changed_fields']);
        $this->assertSame(['Login' => 'User1'], $log->context['old']);
        $this->assertSame(['Login' => 'renamed@example.com'], $log->context['new']);
    }

    public function test_single_field_update_sends_only_delta_and_audits_old_new(): void
    {
        $this->expectAuthoritativeLookup();
        $this->lookupCache->method('hasCustomerCompany')->with('comp1')->willReturn(true);

        $this->client->expects($this->once())
            ->method('updateCustomerUser')
            ->with('user1', ['FirstName' => 'New'])
            ->willReturn([
                'updated' => true,
                'login' => 'User1',
                'customer_id' => 'comp1',
                'errors' => [],
            ]);

        $this->reconciliationService->expects($this->once())
            ->method('reconcileCustomerUser')
            ->with('user1', 'User1', 'comp1', 59360);

        $result = $this->service->updateCustomerUser('user1', [
            ...$this->submittedValues(),
            'FirstName' => 'New',
        ], 59360);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['no_changes']);

        $log = $this->singleNewAudit();
        $this->assertSame(['FirstName'], $log->context['changed_fields']);
        $this->assertSame(['FirstName' => 'Old'], $log->context['old']);
        $this->assertSame(['FirstName' => 'New'], $log->context['new']);
    }

    public function test_multiple_field_update_audits_only_actual_changes(): void
    {
        $this->expectAuthoritativeLookup();
        $this->lookupCache->method('hasCustomerCompany')->with('comp2')->willReturn(true);

        $this->client->expects($this->once())
            ->method('updateCustomerUser')
            ->with('user1', [
                'Email' => 'new@example.com',
                'CustomerID' => 'comp2',
            ])
            ->willReturn([
                'updated' => true,
                'login' => 'User1',
                'customer_id' => 'comp2',
                'errors' => [],
            ]);

        $this->reconciliationService->expects($this->once())
            ->method('reconcileCustomerUser')
            ->with('user1', 'User1', 'comp2', 59360);

        $result = $this->service->updateCustomerUser('user1', [
            ...$this->submittedValues(),
            'Email' => 'new@example.com',
            'CustomerID' => 'comp2',
        ], 59360);

        $this->assertTrue($result['success']);

        $log = $this->singleNewAudit();
        $this->assertSame(['Email', 'CustomerID'], $log->context['changed_fields']);
        $this->assertSame([
            'Email' => 'old@example.com',
            'CustomerID' => 'comp1',
        ], $log->context['old']);
        $this->assertSame([
            'Email' => 'new@example.com',
            'CustomerID' => 'comp2',
        ], $log->context['new']);
    }

    public function test_invalid_email_writes_one_validation_failure_audit(): void
    {
        $this->expectAuthoritativeLookup();
        $this->client->expects($this->never())->method('updateCustomerUser');

        $result = $this->service->updateCustomerUser('user1', [
            ...$this->submittedValues(),
            'Email' => 'invalid',
        ], 59360);

        $this->assertFalse($result['success']);

        $log = $this->singleNewAudit();
        $this->assertSame('znuny.customer_user.update_failed', $log->action);
        $this->assertSame('validation', $log->context['failure_stage']);
        $this->assertSame('invalid_email', $log->context['failure_reason']);
    }

    public function test_unavailable_company_writes_one_validation_failure_audit(): void
    {
        $this->expectAuthoritativeLookup();
        $this->lookupCache->expects($this->once())
            ->method('hasCustomerCompany')
            ->with('missing-company')
            ->willReturn(false);
        $this->client->expects($this->never())->method('updateCustomerUser');

        $result = $this->service->updateCustomerUser('user1', [
            ...$this->submittedValues(),
            'CustomerID' => 'missing-company',
        ], 59360);

        $this->assertFalse($result['success']);

        $log = $this->singleNewAudit();
        $this->assertSame('validation', $log->context['failure_stage']);
        $this->assertSame('company_unavailable', $log->context['failure_reason']);
    }

    public function test_submit_lookup_transport_failure_writes_one_failure_audit(): void
    {
        $this->client->expects($this->once())
            ->method('getCustomerUser')
            ->willThrowException(new \RuntimeException('transport secret'));

        $result = $this->service->updateCustomerUser('user1', $this->submittedValues(), 59360);

        $this->assertFalse($result['success']);

        $log = $this->singleNewAudit();
        $this->assertSame('lookup', $log->context['failure_stage']);
        $this->assertSame('lookup_transport_failure', $log->context['failure_reason']);
        $this->assertStringNotContainsString('transport secret', json_encode($log->context));
    }

    public function test_update_api_failure_writes_one_failure_audit(): void
    {
        $this->expectAuthoritativeLookup();
        $this->lookupCache->method('hasCustomerCompany')->willReturn(true);

        $this->client->expects($this->once())
            ->method('updateCustomerUser')
            ->willReturn([
                'updated' => false,
                'errors' => ['Rejected'],
            ]);

        $this->reconciliationService->expects($this->never())->method('reconcileCustomerUser');

        $result = $this->service->updateCustomerUser('user1', [
            ...$this->submittedValues(),
            'FirstName' => 'New',
        ], 59360);

        $this->assertFalse($result['success']);

        $log = $this->singleNewAudit();
        $this->assertSame('update', $log->context['failure_stage']);
        $this->assertSame('api_rejected', $log->context['failure_reason']);
        $this->assertArrayNotHasKey('raw_response', $log->context);
    }

    public function test_response_identity_mismatch_writes_one_failure_audit(): void
    {
        $this->expectAuthoritativeLookup();
        $this->lookupCache->method('hasCustomerCompany')->willReturn(true);

        $this->client->expects($this->once())
            ->method('updateCustomerUser')
            ->willReturn([
                'updated' => true,
                'login' => 'different-user',
                'customer_id' => 'comp1',
                'errors' => [],
            ]);

        $this->reconciliationService->expects($this->never())->method('reconcileCustomerUser');

        $result = $this->service->updateCustomerUser('user1', [
            ...$this->submittedValues(),
            'FirstName' => 'New',
        ], 59360);

        $this->assertFalse($result['success']);

        $log = $this->singleNewAudit();
        $this->assertSame('response_validation', $log->context['failure_stage']);
        $this->assertSame('updated_identity_invalid', $log->context['failure_reason']);
    }

    public function test_reconciliation_failure_writes_one_failure_audit(): void
    {
        $this->expectAuthoritativeLookup();
        $this->lookupCache->method('hasCustomerCompany')->willReturn(true);

        $this->client->expects($this->once())
            ->method('updateCustomerUser')
            ->willReturn([
                'updated' => true,
                'login' => 'User1',
                'customer_id' => 'comp1',
                'errors' => [],
            ]);

        $this->reconciliationService->expects($this->once())
            ->method('reconcileCustomerUser')
            ->willThrowException(new \RuntimeException('cache failure'));

        $result = $this->service->updateCustomerUser('user1', [
            ...$this->submittedValues(),
            'FirstName' => 'New',
        ], 59360);

        $this->assertFalse($result['success']);

        $log = $this->singleNewAudit();
        $this->assertSame('reconciliation', $log->context['failure_stage']);
        $this->assertSame('cache_reconciliation_failed', $log->context['failure_reason']);
        $this->assertStringNotContainsString('cache failure', json_encode($log->context));
    }

    private function authoritativeUser(array $overrides = []): array
    {
        return array_merge([
            'found' => true,
            'login' => 'User1',
            'email' => 'old@example.com',
            'first_name' => 'Old',
            'last_name' => 'Name',
            'customer_id' => 'comp1',
        ], $overrides);
    }

    private function submittedValues(): array
    {
        return [
            'Login' => 'User1',
            'Email' => 'old@example.com',
            'FirstName' => 'Old',
            'LastName' => 'Name',
            'CustomerID' => 'comp1',
        ];
    }

    private function expectAuthoritativeLookup(): void
    {
        $this->client->expects($this->once())
            ->method('getCustomerUser')
            ->with('user1')
            ->willReturn($this->authoritativeUser());
    }

    private function newAuditRows()
    {
        return AuditLog::query()->where('id', '>', $this->auditBaselineId);
    }

    private function singleNewAudit(): AuditLog
    {
        $rows = $this->newAuditRows()->orderBy('id')->get();
        $this->assertCount(1, $rows);

        return $rows->first();
    }
}
