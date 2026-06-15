<?php

namespace App\Filament\Resources\AuditLogs\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('user.name'),
                TextEntry::make('user.email'),
                TextEntry::make('action'),
                TextEntry::make('entity_type'),
                TextEntry::make('entity_id'),
                TextEntry::make('ip_address'),
                TextEntry::make('user_agent'),
                TextEntry::make('context')
                    ->state(fn ($record) => json_encode($record->context ?? []))
                    ->formatStateUsing(function ($state) {
                        $context = json_decode($state, true) ?? [];

                        if (isset($context['changes']) && is_array($context['changes'])) {
                            $lines = [];
                            foreach ($context['changes'] as $change) {
                                $key = $change['key'] ?? '';
                                $old = is_string($change['old_value'] ?? null) ? ($change['old_value'] ?? 'null') : json_encode($change['old_value'] ?? 'null', JSON_UNESCAPED_UNICODE);
                                $new = is_string($change['new_value'] ?? null) ? ($change['new_value'] ?? 'null') : json_encode($change['new_value'] ?? 'null', JSON_UNESCAPED_UNICODE);
                                $lines[] = "{$key}: {$old} → {$new}";
                            }

                            return new HtmlString(implode('<br>', array_map('e', $lines)));
                        }

                        $json = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                        return new HtmlString('<pre style="margin: 0; white-space: pre-wrap;">'.e($json).'</pre>');
                    })
                    ->fontFamily('mono'),
            ]);
    }
}
