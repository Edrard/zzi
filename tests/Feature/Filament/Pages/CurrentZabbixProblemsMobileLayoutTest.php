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

    public function test_severity_column_is_hidden_on_mobile()
    {
        $bladePath = resource_path('views/filament/pages/current-zabbix-problems.blade.php');
        $bladeContent = file_get_contents($bladePath);

        // 1 & 6. the severity header has the semantic responsive class and is not removed
        $this->assertMatchesRegularExpression(
            '/<th[^>]*class="[^"]*zbx-col-severity[^"]*"[^>]*>\s*<button[^>]*wire:click="sortBy\(\'severity\'\)"/is',
            $bladeContent
        );

        // 2 & 6. the severity body cell has the same class and is not removed
        $this->assertMatchesRegularExpression(
            '/<td[^>]*class="[^"]*zbx-col-severity[^"]*"[^>]*>\s*<span[^>]*class="[^"]*zbx-severity/is',
            $bladeContent
        );

        // 3. no colgroup/col exists in this table to receive the class
        $this->assertStringNotContainsString('<colgroup>', $bladeContent);

        // 4. @media (max-width: 599px) exists
        $this->assertStringContainsString('@media (max-width: 599px)', $bladeContent);

        // 5. inside that media rule, the severity class uses display: none
        $this->assertMatchesRegularExpression(
            '/@media\s*\(\s*max-width:\s*599px\s*\)[^{]*\{[^\}]*\.zbx-col-severity\s*\{\s*display:\s*none;\s*\}/is',
            $bladeContent
        );

        // 7. no max-width: 600px off-by-one breakpoint is used
        $this->assertStringNotContainsString('@media (max-width: 600px)', $bladeContent);

        // 8. no nth-child rule was introduced for hiding severity
        $this->assertStringNotContainsString(':nth-child', $bladeContent);
    }
}
