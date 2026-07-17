<?php

namespace Tests\Feature;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Setting;
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
            ->assertSee('znuny_default_agent_name: Old Name &rarr; Роман Андрушкевич', false)
            ->assertDontSee('\u0420\u043e');
    }

    public function test_audit_log_view_renders_simple_context_human_readable()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $auditLog = AuditLog::create([
            'action' => 'zabbix.problems_poll_completed',
            'context' => [
                'source' => 'manual',
                'manual' => true,
                'scheduled' => false,
                'cached_count' => 27,
                'fetched_count' => 152,
                'ttl_seconds' => 180,
                'limit' => 1000,
                'max_pages' => 3,
                'total_count' => 152,
            ],
        ]);

        $this->actingAs($admin)
            ->get(AuditLogResource::getUrl('view', ['record' => $auditLog]))
            ->assertSuccessful()
            ->assertSee('display: grid', false)
            ->assertSee('grid-template-columns: 170px minmax(0, 1fr)', false)
            ->assertSee('column-gap: 12px', false)
            ->assertSee('padding-left: 5px', false)
            ->assertSee('Source:')
            ->assertSee('Manual')
            ->assertSee('Manual:')
            ->assertSee('Yes')
            ->assertSee('Scheduled:')
            ->assertSee('No')
            ->assertSee('Cached count:')
            ->assertSee('27')
            ->assertSee('Fetched count:')
            ->assertSee('152')
            ->assertSee('Limit:')
            ->assertSee('1000')
            ->assertSee('Max pages:')
            ->assertSee('3')
            ->assertSee('Total count:')
            ->assertDontSee('Sourcescheduled')
            ->assertDontSee('SourceScheduled')
            ->assertDontSee('ManualNo');
    }

    public function test_audit_log_view_renders_nested_stats_human_readable()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $auditLog = AuditLog::create([
            'action' => 'znuny.linked_tickets_sync.completed',
            'context' => [
                'source' => 'manual',
                'stats' => [
                    'cached_new' => 2,
                    'refreshed_unchanged' => 10,
                    'updated_changed' => 1,
                    'errors' => 0,
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->get(AuditLogResource::getUrl('view', ['record' => $auditLog]))
            ->assertSuccessful()
            ->assertSee('display: grid', false)
            ->assertSee('grid-template-columns: 170px minmax(0, 1fr)', false)
            ->assertSee('column-gap: 12px', false)
            ->assertSee('padding-left: 5px', false)
            ->assertSee('Source:')
            ->assertSee('Manual')
            ->assertSee('Stats')
            ->assertSee('Cached new:')
            ->assertSee('2')
            ->assertSee('Refreshed unchanged:')
            ->assertSee('10')
            ->assertSee('Updated changed:')
            ->assertSee('Errors:')
            ->assertDontSee('Cached new2')
            ->assertDontSee('Cached new150')
            ->assertDontSee('Refreshed unchanged150')
            ->assertDontSee('Sourcescheduled');
    }

    public function test_audit_log_view_renders_state_types()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $auditLog = AuditLog::create([
            'action' => 'zabbix.problems_poll_completed',
            'context' => [
                'state_types' => 'new,open,pending_reminder,pending_auto',
            ],
        ]);

        $this->actingAs($admin)
            ->get(AuditLogResource::getUrl('view', ['record' => $auditLog]))
            ->assertSuccessful()
            ->assertSee('State types:')
            ->assertSee('new, open, pending reminder, pending auto');
    }

    public function test_audit_log_view_renders_empty_context()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $auditLog = AuditLog::create([
            'action' => 'test.empty',
            'context' => [],
        ]);

        $this->actingAs($admin)
            ->get(AuditLogResource::getUrl('view', ['record' => $auditLog]))
            ->assertSuccessful()
            ->assertSee('No context');
    }

    public function test_audit_log_view_displays_created_at_in_configured_timezone()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $setting = Setting::where('key', 'app_display_timezone')->first();
        $originalTimezone = $setting ? $setting->value : null;

        try {
            Setting::updateOrCreate(['key' => 'app_display_timezone'], ['value' => 'Europe/Kyiv']);

            $auditLog = AuditLog::create([
                'action' => 'test.timezone',
                'context' => [],
            ]);
            $auditLog->created_at = '2026-06-21 12:00:00';
            $auditLog->save();

            $expected = '21 June 2026, 15:00:00';

            $originalLocale = app()->getLocale();
            try {
                app()->setLocale('en');

                $this->actingAs($admin)
                    ->get(AuditLogResource::getUrl('view', ['record' => $auditLog]))
                    ->assertSuccessful()
                    ->assertSee($expected)
                    ->assertDontSee('Europe/Kyiv');
            } finally {
                app()->setLocale($originalLocale);
            }
        } finally {
            if ($originalTimezone === null) {
                Setting::where('key', 'app_display_timezone')->first()?->delete();
            } else {
                Setting::updateOrCreate(['key' => 'app_display_timezone'], ['value' => $originalTimezone]);
            }
        }
    }
}
