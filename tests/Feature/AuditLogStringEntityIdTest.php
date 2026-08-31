<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogStringEntityIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_logger_persists_string_entity_id(): void
    {
        $log = AuditLogger::log(
            action: 'test.string_entity_id',
            entityType: 'znuny_customer_user',
            entityId: '1c@ichnya.com',
            context: ['source' => 'test'],
            user: null,
            useAuthenticatedUserFallback: false,
        );

        $fresh = AuditLog::query()->findOrFail($log->id);

        $this->assertSame('1c@ichnya.com', (string) $fresh->entity_id);
        $this->assertSame('znuny_customer_user', $fresh->entity_type);
        $this->assertSame('test.string_entity_id', $fresh->action);
        $this->assertSame(['source' => 'test'], $fresh->context);
    }

    public function test_audit_logger_still_accepts_numeric_entity_id(): void
    {
        $log = AuditLogger::log(
            action: 'test.numeric_entity_id',
            entityType: 'ticket',
            entityId: 59457,
            context: [],
            user: null,
            useAuthenticatedUserFallback: false,
        );

        $fresh = AuditLog::query()->findOrFail($log->id);

        $this->assertSame('59457', (string) $fresh->entity_id);
    }
}
