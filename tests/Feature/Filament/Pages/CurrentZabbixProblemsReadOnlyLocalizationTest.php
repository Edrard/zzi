<?php

namespace Tests\Feature\Filament\Pages;

use Tests\TestCase;

class CurrentZabbixProblemsReadOnlyLocalizationTest extends TestCase
{
    public function test_new_translation_keys_resolve_correctly()
    {
        $originalLocale = app()->getLocale();

        try {
            app()->setLocale('en');
            $this->assertEquals('Zabbix problems refreshed successfully', __('current_zabbix_problems.notifications.refresh_success'));
            $this->assertEquals('Failed to refresh Zabbix problems', __('current_zabbix_problems.notifications.refresh_failed'));
            $this->assertEquals('Error:', __('current_zabbix_problems.details.error'));
            $this->assertEquals('No host context available', __('current_zabbix_problems.details.no_host_context'));
            $this->assertEquals('Reopened at:', __('current_zabbix_problems.ticket.reopened_at'));

            app()->setLocale('uk');
            $this->assertEquals('Поточні проблеми Zabbix успішно оновлено', __('current_zabbix_problems.notifications.refresh_success'));
            $this->assertEquals('Не вдалося оновити поточні проблеми Zabbix', __('current_zabbix_problems.notifications.refresh_failed'));
            $this->assertEquals('Помилка:', __('current_zabbix_problems.details.error'));
            $this->assertEquals('Контекст хоста відсутній', __('current_zabbix_problems.details.no_host_context'));
            $this->assertEquals('Повторно відкрито:', __('current_zabbix_problems.ticket.reopened_at'));
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_hardcoded_literals_removed_from_class_and_blade()
    {
        $classPath = app_path('Filament/Pages/CurrentZabbixProblems.php');
        $bladePath = resource_path('views/filament/pages/current-zabbix-problems.blade.php');

        $classContent = file_get_contents($classPath);
        $bladeContent = file_get_contents($bladePath);

        // Assert hard-coded strings are absent
        $this->assertStringNotContainsString("->title('Zabbix problems refreshed successfully')", $classContent);
        $this->assertStringNotContainsString("->title('Failed to refresh Zabbix problems')", $classContent);
        $this->assertStringNotContainsString('<strong>Error:</strong>', $bladeContent);
        $this->assertStringNotContainsString('No host context available', $bladeContent);
        $this->assertStringNotContainsString('<strong>Reopened at:</strong>', $bladeContent);

        // Assert dynamic values and critical bindings remain present
        $this->assertStringContainsString('{{ $error }}', $bladeContent);
        $this->assertStringContainsString('{{ $linkedTicket->manual_reopened_at->format(\'Y-m-d H:i:s\') }}', $bladeContent);

        $this->assertStringContainsString('__(\'current_zabbix_problems.details.error\')', $bladeContent);
        $this->assertStringContainsString('__(\'current_zabbix_problems.details.no_host_context\')', $bladeContent);
        $this->assertStringContainsString('__(\'current_zabbix_problems.ticket.reopened_at\')', $bladeContent);
    }
}
