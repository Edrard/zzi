<?php

namespace Tests\Feature\Services\Znuny;

use App\Exceptions\ZabbixTicketAlreadyLinkedException;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\ZabbixTicket;
use App\Services\Znuny\ZabbixTicketLinkService;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_replace_terminal_ticket_link_updates_same_row_and_creates_audit_log()
    {
        $user = User::factory()->create();

        $initial = ZabbixTicket::create([
            'zabbix_event_id' => 'evt_123',
            'zabbix_host_name' => 'Host 1',
            'zabbix_problem_name' => 'CPU high',
            'znuny_ticket_id' => 111,
            'znuny_ticket_number' => 'TN111111',
            'znuny_queue_name' => 'OldQueue',
            'znuny_owner_id' => 5,
            'znuny_state_name' => 'closed successful',
            'znuny_ticket_state_type' => 'closed',
            'znuny_ticket_closed_at' => now()->subDay(),
            'manual_lifecycle_status' => 'closed',
            'manual_lifecycle_closed_at' => now()->subDay(),
            'manual_close_eligible_at' => now()->subDay(),
            'manual_reopened_at' => now()->subDays(2),
            'manual_lifecycle_last_checked_at' => now()->subHours(5),
            'zabbix_problem_resolved_at' => now()->subDay(),
            'znuny_ticket_snapshot_hash' => 'old_hash',
            'manual_flap_count' => 3,
        ]);

        $initial->created_at = now()->subWeeks(2);
        $initial->save();
        $oldCreatedAt = $initial->fresh()->created_at;

        $replacementData = [
            'zabbix_event_id' => 'evt_123',
            'znuny_ticket_id' => 222,
            'znuny_ticket_number' => 'TN222222',
            'znuny_queue_name' => 'NewQueue',
            'znuny_owner_id' => 10,
            'creation_source' => 'manual',
            'created_by' => $user->id,
        ];

        $updated = $this->service->replaceTerminalTicketLink($initial, $replacementData);

        $this->assertEquals($initial->id, $updated->id);
        $this->assertEquals(1, ZabbixTicket::where('zabbix_event_id', 'evt_123')->count());
        $this->assertEquals(1, ZabbixTicket::count());

        $updated->refresh();
        $this->assertEquals(222, $updated->znuny_ticket_id);
        $this->assertEquals('TN222222', $updated->znuny_ticket_number);
        $this->assertEquals('NewQueue', $updated->znuny_queue_name);
        $this->assertEquals(10, $updated->znuny_owner_id);
        $this->assertNull($updated->znuny_ticket_state_type);
        $this->assertNull($updated->znuny_state_name);
        $this->assertNull($updated->znuny_ticket_closed_at);
        $this->assertNull($updated->manual_lifecycle_status);
        $this->assertNull($updated->manual_lifecycle_closed_at);
        $this->assertNull($updated->manual_close_eligible_at);
        $this->assertNull($updated->manual_reopened_at);
        $this->assertNull($updated->manual_lifecycle_last_checked_at);
        $this->assertNull($updated->zabbix_problem_resolved_at);
        $this->assertNull($updated->znuny_ticket_snapshot_hash);
        $this->assertEquals(0, $updated->manual_flap_count);
        $this->assertTrue($updated->created_at->greaterThan($oldCreatedAt));
        $this->assertLessThan(5, abs(now()->diffInSeconds($updated->created_at)));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'zabbix_ticket.link_replaced',
            'entity_type' => 'zabbix_ticket',
            'entity_id' => $initial->id,
        ]);

        $auditLog = AuditLog::where('action', 'zabbix_ticket.link_replaced')->first();
        $this->assertNotNull($auditLog);
        $this->assertEquals('evt_123', $auditLog->context['zabbix_event_id']);
        $this->assertEquals(111, $auditLog->context['old_ticket_id']);
        $this->assertEquals('TN111111', $auditLog->context['old_ticket_number']);
        $this->assertEquals('closed', $auditLog->context['old_state_type']);
        $this->assertEquals('closed successful', $auditLog->context['old_state_name']);
        $this->assertEquals(222, $auditLog->context['new_ticket_id']);
        $this->assertEquals('TN222222', $auditLog->context['new_ticket_number']);
    }

    public function test_replace_terminal_ticket_link_works_for_merged_state()
    {
        $initial = ZabbixTicket::create([
            'zabbix_event_id' => 'evt_456',
            'zabbix_host_name' => 'Host 2',
            'zabbix_problem_name' => 'Disk full',
            'znuny_ticket_id' => 333,
            'znuny_ticket_number' => 'TN333333',
            'znuny_ticket_state_type' => 'merged',
        ]);

        $updated = $this->service->replaceTerminalTicketLink($initial, [
            'zabbix_event_id' => 'evt_456',
            'znuny_ticket_id' => 444,
            'znuny_ticket_number' => 'TN444444',
        ]);

        $this->assertEquals($initial->id, $updated->id);
        $this->assertEquals(1, ZabbixTicket::where('zabbix_event_id', 'evt_456')->count());
        $this->assertEquals(444, $updated->znuny_ticket_id);
    }

    public function test_replace_terminal_ticket_link_throws_when_not_terminal()
    {
        $initial = ZabbixTicket::create([
            'zabbix_event_id' => 'evt_789',
            'zabbix_host_name' => 'Host 3',
            'zabbix_problem_name' => 'Network down',
            'znuny_ticket_id' => 555,
            'znuny_ticket_number' => 'TN555555',
            'znuny_ticket_state_type' => 'open',
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is not in a terminal state');

        $this->service->replaceTerminalTicketLink($initial, [
            'zabbix_event_id' => 'evt_789',
            'znuny_ticket_id' => 666,
            'znuny_ticket_number' => 'TN666666',
        ]);
    }

    public function test_replace_terminal_ticket_link_throws_when_event_id_mismatched()
    {
        $initial = ZabbixTicket::create([
            'zabbix_event_id' => 'evt_abc',
            'zabbix_host_name' => 'Host 4',
            'zabbix_problem_name' => 'Mem leak',
            'znuny_ticket_id' => 777,
            'znuny_ticket_number' => 'TN777777',
            'znuny_ticket_state_type' => 'closed',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot replace link for mismatched Zabbix Event ID.');

        $this->service->replaceTerminalTicketLink($initial, [
            'zabbix_event_id' => 'evt_different',
            'znuny_ticket_id' => 888,
            'znuny_ticket_number' => 'TN888888',
        ]);
    }

    public function test_ordinary_create_still_rejects_duplicate_even_if_terminal()
    {
        ZabbixTicket::create([
            'zabbix_event_id' => 'evt_term',
            'zabbix_host_name' => 'Host Term',
            'zabbix_problem_name' => 'Problem Term',
            'znuny_ticket_id' => 123,
            'znuny_ticket_number' => 'TN123',
            'znuny_ticket_state_type' => 'closed',
        ]);

        $this->expectException(ZabbixTicketAlreadyLinkedException::class);
        $this->service->create([
            'zabbix_event_id' => 'evt_term',
            'zabbix_host_name' => 'Host Term',
            'zabbix_problem_name' => 'Problem Term',
            'znuny_ticket_id' => 456,
            'znuny_ticket_number' => 'TN456',
        ]);
    }
}
