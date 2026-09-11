<?php

namespace Tests\Feature\Znuny;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\ZabbixTicket;
use App\Services\SettingsService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyTicketCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ZnunyTicketCreationTerminalReplacementFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('settings')->insert([
            ['key' => 'znuny_default_ticket_state', 'value' => 'new', 'type' => 'string'],
            ['key' => 'znuny_default_ticket_lock', 'value' => 'unlock', 'type' => 'string'],
            ['key' => 'znuny_default_ticket_priority', 'value' => '3 normal', 'type' => 'string'],
        ]);
        app(SettingsService::class)->clearAllCaches();
    }

    public function test_manual_creation_for_closed_ticket_replaces_existing_row_and_logs_audit()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $initial = ZabbixTicket::create([
            'zabbix_event_id' => '10099',
            'zabbix_host_name' => 'Host Alfa',
            'zabbix_problem_name' => 'Disk Space Low',
            'znuny_ticket_id' => 501,
            'znuny_ticket_number' => 'TN501',
            'znuny_queue_name' => 'OldQueue',
            'znuny_owner_id' => 10,
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
            'manual_flap_count' => 2,
        ]);

        $initial->created_at = now()->subWeeks(2);
        $initial->save();
        $oldCreatedAt = $initial->fresh()->created_at;

        $mockClient = $this->createMock(ZnunyClient::class);
        $mockClient->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID_123']);
        $mockClient->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);
        $mockClient->expects($this->once())->method('createTicket')->willReturn([
            'success' => true,
            'ticket_id' => 902,
            'ticket_number' => 'TN902',
            'warnings' => [],
        ]);
        $mockClient->expects($this->once())->method('getTicket')->with(902)->willReturn([
            'TicketID' => 902,
            'TicketNumber' => 'TN902',
            'State' => 'new',
            'StateType' => 'new',
            'Queue' => 'NewQueue',
            'QueueID' => 1,
            'Owner' => 'agent1',
            'OwnerID' => 15,
            'Title' => 'Ticket Title',
        ]);
        $this->app->instance(ZnunyClient::class, $mockClient);

        $service = app(ZnunyTicketCreationService::class);
        $result = $service->createTicketForProblem(
            '10099',
            'Host Alfa',
            'Disk Space Low',
            15,
            'NewQueue',
            'customer1',
            'Ticket Title',
            'Ticket Article Subject',
            'Ticket Article Body'
        );

        $this->assertTrue($result['success']);
        $this->assertEquals(902, $result['ticket_id']);
        $this->assertEquals('TN902', $result['ticket_number']);

        // Exactly one row must exist
        $this->assertEquals(1, ZabbixTicket::where('zabbix_event_id', '10099')->count());
        $this->assertEquals(1, ZabbixTicket::count());

        $updated = ZabbixTicket::where('zabbix_event_id', '10099')->first();
        $this->assertEquals($initial->id, $updated->id);
        $this->assertEquals(902, $updated->znuny_ticket_id);
        $this->assertEquals('TN902', $updated->znuny_ticket_number);
        $this->assertEquals('NewQueue', $updated->znuny_queue_name);
        $this->assertEquals(15, $updated->znuny_owner_id);

        // created_at must be updated to now
        $this->assertTrue($updated->created_at->greaterThan($oldCreatedAt));
        $this->assertLessThan(5, abs(now()->diffInSeconds($updated->created_at)));

        // Immediate sync must have run and set last_synced_at and fresh state
        $this->assertNotNull($updated->znuny_ticket_last_synced_at);
        $this->assertEquals('new', $updated->znuny_ticket_state_type);

        // Stale fields must be reset
        $this->assertNull($updated->znuny_ticket_closed_at);
        $this->assertNull($updated->manual_lifecycle_status);
        $this->assertNull($updated->manual_lifecycle_closed_at);
        $this->assertNull($updated->manual_close_eligible_at);
        $this->assertNull($updated->manual_reopened_at);
        $this->assertNull($updated->manual_lifecycle_last_checked_at);
        $this->assertNull($updated->zabbix_problem_resolved_at);
        $this->assertEquals(0, $updated->manual_flap_count);

        // Audit log must exist
        $auditLog = AuditLog::where('action', 'zabbix_ticket.link_replaced')->first();
        $this->assertNotNull($auditLog);
        $this->assertEquals('10099', $auditLog->context['zabbix_event_id']);
        $this->assertEquals(501, $auditLog->context['old_ticket_id']);
        $this->assertEquals('TN501', $auditLog->context['old_ticket_number']);
        $this->assertEquals('closed', $auditLog->context['old_state_type']);
        $this->assertEquals('closed successful', $auditLog->context['old_state_name']);
        $this->assertEquals(902, $auditLog->context['new_ticket_id']);
        $this->assertEquals('TN902', $auditLog->context['new_ticket_number']);
    }

    public function test_manual_creation_for_merged_ticket_replaces_existing_row()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $initial = ZabbixTicket::create([
            'zabbix_event_id' => '10098',
            'zabbix_host_name' => 'Host Beta',
            'zabbix_problem_name' => 'Memory High',
            'znuny_ticket_id' => 601,
            'znuny_ticket_number' => 'TN601',
            'znuny_queue_name' => 'Support',
            'znuny_owner_id' => 10,
            'znuny_state_name' => 'merged',
            'znuny_ticket_state_type' => 'merged',
        ]);

        $mockClient = $this->createMock(ZnunyClient::class);
        $mockClient->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID_456']);
        $mockClient->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);
        $mockClient->expects($this->once())->method('createTicket')->willReturn([
            'success' => true,
            'ticket_id' => 903,
            'ticket_number' => 'TN903',
            'warnings' => [],
        ]);
        $mockClient->expects($this->once())->method('getTicket')->with(903)->willReturn([
            'TicketID' => 903,
            'TicketNumber' => 'TN903',
            'State' => 'new',
            'StateType' => 'new',
            'Queue' => 'Support',
            'QueueID' => 2,
            'Owner' => 'agent2',
            'OwnerID' => 20,
            'Title' => 'Title Beta',
        ]);
        $this->app->instance(ZnunyClient::class, $mockClient);

        $service = app(ZnunyTicketCreationService::class);
        $result = $service->createTicketForProblem(
            '10098',
            'Host Beta',
            'Memory High',
            20,
            'Support',
            'customer2',
            'Title Beta',
            'Subj Beta',
            'Body Beta'
        );

        $this->assertTrue($result['success']);
        $this->assertEquals(903, $result['ticket_id']);
        $this->assertEquals('TN903', $result['ticket_number']);

        $this->assertEquals(1, ZabbixTicket::where('zabbix_event_id', '10098')->count());
        $updated = ZabbixTicket::where('zabbix_event_id', '10098')->first();
        $this->assertEquals($initial->id, $updated->id);
        $this->assertEquals(903, $updated->znuny_ticket_id);
        $this->assertNotNull($updated->znuny_ticket_last_synced_at);
        $this->assertEquals('new', $updated->znuny_ticket_state_type);
    }

    public function test_manual_creation_for_closed_ticket_succeeds_even_if_immediate_sync_fails()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $initial = ZabbixTicket::create([
            'zabbix_event_id' => '10095',
            'zabbix_host_name' => 'Host Epsilon',
            'zabbix_problem_name' => 'CPU High',
            'znuny_ticket_id' => 502,
            'znuny_ticket_number' => 'TN502',
            'znuny_queue_name' => 'OldQueue',
            'znuny_owner_id' => 10,
            'znuny_state_name' => 'closed successful',
            'znuny_ticket_state_type' => 'closed',
        ]);

        $mockClient = $this->createMock(ZnunyClient::class);
        $mockClient->expects($this->once())->method('getCustomerUser')->willReturn(['found' => true, 'customer_id' => 'CID_999']);
        $mockClient->expects($this->once())->method('validateTicketCreate')->willReturn(['valid' => true]);
        $mockClient->expects($this->once())->method('createTicket')->willReturn([
            'success' => true,
            'ticket_id' => 904,
            'ticket_number' => 'TN904',
            'warnings' => [],
        ]);
        // Simulate immediate sync failing with an exception
        $mockClient->expects($this->once())->method('getTicket')->with(904)->willThrowException(new \RuntimeException('Connection timeout to Znuny'));
        $this->app->instance(ZnunyClient::class, $mockClient);

        $service = app(ZnunyTicketCreationService::class);
        $result = $service->createTicketForProblem(
            '10095',
            'Host Epsilon',
            'CPU High',
            10,
            'OldQueue',
            'customer5',
            'Title Epsilon',
            'Subj Epsilon',
            'Body Epsilon'
        );

        // Result MUST remain success and NOT orphaned
        $this->assertTrue($result['success']);
        $this->assertFalse($result['orphaned'] ?? false);
        $this->assertEquals(904, $result['ticket_id']);
        $this->assertEquals('TN904', $result['ticket_number']);

        // Row was still replaced successfully with new ticket link and updated created_at
        $this->assertEquals(1, ZabbixTicket::where('zabbix_event_id', '10095')->count());
        $updated = ZabbixTicket::where('zabbix_event_id', '10095')->first();
        $this->assertEquals($initial->id, $updated->id);
        $this->assertEquals(904, $updated->znuny_ticket_id);
        $this->assertEquals('TN904', $updated->znuny_ticket_number);
        $this->assertNull($updated->znuny_ticket_last_synced_at);
    }

    public function test_manual_creation_for_open_ticket_blocks_as_duplicate_and_makes_no_znuny_create_call()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        ZabbixTicket::create([
            'zabbix_event_id' => '10097',
            'zabbix_host_name' => 'Host Gamma',
            'zabbix_problem_name' => 'Service Down',
            'znuny_ticket_id' => 701,
            'znuny_ticket_number' => 'TN701',
            'znuny_queue_name' => 'Support',
            'znuny_owner_id' => 10,
            'znuny_state_name' => 'open',
            'znuny_ticket_state_type' => 'open',
        ]);

        $mockClient = $this->createMock(ZnunyClient::class);
        $mockClient->expects($this->never())->method('createTicket');
        $this->app->instance(ZnunyClient::class, $mockClient);

        $service = app(ZnunyTicketCreationService::class);
        $result = $service->createTicketForProblem(
            '10097',
            'Host Gamma',
            'Service Down',
            10,
            'Support',
            'customer3',
            'Title Gamma',
            'Subj Gamma',
            'Body Gamma'
        );

        $this->assertFalse($result['success']);
        $this->assertTrue($result['duplicate']);
        $this->assertEquals(701, $result['ticket_id']);
        $this->assertEquals('TN701', $result['ticket_number']);

        $row = ZabbixTicket::where('zabbix_event_id', '10097')->first();
        $this->assertEquals(701, $row->znuny_ticket_id);
        $this->assertEquals('open', $row->znuny_ticket_state_type);
    }

    public function test_manual_creation_for_unknown_or_blank_state_blocks_as_duplicate()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        ZabbixTicket::create([
            'zabbix_event_id' => '10096',
            'zabbix_host_name' => 'Host Delta',
            'zabbix_problem_name' => 'Unknown State Problem',
            'znuny_ticket_id' => 801,
            'znuny_ticket_number' => 'TN801',
            'znuny_ticket_state_type' => '',
        ]);

        $mockClient = $this->createMock(ZnunyClient::class);
        $mockClient->expects($this->never())->method('createTicket');
        $this->app->instance(ZnunyClient::class, $mockClient);

        $service = app(ZnunyTicketCreationService::class);
        $result = $service->createTicketForProblem(
            '10096',
            'Host Delta',
            'Unknown State Problem',
            10,
            'Support',
            'customer4',
            'Title Delta',
            'Subj Delta',
            'Body Delta'
        );

        $this->assertFalse($result['success']);
        $this->assertTrue($result['duplicate']);
        $this->assertEquals(801, $result['ticket_id']);
        $this->assertEquals('TN801', $result['ticket_number']);
    }
}
