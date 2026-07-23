<?php

namespace App\Livewire;

use App\Models\SystemAlert;
use Livewire\Component;

class SystemAlertsBell extends Component
{
    public $activeAlerts = [];

    public $showDropdown = false;

    public $hasUnread = false;

    public function mount()
    {
        $this->loadAlerts();
    }

    public function loadAlerts()
    {
        $this->activeAlerts = SystemAlert::where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();
        $this->hasUnread = $this->activeAlerts->isNotEmpty();
    }

    public function toggleDropdown()
    {
        $this->showDropdown = ! $this->showDropdown;
        if ($this->showDropdown) {
            $this->loadAlerts();
        }
    }

    public function acknowledge($id)
    {
        abort_unless(auth()->user()?->canAdministerApplication(), 403);

        $alert = SystemAlert::find($id);
        if ($alert && $alert->status === 'active') {
            app(\App\Services\SystemAlertService::class)->acknowledge($alert, auth()->id());
            $this->loadAlerts();
            if ($this->activeAlerts->isEmpty()) {
                $this->showDropdown = false;
            }
        }
    }

    public function render()
    {
        return view('livewire.system-alerts-bell');
    }
}
