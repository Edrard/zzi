<?php

namespace Tests\Feature;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_view_renders_json_context_with_unescaped_unicode()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Insert log directly to ensure we can test raw JSON rendering for random context arrays
        $auditLog = AuditLog::create([
            'action' => 'custom.action',
            'context' => [
                'new_value' => 'Роман Андрушкевич',
            ],
        ]);

        $html = $this->actingAs($admin)
            ->get(AuditLogResource::getUrl('view', ['record' => $auditLog]))
            ->assertSuccessful()
            ->getContent();

        $this->actingAs($admin)
            ->get(AuditLogResource::getUrl('view', ['record' => $auditLog]))
            ->assertSuccessful()
            ->assertSee('Роман Андрушкевич')
            ->assertDontSee('\u0420\u043e');
    }

    public function test_audit_log_view_renders_changes_array_with_unescaped_unicode()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $auditLog = AuditLog::create([
            'action' => 'settings.updated',
            'context' => [
                'changes' => [
                    [
                        'key' => 'znuny_default_agent_name',
                        'old_value' => 'Old Name',
                        'new_value' => 'Роман Андрушкевич',
                    ],
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->get(AuditLogResource::getUrl('view', ['record' => $auditLog]))
            ->assertSuccessful()
            ->assertSee('znuny_default_agent_name: Old Name → Роман Андрушкевич', false)
            ->assertDontSee('\u0420\u043e');
    }
}
