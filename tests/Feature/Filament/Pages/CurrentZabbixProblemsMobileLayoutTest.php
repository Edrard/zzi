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

        // Verify the media query exists
        $this->assertMatchesRegularExpression(
            '/@media\s*\(\s*max-width:\s*499\.98px\s*\)/is',
            $bladeContent
        );

        // Verify .mobile-hidden is display: none inside the mobile breakpoint
        $this->assertMatchesRegularExpression(
            '/@media\s*\(\s*max-width:\s*499\.98px\s*\)[^{]*\{[^}]*\.mobile-hidden\s*\{\s*display:\s*none\s*!important;\s*\}/is',
            $bladeContent
        );

        // Verify search control has both zbx-toolbar-search and mobile-hidden classes
        $this->assertMatchesRegularExpression(
            '/<div[^>]*class="(?=[^"]*\bzbx-toolbar-search\b)(?=[^"]*\bmobile-hidden\b)[^"]*"[^>]*>/is',
            $bladeContent
        );

        // Verify presets/filter group has both zbx-toolbar-presets and mobile-hidden classes
        $this->assertMatchesRegularExpression(
            '/<div[^>]*class="(?=[^"]*\bzbx-toolbar-presets\b)(?=[^"]*\bmobile-hidden\b)[^"]*"[^>]*>/is',
            $bladeContent
        );

        // Verify toolbar count has both zbx-toolbar-count and mobile-hidden classes
        $this->assertMatchesRegularExpression(
            '/<div[^>]*class="(?=[^"]*\bzbx-toolbar-count\b)(?=[^"]*\bmobile-hidden\b)[^"]*"[^>]*>/is',
            $bladeContent
        );

        // Verify problems table container does NOT have mobile-hidden class
        $this->assertDoesNotMatchRegularExpression(
            '/<div[^>]*class="(?=[^"]*\bzbx-table-container\b)(?=[^"]*\bmobile-hidden\b)[^"]*"[^>]*>/is',
            $bladeContent
        );

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

        // 5. inside that media rule, the severity class uses a zero-width visual collapse
        $this->assertMatchesRegularExpression(
            '/@media\s*\(\s*max-width:\s*599px\s*\)[^{]*\{(?:(?!\s*@media).)*\.zbx-table\s+th\.zbx-col-severity\s*,\s*\.zbx-table\s+td\.zbx-col-severity\s*\{[^}]*width:\s*0;/is',
            $bladeContent
        );

        // 7. no max-width: 600px off-by-one breakpoint is used
        $this->assertStringNotContainsString('@media (max-width: 600px)', $bladeContent);

        // 8. no nth-child rule was introduced for hiding severity
        $this->assertStringNotContainsString(':nth-child', $bladeContent);
    }

    public function test_mobile_table_layout_fits_within_600px()
    {
        $bladePath = resource_path('views/filament/pages/current-zabbix-problems.blade.php');
        $bladeContent = file_get_contents($bladePath);

        $this->assertStringContainsString('<th class="zbx-col-expand">', $bladeContent);
        $this->assertStringContainsString('<td class="zbx-col-expand">', $bladeContent);
        $this->assertStringContainsString('<th class="zbx-col-status">', $bladeContent);
        $this->assertStringContainsString('<td class="zbx-col-status">', $bladeContent);
        $this->assertStringContainsString('<th class="zbx-col-host">', $bladeContent);
        $this->assertStringContainsString('<td class="zbx-host-col zbx-col-host">', $bladeContent);
        $this->assertStringContainsString('<th class="zbx-col-problem">', $bladeContent);
        $this->assertStringContainsString('<td class="zbx-col-problem">', $bladeContent);
        $this->assertStringContainsString('<th class="zbx-col-duration">', $bladeContent);
        $this->assertStringContainsString('<td class="zbx-col-duration">', $bladeContent);

        $forbiddenStyles = ['width:\s*42px', 'width:\s*130px', 'width:\s*24px', 'width:\s*110px'];
        foreach ($forbiddenStyles as $style) {
            $this->assertDoesNotMatchRegularExpression(
                '/<(?:th|td)[^>]*style="[^"]*'.$style.'[^"]*"/i',
                $bladeContent
            );
        }

        $this->assertDoesNotMatchRegularExpression(
            '/<(?:th|td)[^>]*class="[^"]*zbx-col-duration[^"]*"[^>]*style="[^"]*text-align:\s*right[^"]*"/i',
            $bladeContent
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<(?:th|td)[^>]*style="[^"]*text-align:\s*right[^"]*"[^>]*class="[^"]*zbx-col-duration[^"]*"/i',
            $bladeContent
        );

        $this->assertStringContainsString('.zbx-col-expand { width: 42px; }', $bladeContent);
        $this->assertStringContainsString('.zbx-col-severity { width: 130px; }', $bladeContent);
        $this->assertStringContainsString('.zbx-col-status { width: 24px; }', $bladeContent);
        $this->assertStringContainsString('.zbx-col-duration { width: 110px; text-align: right; }', $bladeContent);

        $this->assertStringContainsString('@media (max-width: 599px)', $bladeContent);

        $this->assertStringContainsString('table-layout: fixed;', $bladeContent);

        $this->assertStringContainsString('white-space: normal;', $bladeContent);
        $this->assertStringContainsString('overflow-wrap: anywhere;', $bladeContent);
        $this->assertStringContainsString('word-break: break-word;', $bladeContent);

        $this->assertStringContainsString('width: 34px;', $bladeContent);
        $this->assertStringContainsString('width: 94px;', $bladeContent);

        $this->assertMatchesRegularExpression(
            '/\.zbx-table\s+th\.zbx-col-severity\s*,\s*\.zbx-table\s+td\.zbx-col-severity\s*\{[^}]*width:\s*0;/is',
            $bladeContent
        );
        $this->assertMatchesRegularExpression(
            '/\.zbx-table\s+th\.zbx-col-severity\s*,\s*\.zbx-table\s+td\.zbx-col-severity\s*\{[^}]*max-width:\s*0;/is',
            $bladeContent
        );
        $this->assertMatchesRegularExpression(
            '/\.zbx-table\s+th\.zbx-col-severity\s*,\s*\.zbx-table\s+td\.zbx-col-severity\s*\{[^}]*padding-inline:\s*0;/is',
            $bladeContent
        );
        $this->assertMatchesRegularExpression(
            '/\.zbx-table\s+th\.zbx-col-severity\s*,\s*\.zbx-table\s+td\.zbx-col-severity\s*\{[^}]*visibility:\s*hidden;/is',
            $bladeContent
        );
        $this->assertMatchesRegularExpression(
            '/\.zbx-table\s+th\.zbx-col-severity\s*,\s*\.zbx-table\s+td\.zbx-col-severity\s*\{[^}]*overflow:\s*hidden;/is',
            $bladeContent
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\.zbx-table\s+(?:th|td)\.zbx-col-severity\s*\{[^}]*display:\s*none;/is',
            $bladeContent
        );

        $this->assertStringNotContainsString('.zbx-col-expand { display: none', $bladeContent);
        $this->assertStringNotContainsString('.zbx-col-status { display: none', $bladeContent);
        $this->assertStringNotContainsString('.zbx-col-host { display: none', $bladeContent);
        $this->assertStringNotContainsString('.zbx-col-problem { display: none', $bladeContent);
        $this->assertStringNotContainsString('.zbx-col-duration { display: none', $bladeContent);

        $this->assertStringNotContainsString('nth-child', $bladeContent);

        $this->assertStringNotContainsString('overflow-x: hidden', $bladeContent);
        $this->assertStringNotContainsString('overflow-x: clip', $bladeContent);

        $this->assertStringContainsString('colspan="6"', $bladeContent);
        $this->assertStringNotContainsString('colspan="5"', $bladeContent);
        $this->assertStringNotContainsString('x-bind:colspan', $bladeContent);
        $this->assertStringNotContainsString('matchMedia', $bladeContent);
        $this->assertStringNotContainsString('innerWidth', $bladeContent);

        $this->assertSame(
            1,
            substr_count($bladeContent, '<tr class="zbx-details-row"'),
            'There should be exactly one details row definition in the template.'
        );

        $this->assertStringContainsString('overflow-x: auto;', $bladeContent);
    }
}
