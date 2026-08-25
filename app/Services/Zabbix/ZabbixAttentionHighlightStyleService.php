<?php

namespace App\Services\Zabbix;

use App\Services\SettingsService;

class ZabbixAttentionHighlightStyleService
{
    public function getHighlightColorHex(string $color, ?string $customHex): string
    {
        if ($color === 'custom_hex') {
            $hex = '#'.ltrim(strtoupper(trim((string) $customHex)), '#');

            return preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) ? $hex : '#7FFFD4';
        }

        $presets = [
            'aquamarine' => '#7FFFD4',
            'white' => '#FFFFFF',
            'gray' => '#9CA3AF',
            'red' => '#EF4444',
            'orange' => '#F97316',
            'amber' => '#F59E0B',
            'yellow' => '#EAB308',
            'lime' => '#84CC16',
            'green' => '#22C55E',
            'emerald' => '#10B981',
            'cyan' => '#06B6D4',
            'sky' => '#0EA5E9',
            'blue' => '#3B82F6',
            'violet' => '#8B5CF6',
            'pink' => '#EC4899',
        ];

        return $presets[$color] ?? '#7FFFD4';
    }

    public function getHighlightStyle(): string
    {
        if (! SettingsService::bool('zabbix_attention_highlighting_enabled', false)) {
            return '';
        }

        $textColor = $this->getHighlightColorHex(
            SettingsService::string('zabbix_attention_highlight_text_color', 'aquamarine'),
            SettingsService::string('zabbix_attention_highlight_text_custom_hex', '')
        );

        $style = "color: {$textColor};";

        $underlineStyle = SettingsService::string('zabbix_attention_highlight_underline_style', 'disabled');

        if ($underlineStyle !== 'disabled') {
            $underlineColor = $this->getHighlightColorHex(
                SettingsService::string('zabbix_attention_highlight_underline_color', 'red'),
                SettingsService::string('zabbix_attention_highlight_underline_custom_hex', '')
            );
            $thickness = SettingsService::string('zabbix_attention_highlight_underline_thickness', '1px');

            $style .= " text-decoration-line: underline; text-decoration-style: {$underlineStyle}; text-decoration-color: {$underlineColor}; text-decoration-thickness: {$thickness}; text-underline-offset: 4px;";
        }

        return $style;
    }
}
