<?php

namespace Tests\Feature\Filament\Pages;

use Tests\TestCase;

class CurrentZabbixProblemsMobileLayoutTest extends TestCase
{
    public function test_toolbar_search_layout_on_mobile_and_desktop()
    {
        $bladePath = resource_path('views/filament/pages/current-zabbix-problems.blade.php');
        $bladeContent = file_get_contents($bladePath);

        $this->assertStringNotContainsString('style="flex: 0 0 350px;"', $bladeContent);
        $this->assertStringNotContainsString('style="flex: 0 1 350px;"', $bladeContent);
        $this->assertStringContainsString('<div class="zbx-toolbar-search">', $bladeContent);

        $expectedBaseRules = [
            'flex: 0 1 auto;',
            'width: 100%;',
            'max-width: none;',
        ];

        foreach ($expectedBaseRules as $rule) {
            $this->assertStringContainsString($rule, $bladeContent);
        }

        $expectedDesktopRules = [
            'flex: 1 1 350px;',
            'min-width: 0;',
            'max-width: 400px;',
        ];

        foreach ($expectedDesktopRules as $rule) {
            $this->assertStringContainsString($rule, $bladeContent);
        }
    }
}
