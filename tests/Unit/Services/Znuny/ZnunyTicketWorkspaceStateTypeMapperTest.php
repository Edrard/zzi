<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\ZnunyTicketWorkspaceStateTypeMapper;
use PHPUnit\Framework\TestCase;

class ZnunyTicketWorkspaceStateTypeMapperTest extends TestCase
{
    public function test_it_maps_valid_ids_to_znuny_state_types(): void
    {
        $ids = ['new', 'open', 'pending_reminder', 'pending_auto', 'closed', 'merged'];
        $expected = ['new', 'open', 'pending reminder', 'pending auto', 'closed', 'merged'];

        $result = ZnunyTicketWorkspaceStateTypeMapper::idsToZnunyStateTypes($ids);

        $this->assertEquals($expected, $result);
    }

    public function test_it_ignores_invalid_ids(): void
    {
        $ids = ['new', 'invalid_id', 'open', 'another_bad_one'];
        $expected = ['new', 'open'];

        $result = ZnunyTicketWorkspaceStateTypeMapper::idsToZnunyStateTypes($ids);

        $this->assertEquals($expected, $result);
    }

    public function test_it_handles_empty_array(): void
    {
        $result = ZnunyTicketWorkspaceStateTypeMapper::idsToZnunyStateTypes([]);

        $this->assertEmpty($result);
    }
}
