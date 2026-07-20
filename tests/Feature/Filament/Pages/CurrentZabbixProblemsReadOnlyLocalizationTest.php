<?php

namespace Tests\Feature\Filament\Pages;

use App\Filament\Pages\CurrentZabbixProblems;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CurrentZabbixProblemsReadOnlyLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_titles_and_navigation_are_localized()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $originalLocale = app()->getLocale();

        try {
            // EN
            app()->setLocale('en');
            $this->assertEquals('Current problems', CurrentZabbixProblems::getNavigationLabel());
            $manageEn = Livewire::actingAs($admin)->test(CurrentZabbixProblems::class);
            $this->assertEquals('Current Zabbix problems', $manageEn->instance()->getTitle());

            // UK
            app()->setLocale('uk');
            $this->assertEquals('Поточні проблеми', CurrentZabbixProblems::getNavigationLabel());
            $manageUk = Livewire::actingAs($admin)->test(CurrentZabbixProblems::class);
            $this->assertEquals('Поточні проблеми Zabbix', $manageUk->instance()->getTitle());
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_page_shell_is_localized()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $originalLocale = app()->getLocale();

        try {
            app()->setLocale('uk');
            $component = Livewire::actingAs($admin)->test(CurrentZabbixProblems::class);

            // Test basic translation strings in view
            $component->assertSeeHtml('Оновити із Zabbix');
            $component->assertSeeHtml('Стан опитування');
            $component->assertSeeHtml('Поточні проблеми');
            $component->assertSeeHtml('Усього виключено');

            // Test table headers
            $component->assertSeeHtml('Важливість');
            $component->assertSeeHtml('Хост');
            $component->assertSeeHtml('Проблема');
            $component->assertSeeHtml('Тривалість');

            // Test presets
            $component->assertSeeHtml('Висока');
            $component->assertSeeHtml('Попередження');
            $component->assertSeeHtml('Інформація');
            $component->assertSeeHtml('На повторне відкриття');

            // Test legend
            $component->assertSeeHtml('Позначення значків');
            $component->assertSeeHtml('Пов’язане звернення');

            // Test EN
            app()->setLocale('en');
            $componentEn = Livewire::actingAs($admin)->test(CurrentZabbixProblems::class);

            $componentEn->assertSeeHtml('Refresh from Zabbix');
            $componentEn->assertSeeHtml('Polling status');
            $componentEn->assertSeeHtml('Current problems');
            $componentEn->assertSeeHtml('Excluded total');

            $componentEn->assertSeeHtml('Severity');
            $componentEn->assertSeeHtml('Host');
            $componentEn->assertSeeHtml('Problem');
            $componentEn->assertSeeHtml('Age');

            $componentEn->assertSeeHtml('High');
            $componentEn->assertSeeHtml('Warning');
            $componentEn->assertSeeHtml('Information');
            $componentEn->assertSeeHtml('Reopen');

            $componentEn->assertSeeHtml('Icon legend');
            $componentEn->assertSeeHtml('Linked ticket');
        } finally {
            app()->setLocale($originalLocale);
        }
    }
}
