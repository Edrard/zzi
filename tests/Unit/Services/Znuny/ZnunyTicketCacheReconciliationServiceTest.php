<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\ClosedTicketCacheService;
use App\Services\Znuny\ZnunyTicketCacheReconciliationService;
use App\Services\Znuny\ZnunyTicketCacheService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ZnunyTicketCacheReconciliationServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach ([
            'znuny:index:customer_user:user1',
            'znuny:closed_ticket:customer_user_index:user1',
            'znuny:index:customer_user:missing@example.com',
            'znuny:closed_ticket:customer_user_index:missing@example.com',
        ] as $key) {
            Redis::del($key);
        }

        parent::tearDown();
    }

    public function test_reconciles_only_matching_indexed_active_and_closed_tickets(): void
    {
        Redis::zadd('znuny:index:customer_user:user1', 1, '100');
        Redis::zadd('znuny:index:customer_user:user1', 2, '101');
        Redis::zadd('znuny:closed_ticket:customer_user_index:user1', 1, '200');

        $active = $this->createMock(ZnunyTicketCacheService::class);
        $closed = $this->createMock(ClosedTicketCacheService::class);

        $active->expects($this->exactly(2))
            ->method('getTicket')
            ->willReturnMap([
                ['100', ['TicketID' => 100, 'CustomerUserID' => 'USER1', 'CustomerID' => 'old-comp']],
                ['101', ['TicketID' => 101, 'CustomerUserID' => 'other-user', 'CustomerID' => 'old-comp']],
            ]);

        $active->expects($this->once())
            ->method('mirrorConfirmedTicketIdentity')
            ->with('100', 'NewUser', 'new-comp');

        $closed->expects($this->once())
            ->method('getTicket')
            ->with('200')
            ->willReturn([
                'TicketID' => 200,
                'CustomerUserID' => 'user1',
                'CustomerID' => 'old-comp',
            ]);

        $closed->expects($this->once())
            ->method('mirrorConfirmedTicketIdentity')
            ->with('200', 'NewUser', 'new-comp');

        $service = new ZnunyTicketCacheReconciliationService($active, $closed);

        $service->reconcileKnownCustomerUserIdentity(
            ' User1 ',
            'NewUser',
            'new-comp',
        );
    }

    public function test_current_ticket_fallback_reconciles_active_ticket_when_not_indexed(): void
    {
        $active = $this->createMock(ZnunyTicketCacheService::class);
        $closed = $this->createMock(ClosedTicketCacheService::class);

        $active->expects($this->once())
            ->method('getTicket')
            ->with('999')
            ->willReturn([
                'TicketID' => 999,
                'CustomerUserID' => 'user1',
                'CustomerID' => 'old-comp',
            ]);

        $active->expects($this->once())
            ->method('mirrorConfirmedTicketIdentity')
            ->with('999', 'renamed-user', 'new-comp');

        $closed->expects($this->once())
            ->method('getTicket')
            ->with('999')
            ->willReturn(null);

        $closed->expects($this->never())
            ->method('mirrorConfirmedTicketIdentity');

        $service = new ZnunyTicketCacheReconciliationService($active, $closed);

        $service->reconcileKnownCustomerUserIdentity(
            'user1',
            'renamed-user',
            'new-comp',
            999,
        );
    }

    public function test_current_ticket_fallback_reconciles_closed_ticket_when_not_indexed(): void
    {
        $active = $this->createMock(ZnunyTicketCacheService::class);
        $closed = $this->createMock(ClosedTicketCacheService::class);

        $active->expects($this->once())
            ->method('getTicket')
            ->with('1000')
            ->willReturn(null);

        $active->expects($this->never())
            ->method('mirrorConfirmedTicketIdentity');

        $closed->expects($this->once())
            ->method('getTicket')
            ->with('1000')
            ->willReturn([
                'TicketID' => 1000,
                'CustomerUserID' => 'user1',
                'CustomerID' => 'old-comp',
            ]);

        $closed->expects($this->once())
            ->method('mirrorConfirmedTicketIdentity')
            ->with('1000', 'renamed-user', 'new-comp');

        $service = new ZnunyTicketCacheReconciliationService($active, $closed);

        $service->reconcileKnownCustomerUserIdentity(
            'user1',
            'renamed-user',
            'new-comp',
            1000,
        );
    }

    public function test_uncached_current_ticket_is_not_created_or_mirrored(): void
    {
        $active = $this->createMock(ZnunyTicketCacheService::class);
        $closed = $this->createMock(ClosedTicketCacheService::class);

        $active->expects($this->once())
            ->method('getTicket')
            ->with('1001')
            ->willReturn(null);

        $closed->expects($this->once())
            ->method('getTicket')
            ->with('1001')
            ->willReturn(null);

        $active->expects($this->never())
            ->method('mirrorConfirmedTicketIdentity');

        $closed->expects($this->never())
            ->method('mirrorConfirmedTicketIdentity');

        $service = new ZnunyTicketCacheReconciliationService($active, $closed);

        $service->reconcileKnownCustomerUserIdentity(
            'missing@example.com',
            'returned@example.com',
            'new-comp',
            1001,
        );
    }
}
