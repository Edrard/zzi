<?php

namespace Tests\Feature\Filament\Pages;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

class ZnunyTicketWorkspaceLocalizationTest extends TestCase
{
    public function test_translation_file_key_parity(): void
    {
        $en = Lang::get('znuny_ticket_workspace', [], 'en');
        $uk = Lang::get('znuny_ticket_workspace', [], 'uk');

        $this->assertEqualsCanonicalizing(
            array_keys(Arr::dot($en)),
            array_keys(Arr::dot($uk)),
            'EN and UK translation files must have exact key parity.'
        );
    }

    public function test_required_new_translations_resolve_correctly(): void
    {
        $originalLocale = app()->getLocale();

        try {
            app()->setLocale('en');
            $this->assertEquals('Close Ticket', __('znuny_ticket_workspace.management_actions.close_ticket'));
            $this->assertEquals('Ticket ID missing', __('znuny_ticket_workspace.management_actions.ticket_id_missing'));
            $this->assertEquals('Reopen Ticket', __('znuny_ticket_workspace.management_actions.reopen_ticket'));
            $this->assertEquals('Change', __('znuny_ticket_workspace.management_actions.change_assignment_action'));
            $this->assertEquals('Created', __('znuny_ticket_workspace.accordion.created'));
            $this->assertEquals('Sender', __('znuny_ticket_workspace.accordion.sender'));

            app()->setLocale('uk');
            $this->assertEquals('Закрити звернення', __('znuny_ticket_workspace.management_actions.close_ticket'));
            $this->assertEquals('Відсутній ID звернення', __('znuny_ticket_workspace.management_actions.ticket_id_missing'));
            $this->assertEquals('Повторно відкрити звернення Znuny', __('znuny_ticket_workspace.management_actions.reopen_ticket'));
            $this->assertEquals('Змінити призначення', __('znuny_ticket_workspace.management_actions.change_assignment_action'));
            $this->assertEquals('Створено', __('znuny_ticket_workspace.accordion.created'));
            $this->assertEquals('Відправник', __('znuny_ticket_workspace.accordion.sender'));
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_hardcoded_literals_removed_and_critical_names_present(): void
    {
        $actionsPath = app_path('Filament/Support/ZnunyTicketManagementActions.php');
        $accordionPath = resource_path('views/filament/infolists/articles-accordion.blade.php');

        $actionsContent = file_get_contents($actionsPath);
        $accordionContent = file_get_contents($accordionPath);

        // Assert hardcoded english literals are absent
        $this->assertStringNotContainsString("->label('Close Ticket')", $actionsContent);
        $this->assertStringNotContainsString("->title('Ticket ID missing')", $actionsContent);
        $this->assertStringNotContainsString("->label('Reopen Ticket')", $actionsContent);
        $this->assertStringNotContainsString("->label('Change')", $actionsContent);
        $this->assertStringNotContainsString('>No articles found.<', $accordionContent);
        $this->assertStringNotContainsString('>Created<', $accordionContent);
        $this->assertStringNotContainsString('>Sender<', $accordionContent);

        // Assert critical action names, methods, callbacks, bindings, and raw property names remain present
        $this->assertStringContainsString('public static function closeTicketAction', $actionsContent);
        $this->assertStringContainsString('Action::make($name)', $actionsContent);
        $this->assertStringContainsString('public static function changeAssignmentAction', $actionsContent);
        $this->assertStringContainsString('Textarea::make(\'reason\')', $actionsContent);
        $this->assertStringContainsString('TicketDetailsPayload::fromRecord', $actionsContent);

        $this->assertStringContainsString('@foreach ($articles as $index => $article)', $accordionContent);
        $this->assertStringContainsString('x-data="{', $accordionContent);
        $this->assertStringContainsString('{{ app(\App\Services\Support\DateTimeDisplayService::class)->formatDateTime($article[\'created_at\']) }}', $accordionContent);
    }
}
