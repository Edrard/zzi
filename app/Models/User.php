<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'show_current_problems_status_panel', 'show_znuny_closed_ticket_status_panel', 'show_scheduled_tasks_status_panel', 'default_landing_page'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active !== false && in_array($this->role, ['admin', 'operator', 'viewer'], true);
    }

    public function canViewCurrentProblemsStatusPanel(): bool
    {
        return $this->role === 'admin' && $this->show_current_problems_status_panel;
    }

    public function canViewZnunyClosedTicketStatusPanel(): bool
    {
        return $this->role === 'admin' && $this->show_znuny_closed_ticket_status_panel;
    }

    public function canViewScheduledTasksStatusPanel(): bool
    {
        return $this->role === 'admin' && $this->show_scheduled_tasks_status_panel;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'show_current_problems_status_panel' => 'boolean',
            'show_znuny_closed_ticket_status_panel' => 'boolean',
            'show_scheduled_tasks_status_panel' => 'boolean',
        ];
    }
}
