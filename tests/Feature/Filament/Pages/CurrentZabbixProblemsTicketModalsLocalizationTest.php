<?php

namespace Tests\Feature\Filament\Pages;

use Illuminate\Support\Arr;
use Tests\TestCase;

class CurrentZabbixProblemsTicketModalsLocalizationTest extends TestCase
{
    public function test_translation_arrays_have_parity_and_no_dotted_keys()
    {
        $en = require lang_path('en/current_zabbix_problems.php');
        $uk = require lang_path('uk/current_zabbix_problems.php');

        $enKeys = array_keys(Arr::dot($en));
        $ukKeys = array_keys(Arr::dot($uk));

        sort($enKeys);
        sort($ukKeys);

        $this->assertEquals($enKeys, $ukKeys, 'EN and UK translation keys must match exactly.');

        foreach (array_keys($en) as $key) {
            $this->assertStringNotContainsString('.', $key, "Literal dotted array keys are not allowed in translation file: {$key}");
        }
        foreach (array_keys($uk) as $key) {
            $this->assertStringNotContainsString('.', $key, "Literal dotted array keys are not allowed in translation file: {$key}");
        }
    }

    public function test_blade_file_contains_required_modals_and_localization()
    {
        $bladePath = resource_path('views/filament/pages/current-zabbix-problems.blade.php');
        $bladeContent = file_get_contents($bladePath);

        // Assert modal IDs
        $this->assertStringContainsString('id="create-ticket-modal"', $bladeContent);
        $this->assertStringContainsString('id="edit-ticket-text-modal"', $bladeContent);

        // Assert translation calls are present
        $this->assertStringContainsString("__('current_zabbix_problems.modals.create_ticket.heading')", $bladeContent);
        $this->assertStringContainsString("__('current_zabbix_problems.modals.edit_text.heading')", $bladeContent);

        // Assert critical bindings and methods remain
        $methods = [
            'closeCreateTicketModal',
            'openEditTicketTextModal',
            'createZnunyTicket',
            'closeEditTicketTextModal',
            'resetTicketText',
            'saveTicketText',
        ];

        foreach ($methods as $method) {
            $this->assertStringContainsString($method, $bladeContent, "Method/binding '{$method}' must remain in the Blade file.");
        }
    }

    public function test_php_class_contains_raw_properties()
    {
        $classPath = app_path('Filament/Pages/CurrentZabbixProblems.php');
        $classContent = file_get_contents($classPath);

        $properties = [
            'ticketTextTitle',
            'ticketTextArticleSubject',
            'ticketTextArticleBody',
            'generatedTicketTextTitle',
            'generatedTicketTextArticleBody',
        ];

        foreach ($properties as $prop) {
            $this->assertStringContainsString($prop, $classContent, "Property '{$prop}' must remain in the class file.");
        }
    }

    public function test_core_modal_labels_resolve_correctly_in_en_and_uk()
    {
        $originalLocale = app()->getLocale();

        try {
            app()->setLocale('en');
            $this->assertEquals('Create Znuny ticket', __('current_zabbix_problems.modals.create_ticket.heading'));
            $this->assertEquals('Edit ticket text', __('current_zabbix_problems.modals.edit_text.heading'));
            $this->assertEquals('Creating...', __('current_zabbix_problems.modals.create_ticket.creating'));

            app()->setLocale('uk');
            $this->assertEquals('Створити звернення Znuny', __('current_zabbix_problems.modals.create_ticket.heading'));
            $this->assertEquals('Редагувати текст звернення', __('current_zabbix_problems.modals.edit_text.heading'));
            $this->assertEquals('Створення...', __('current_zabbix_problems.modals.create_ticket.creating'));
        } finally {
            app()->setLocale($originalLocale);
        }
    }
}
