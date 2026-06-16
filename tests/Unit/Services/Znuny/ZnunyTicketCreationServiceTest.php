<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyTicketCreationService;
use Tests\TestCase;

class ZnunyTicketCreationServiceTest extends TestCase
{
    public function test_validate_ticket_payload_success()
    {
        $clientMock = $this->mock(ZnunyClient::class);

        $clientMock->shouldReceive('validateTicketCreate')
            ->once()
            ->with([
                'OwnerID' => 10,
                'Queue' => 'TestQueue',
                'CustomerUser' => 'testuser',
                'State' => 'new',
                'Lock' => 'lock',
            ])
            ->andReturn([
                'valid' => 1,
                'errors' => [],
                'warnings' => ['A warning'],
            ]);

        $service = new ZnunyTicketCreationService($clientMock);

        $result = $service->validateTicketPayload(10, 'TestQueue', 'testuser');

        $this->assertTrue($result['valid']);
        $this->assertEquals([], $result['errors']);
        $this->assertEquals(['A warning'], $result['warnings']);
    }

    public function test_validate_ticket_payload_failure()
    {
        $clientMock = $this->mock(ZnunyClient::class);

        $clientMock->shouldReceive('validateTicketCreate')
            ->once()
            ->andReturn([
                'valid' => 0,
                'errors' => ['Missing data'],
                'warnings' => [],
            ]);

        $service = new ZnunyTicketCreationService($clientMock);

        $result = $service->validateTicketPayload('10', 'TestQueue', 'testuser');

        $this->assertFalse($result['valid']);
        $this->assertEquals(['Missing data'], $result['errors']);
        $this->assertEquals([], $result['warnings']);
    }

    public function test_validate_ticket_payload_exception()
    {
        $clientMock = $this->mock(ZnunyClient::class);

        $clientMock->shouldReceive('validateTicketCreate')
            ->once()
            ->andThrow(new \Exception('API timeout'));

        $service = new ZnunyTicketCreationService($clientMock);

        $result = $service->validateTicketPayload(10, 'TestQueue', 'testuser');

        $this->assertFalse($result['valid']);
        $this->assertEquals(['API timeout'], $result['errors']);
        $this->assertEquals([], $result['warnings']);
    }
}
