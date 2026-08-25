<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyStandaloneTicketCreationService;
use App\Services\Znuny\ZnunyTicketAdvancedDefaultsService;
use Mockery\MockInterface;
use Tests\TestCase;

class ZnunyStandaloneTicketCreationServiceTest extends TestCase
{
    public function test_creates_ticket_successfully_with_correct_payloads()
    {
        $this->mock(ZnunyTicketAdvancedDefaultsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getDefaults')->andReturn([
                'state' => 'new',
                'priority' => '3 normal',
                'lock' => 'unlock',
            ]);
        });

        $client = $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getCustomerUser')
                ->with('testuser')
                ->once()
                ->andReturn([
                    'found' => true,
                    'customer_id' => 'ExampleClients',
                ]);

            $mock->shouldReceive('validateTicketCreate')
                ->with([
                    'OwnerID' => 123,
                    'Queue' => 'Junk',
                    'CustomerUser' => 'testuser',
                    'CustomerID' => 'ExampleClients',
                    'State' => 'new',
                    'Lock' => 'unlock',
                    'Priority' => '3 normal',
                ])
                ->once()
                ->andReturn([
                    'valid' => true,
                    'errors' => [],
                    'warnings' => [],
                ]);

            $mock->shouldReceive('createTicket')
                ->with([
                    'Ticket' => [
                        'Title' => 'Test',
                        'Queue' => 'Junk',
                        'CustomerUser' => 'testuser',
                        'CustomerID' => 'ExampleClients',
                        'State' => 'new',
                        'Lock' => 'unlock',
                        'OwnerID' => 123,
                        'Priority' => '3 normal',
                    ],
                    'Article' => [
                        'Subject' => 'Test',
                        'Body' => 'Test Body',
                        'ContentType' => 'text/plain; charset=utf8',
                        'IsVisibleForCustomer' => 1,
                    ],
                ])
                ->once()
                ->andReturn([
                    'success' => true,
                    'ticket_id' => 999,
                    'ticket_number' => '20260101999',
                ]);
        });

        $service = new ZnunyStandaloneTicketCreationService($client);

        $result = $service->createTicket(
            ownerId: 123,
            queue: 'Junk',
            customerUser: 'testuser',
            title: 'Test',
            articleBody: 'Test Body',
            state: null,
            priority: null,
            lock: null
        );

        $this->assertTrue($result['success']);
        $this->assertEquals(999, $result['ticket_id']);
        $this->assertEquals('20260101999', $result['ticket_number']);
    }
}
