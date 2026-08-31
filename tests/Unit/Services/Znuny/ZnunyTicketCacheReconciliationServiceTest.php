<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\ClosedTicketCacheService;
use App\Services\Znuny\ZnunyTicketCacheReconciliationService;
use App\Services\Znuny\ZnunyTicketCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class ZnunyTicketCacheReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ZnunyTicketCacheService|MockObject $activeCache;

    private ClosedTicketCacheService|MockObject $closedCache;

    private ZnunyTicketCacheReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activeCache = $this->createMock(ZnunyTicketCacheService::class);
        $this->closedCache = $this->createMock(ClosedTicketCacheService::class);

        $this->service = new ZnunyTicketCacheReconciliationService(
            $this->activeCache,
            $this->closedCache
        );
    }

    protected function tearDown(): void
    {
        Redis::del('znuny:index:customer_user:user1');
        Redis::del('znuny:closed_ticket:customer_user_index:user1');
        parent::tearDown();
    }

    public function test_reconciles_active_and_closed_tickets()
    {
        Redis::zadd('znuny:index:customer_user:user1', 1, '100');
        Redis::zadd('znuny:index:customer_user:user1', 1, '101');
        Redis::zadd('znuny:closed_ticket:customer_user_index:user1', 1, '200');

        // Setup mock returns
        $this->activeCache->method('getTicket')->willReturnMap([
            ['100', ['TicketID' => 100, 'CustomerUserID' => 'user1', 'CustomerID' => 'old_comp']],
            ['101', ['TicketID' => 101, 'CustomerUserID' => 'other_user', 'CustomerID' => 'old_comp']],
        ]);

        $this->closedCache->method('getTicket')->willReturnMap([
            ['200', ['TicketID' => 200, 'CustomerUserID' => 'user1', 'CustomerID' => 'old_comp']],
        ]);

        // Assertions
        $this->activeCache->expects($this->once())
            ->method('updateTicketIdentity')
            ->with('100', 'auth_user', 'new_comp');

        $this->closedCache->expects($this->once())
            ->method('updateTicketIdentity')
            ->with('200', 'auth_user', 'new_comp');

        $this->service->reconcileCustomerUser('user1', 'auth_user', 'new_comp');
    }

    public function test_reconciles_current_ticket_even_if_not_indexed()
    {
        $this->activeCache->method('getTicket')->willReturnMap([
            ['999', ['TicketID' => 999, 'CustomerUserID' => 'user1', 'CustomerID' => 'old_comp']],
        ]);

        $this->activeCache->expects($this->once())
            ->method('updateTicketIdentity')
            ->with('999', 'auth_user', 'new_comp');

        $this->closedCache->expects($this->never())->method('updateTicketIdentity');

        $this->service->reconcileCustomerUser('user1', 'auth_user', 'new_comp', 999);
    }
}
