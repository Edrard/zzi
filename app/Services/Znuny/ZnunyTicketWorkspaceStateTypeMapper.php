<?php

namespace App\Services\Znuny;

class ZnunyTicketWorkspaceStateTypeMapper
{
    private const MAP = [
        'new' => 'new',
        'open' => 'open',
        'pending_reminder' => 'pending reminder',
        'pending_auto' => 'pending auto',
        'closed' => 'closed',
        'merged' => 'merged',
    ];

    public static function idsToZnunyStateTypes(array $ids): array
    {
        $mapped = [];

        foreach ($ids as $id) {
            if (array_key_exists($id, self::MAP)) {
                $mapped[] = self::MAP[$id];
            }
        }

        return $mapped;
    }

    public function mapInternalIdsToZnunyTypes(array $ids): array
    {
        return self::idsToZnunyStateTypes($ids);
    }
}
