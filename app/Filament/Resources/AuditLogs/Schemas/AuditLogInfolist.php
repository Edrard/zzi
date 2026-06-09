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
                    ->formatStateUsing(function ($state) {
                        if (is_array($state) && isset($state['changes'])) {
                            $output = [];
                            foreach ($state['changes'] as $change) {
                                $key = $change['key'] ?? '';
                                $old = $change['old_value'] ?? 'null';
                                $new = $change['new_value'] ?? 'null';
                                $output[] = "{$key}: {$old} → {$new}";
                            }

                            return $output;
                        }

                        $json = is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $state;

                        return new HtmlString('<pre style="margin: 0; white-space: pre-wrap;">'.e($json).'</pre>');
                    })
                    ->listWithLineBreaks()
                    ->fontFamily('mono'),
            ]);
    }
}
