<?php

namespace Tests\Feature\Services\Znuny;

use App\Exceptions\ZabbixTicketAlreadyLinkedException;
use App\Models\AuditLog;
use App\Models\ZabbixTicket;
use App\Services\Znuny\ZabbixTicketLinkService;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ZabbixTicketLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    private ZabbixTicketLinkService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ZabbixTicketLinkService::class);
    }

    private function validData(): array
    {
        return [
            'zabbix_event_id' => 'evt_123',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'CPU high',
            'znuny_ticket_id' => 999,
            'znuny_ticket_number' => 'TN123456',
        ];
    }

    public function test_it_creates_successful_relation_and_audit_log()
    {
        $data = $this->validData();

        $ticket = $this->service->create($data);

        $this->assertInstanceOf(ZabbixTicket::class, $ticket);
        $this->assertDatabaseHas('zabbix_tickets', [
            'zabbix_event_id' => 'evt_123',
            'zabbix_host_name' => 'Host 1',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'zabbix_ticket.link_created',
            'entity_type' => 'zabbix_ticket',
            'entity_id' => $ticket->id,
        ]);
    }

    public function test_it_throws_on_explicit_duplicate_pre_check()
    {
        $data = $this->validData();
        $this->service->create($data);

        $this->expectException(ZabbixTicketAlreadyLinkedException::class);
        $this->service->create($data);
    }

    public function test_is_duplicate_event_id_exception_logic()
    {
        $reflection = new \ReflectionClass(ZabbixTicketLinkService::class);
        $method = $reflection->getMethod('isDuplicateEventIdException');

        // Test 1: Correct driver code and message
        $pdoException = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'evt_123\' for key \'zabbix_event_id\'');
        $pdoException->errorInfo = ['23000', 1062, 'Duplicate entry'];
        $queryException = new QueryException('', '', [], $pdoException);
        $this->assertTrue($method->invoke($this->service, $queryException));

        // Test 2: Unrelated driver code
        $pdoException = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1048 Column cannot be null');
        $pdoException->errorInfo = ['23000', 1048, 'Column cannot be null'];
        $queryException = new QueryException('', '', [], $pdoException);
        $this->assertFalse($method->invoke($this->service, $queryException));

        // Test 3: Correct driver code but wrong message (unrelated unique key)
        $pdoException = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'123\' for key \'znuny_ticket_id\'');
        $pdoException->errorInfo = ['23000', 1062, 'Duplicate entry'];
        $queryException = new QueryException('', '', [], $pdoException);
        $this->assertFalse($method->invoke($this->service, $queryException));
    }

    public function test_transaction_rollback_when_audit_log_fails()
    {
        $data = $this->validData();

        // Let's cause AuditLogger to fail. Since AuditLogger uses standard Eloquent model AuditLog::create,
        // we can hook into saving event of AuditLog to throw an exception.
        AuditLog::saving(function () {
            throw new Exception('Audit Log failed intentionally');
        });

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Audit Log failed intentionally');

        try {
            $this->service->create($data);
        } catch (Exception $e) {
            $this->assertDatabaseEmpty('zabbix_tickets');
            throw $e;
        }
    }
}
