<?php

namespace Tests\Unit\Models;

use App\Enums\ZnunyTicketCreationAttemptStatus;
use App\Models\User;
use App\Models\ZnunyTicketCreationAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZnunyTicketCreationAttemptTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_persistence_and_status_casting()
    {
        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'zabbix_problem',
            'source_id' => '12345',
            'marker' => '[ZBX:12345]',
            'subject_original' => 'Alert',
            'subject_sent' => 'Alert [ZBX:12345]',
            'status' => ZnunyTicketCreationAttemptStatus::Preparing,
        ]);

        $this->assertDatabaseHas('znuny_ticket_creation_attempts', [
            'id' => $attempt->id,
            'status' => 'preparing',
        ]);

        $attempt->refresh();
        $this->assertInstanceOf(ZnunyTicketCreationAttemptStatus::class, $attempt->status);
        $this->assertEquals(ZnunyTicketCreationAttemptStatus::Preparing, $attempt->status);
    }

    public function test_date_casts()
    {
        $now = now()->startOfSecond();

        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'zabbix_problem',
            'source_id' => '123',
            'marker' => '[ZBX:123]',
            'subject_original' => 'Alert',
            'subject_sent' => 'Alert [ZBX:123]',
            'status' => ZnunyTicketCreationAttemptStatus::Success,
            'started_at' => $now,
            'finished_at' => $now->copy()->addSeconds(2),
            'last_checked_at' => $now->copy()->addMinutes(5),
        ]);

        $attempt->refresh();

        $this->assertTrue($attempt->started_at->equalTo($now));
        $this->assertTrue($attempt->finished_at->equalTo($now->copy()->addSeconds(2)));
        $this->assertTrue($attempt->last_checked_at->equalTo($now->copy()->addMinutes(5)));
    }

    public function test_json_casts()
    {
        $payload = ['Ticket' => ['Title' => 'Test']];
        $response = ['success' => true, 'ticket_id' => 99];

        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'zabbix_problem',
            'source_id' => '123',
            'marker' => '[ZBX:123]',
            'subject_original' => 'Alert',
            'subject_sent' => 'Alert [ZBX:123]',
            'status' => ZnunyTicketCreationAttemptStatus::Success,
            'payload_snapshot' => $payload,
            'response_snapshot' => $response,
        ]);

        $attempt->refresh();

        $this->assertIsArray($attempt->payload_snapshot);
        $this->assertEquals($payload, $attempt->payload_snapshot);

        $this->assertIsArray($attempt->response_snapshot);
        $this->assertEquals($response, $attempt->response_snapshot);
    }

    public function test_check_attempts_defaulting_to_zero()
    {
        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'zabbix_problem',
            'source_id' => '123',
            'marker' => '[ZBX:123]',
            'subject_original' => 'Alert',
            'subject_sent' => 'Alert [ZBX:123]',
            'status' => ZnunyTicketCreationAttemptStatus::Preparing,
        ]);

        $attempt->refresh();

        $this->assertIsInt($attempt->check_attempts);
        $this->assertEquals(0, $attempt->check_attempts);
    }

    public function test_created_by_relationship()
    {
        $user = User::factory()->create();

        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'zabbix_problem',
            'source_id' => '123',
            'marker' => '[ZBX:123]',
            'subject_original' => 'Alert',
            'subject_sent' => 'Alert [ZBX:123]',
            'status' => ZnunyTicketCreationAttemptStatus::Preparing,
            'created_by' => $user->id,
        ]);

        $this->assertInstanceOf(User::class, $attempt->createdBy);
        $this->assertEquals($user->id, $attempt->createdBy->id);
    }

    public function test_multiple_records_may_use_same_marker()
    {
        $marker = '[ZBX:123]';

        ZnunyTicketCreationAttempt::create([
            'source_type' => 'zabbix_problem',
            'source_id' => '123',
            'marker' => $marker,
            'subject_original' => 'Alert',
            'subject_sent' => 'Alert [ZBX:123]',
            'status' => ZnunyTicketCreationAttemptStatus::Uncertain,
        ]);

        $secondAttempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'zabbix_problem',
            'source_id' => '123',
            'marker' => $marker,
            'subject_original' => 'Alert',
            'subject_sent' => 'Alert [ZBX:123]',
            'status' => ZnunyTicketCreationAttemptStatus::Preparing,
        ]);

        $this->assertDatabaseCount('znuny_ticket_creation_attempts', 2);
        $this->assertEquals($marker, $secondAttempt->marker);
    }

    public function test_ticket_id_integer_casting()
    {
        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'zabbix_problem',
            'source_id' => '123',
            'marker' => '[ZBX:123]',
            'subject_original' => 'Alert',
            'subject_sent' => 'Alert [ZBX:123]',
            'status' => ZnunyTicketCreationAttemptStatus::Preparing,
            'ticket_id' => '98765',
        ]);

        $attempt->refresh();
        $this->assertIsInt($attempt->ticket_id);
        $this->assertSame(98765, $attempt->ticket_id);
    }

    public function test_created_by_becomes_null_after_related_user_is_deleted()
    {
        $user = User::factory()->create();

        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'zabbix_problem',
            'source_id' => '123',
            'marker' => '[ZBX:123]',
            'subject_original' => 'Alert',
            'subject_sent' => 'Alert [ZBX:123]',
            'status' => ZnunyTicketCreationAttemptStatus::Preparing,
            'created_by' => $user->id,
        ]);

        $this->assertEquals($user->id, $attempt->created_by);

        $user->delete();
        $attempt->refresh();

        $this->assertNull($attempt->created_by);
    }

    public function test_nullable_snapshot_fields_remain_null()
    {
        $attempt = ZnunyTicketCreationAttempt::create([
            'source_type' => 'zabbix_problem',
            'source_id' => '123',
            'marker' => '[ZBX:123]',
            'subject_original' => 'Alert',
            'subject_sent' => 'Alert [ZBX:123]',
            'status' => ZnunyTicketCreationAttemptStatus::Preparing,
        ]);

        $attempt->refresh();

        $this->assertNull($attempt->payload_snapshot);
        $this->assertNull($attempt->response_snapshot);
    }

    public function test_enum_cases_match_exact_expected_ordered_list()
    {
        $expectedValues = [
            'preparing',
            'sending',
            'success',
            'confirmed_failed',
            'uncertain',
            'orphaned',
            'recovered',
            'manually_linked',
            'resolved_without_ticket',
        ];

        $actualValues = array_map(
            fn ($case) => $case->value,
            ZnunyTicketCreationAttemptStatus::cases()
        );

        $this->assertEquals($expectedValues, $actualValues);
    }
}
