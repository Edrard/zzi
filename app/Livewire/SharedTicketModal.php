<?php

namespace App\Livewire;

use App\Models\ZabbixTicket;
use App\Services\Znuny\ZnunyTicketWorkspaceCacheReader;
use Illuminate\Support\Facades\Redis;
use Livewire\Component;

class SharedTicketModal extends Component
{
    public ?array $ticket = null;

    protected $listeners = [
        'open-shared-ticket-modal' => 'openModal',
    ];

    public function openModal(int $ticketId)
    {
        // 1. Try to fetch normalized ticket from Redis cache
        $reader = app(ZnunyTicketWorkspaceCacheReader::class);
        $cachedTicket = $reader->getTicketById($ticketId);

        if ($cachedTicket) {
            $this->ticket = $cachedTicket;
        } else {
            // 2. Fallback to Local DB Linked Ticket data
            $zabbixTicket = ZabbixTicket::where('znuny_ticket_id', $ticketId)->first();
            if ($zabbixTicket) {
                $this->ticket = [
                    'TicketID' => $zabbixTicket->znuny_ticket_id,
                    'TicketNumber' => $zabbixTicket->znuny_ticket_number,
                    'Title' => '-',
                    'Queue' => $zabbixTicket->znuny_queue_name,
                    'Owner' => $zabbixTicket->znuny_owner_name,
                    'CustomerUserID' => '-',
                    'State' => $zabbixTicket->znuny_state_name,
                    'StateType' => $zabbixTicket->znuny_ticket_state_type,
                    'Priority' => $zabbixTicket->znuny_priority,
                    'Type' => '-',
                    'Created' => '-',
                    'Changed' => $zabbixTicket->znuny_ticket_changed_at ? $zabbixTicket->znuny_ticket_changed_at->toDateTimeString() : '-',
                    'ArticleCount' => '-',
                    'LastArticleCreated' => '-',
                    'is_linked_to_zabbix_problem' => true,
                    'zabbix_host_name' => $zabbixTicket->zabbix_host_name,
                    'zabbix_problem_name' => $zabbixTicket->zabbix_problem_name,
                    'zabbix_event_id' => $zabbixTicket->zabbix_event_id,
                    'zabbix_problem_is_active' => $zabbixTicket->zabbix_problem_is_active,
                    'zabbix_problem_started_at' => $zabbixTicket->zabbix_started_at ? $zabbixTicket->zabbix_started_at->toDateTimeString() : null,
                    'zabbix_severity' => $zabbixTicket->zabbix_severity,
                    'manual_lifecycle_status' => $zabbixTicket->manual_lifecycle_status,
                ];
            } else {
                $this->ticket = null;
            }
        }

        $this->dispatch('open-modal', id: 'shared-ticket-details-modal');
    }

    public function render()
    {
        return view('livewire.shared-ticket-modal');
    }
}
