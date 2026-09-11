<?php

namespace Tests\Unit\Filament\Support;

use App\Filament\Support\TicketDetailsPayload;
use App\Models\ZabbixTicket;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TicketDetailsPayloadTest extends TestCase
{
    public function test_it_hydrates_customer_user_and_id_independently_from_array()
    {
        $arr = [
            'TicketID' => 123,
            'TicketNumber' => '123456',
            'Title' => 'Test Ticket',
            'CustomerUserID' => 'FinbertClients',
            'CustomerID' => 'finbert',
            'customer_user_registered' => false,
        ];

        $payload = TicketDetailsPayload::fromRecord($arr);

        $this->assertEquals('FinbertClients', $payload->customer_user);
        $this->assertEquals('finbert', $payload->customer_id);
        $this->assertFalse($payload->customer_user_registered);
    }

    public function test_it_preserves_customer_user_registered_true()
    {
        $arr = [
            'TicketID' => 123,
            'CustomerUserID' => 'agrotekhnik',
            'CustomerID' => 'agrotekhnik',
            'customer_user_registered' => true,
        ];

        $payload = TicketDetailsPayload::fromRecord($arr);

        $this->assertTrue($payload->customer_user_registered);
    }

    public function test_it_preserves_customer_user_registered_null()
    {
        $arr = [
            'TicketID' => 123,
            'CustomerUserID' => 'someuser',
            'CustomerID' => 'somecompany',
        ];

        $payload = TicketDetailsPayload::fromRecord($arr);

        $this->assertNull($payload->customer_user_registered);
    }

    public function test_it_uses_record_created_at_for_zabbix_ticket_model()
    {
        $timestamp = Carbon::parse('2026-09-11 15:30:00');
        $record = new ZabbixTicket;
        $record->created_at = $timestamp;
        $record->manual_flap_count = 0;

        $payload = TicketDetailsPayload::fromRecord($record);

        $this->assertEquals($timestamp, $payload->created_at);
    }
}
