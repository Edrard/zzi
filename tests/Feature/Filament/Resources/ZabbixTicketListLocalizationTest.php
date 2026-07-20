<?php

namespace Tests\Feature\Filament\Resources;

use App\Filament\Resources\ZabbixTickets\Pages\ListZabbixTickets;
use App\Filament\Resources\ZabbixTickets\ZabbixTicketResource;
use App\Models\User;
use App\Support\Pagination\PaginationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use Tests\TestCase;

class ZabbixTicketListLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_translation_file_key_parity(): void
    {
        $en = Lang::get('zabbix_tickets', [], 'en');
        $uk = Lang::get('zabbix_tickets', [], 'uk');

        $this->assertEqualsCanonicalizing(
            array_keys(Arr::dot($en)),
            array_keys(Arr::dot($uk)),
            'EN and UK translation files must have exact key parity.'
        );
    }

    public function test_it_preserves_navigation_and_permissions(): void
    {
        $this->assertTrue(ZabbixTicketResource::canAccess());
        $this->assertEquals(__('navigation.groups.znuny'), ZabbixTicketResource::getNavigationGroup());
        $this->assertStringEndsWith('/admin/zabbix-tickets', ZabbixTicketResource::getUrl('index'));

        $originalLocale = App::getLocale();

        try {
            App::setLocale('uk');
            $this->assertEquals('Пов’язані звернення', ZabbixTicketResource::getNavigationLabel());
            $this->assertEquals('Пов’язане звернення', ZabbixTicketResource::getModelLabel());
            $this->assertEquals('Пов’язані звернення', ZabbixTicketResource::getPluralModelLabel());

            App::setLocale('en');
            $this->assertEquals('Linked tickets', ZabbixTicketResource::getNavigationLabel());
            $this->assertEquals('Linked ticket', ZabbixTicketResource::getModelLabel());
            $this->assertEquals('Linked tickets', ZabbixTicketResource::getPluralModelLabel());
        } finally {
            App::setLocale($originalLocale);
        }
    }

    public function test_table_headings_and_empty_state_uk(): void
    {
        $originalLocale = App::getLocale();
        App::setLocale('uk');
        try {
            Livewire::test(ListZabbixTickets::class)
                ->assertSeeHtml('Хост')
                ->assertSeeHtml('Проблема')
                ->assertSeeHtml('Стан')
                ->assertSeeHtml('Zabbix')
                ->assertSeeHtml('Вік звернення')
                ->assertSeeHtml('Синхронізувати звернення')
                ->assertSeeHtml('Немає пов’язаних звернень');
        } finally {
            App::setLocale($originalLocale);
        }
    }

    public function test_table_headings_and_empty_state_en(): void
    {
        $originalLocale = App::getLocale();
        App::setLocale('en');
        try {
            Livewire::test(ListZabbixTickets::class)
                ->assertSeeHtml('Host')
                ->assertSeeHtml('Problem')
                ->assertSeeHtml('State')
                ->assertSeeHtml('Zabbix')
                ->assertSeeHtml('Ticket age')
                ->assertSeeHtml('Sync tickets')
                ->assertSeeHtml('No linked tickets');
        } finally {
            App::setLocale($originalLocale);
        }
    }

    public function test_sync_action_mocking(): void
    {
        Artisan::shouldReceive('call')->with('znuny:sync-linked-tickets', ['--manual' => true])->once()->andReturn(0);
        Artisan::shouldReceive('call')->with('znuny:evaluate-manual-ticket-lifecycle')->once()->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');

        $originalLocale = App::getLocale();
        App::setLocale('en');
        try {
            Livewire::test(ListZabbixTickets::class)
                ->callAction('sync_tickets')
                ->assertNotified('Sync successful');
        } finally {
            App::setLocale($originalLocale);
        }
    }

    public function test_znuny_state_mappings(): void
    {
        $originalLocale = App::getLocale();
        App::setLocale('uk');
        try {
            $this->assertEquals('Нове', ZabbixTicketResource::translateZnunyState('new'));
            $this->assertEquals('Очікує нагадування', ZabbixTicketResource::translateZnunyState('pending reminder'));
            $this->assertEquals('Закрито успішно', ZabbixTicketResource::translateZnunyState('closed successful'));
            $this->assertEquals('Невідомий', ZabbixTicketResource::translateZnunyState('Невідомий'));
            $this->assertNull(ZabbixTicketResource::translateZnunyState(null));
            $this->assertSame('', ZabbixTicketResource::translateZnunyState(''));
        } finally {
            App::setLocale($originalLocale);
        }

        App::setLocale('en');
        try {
            $this->assertEquals('New', ZabbixTicketResource::translateZnunyState('new'));
            $this->assertEquals('Pending reminder', ZabbixTicketResource::translateZnunyState('pending reminder'));
            $this->assertEquals('Closed successfully', ZabbixTicketResource::translateZnunyState('closed successful'));
            $this->assertEquals('UnknownState', ZabbixTicketResource::translateZnunyState('UnknownState'));
            $this->assertNull(ZabbixTicketResource::translateZnunyState(null));
            $this->assertSame('', ZabbixTicketResource::translateZnunyState(''));
        } finally {
            App::setLocale($originalLocale);
        }
    }

    public function test_zabbix_status_mappings(): void
    {
        $activePresentation = [
            'label' => 'Active',
            'color' => 'danger',
            'icon' => 'heroicon-o-exclamation-circle',
            'tooltip' => 'Linked Zabbix problem is still active.',
        ];

        $originalLocale = App::getLocale();
        App::setLocale('uk');
        try {
            $translated = ZabbixTicketResource::translateZabbixStatus($activePresentation);
            $this->assertEquals('Активна', $translated['label']);
            $this->assertEquals('Пов’язана проблема Zabbix досі активна.', $translated['tooltip']);
            $this->assertEquals('danger', $translated['color']);

            $translated2 = ZabbixTicketResource::translateZabbixStatus(['label' => 'Ready']);
            $this->assertEquals('Готово', $translated2['label']);
        } finally {
            App::setLocale($originalLocale);
        }
    }

    public function test_table_behaviors_remain_unchanged(): void
    {
        $component = Livewire::test(ListZabbixTickets::class);
        $table = $component->instance()->getTable();

        // 20. row click and viewTicket binding remain connected
        $component->assertTableActionExists('viewTicket');

        // 21. search, sorting, pagination, and records-per-page behavior remain unchanged
        $this->assertTrue($table->getColumn('zabbix_host_name')->isSearchable());
        $this->assertTrue($table->getColumn('zabbix_host_name')->isSortable());

        $this->assertEquals(app(PaginationSettings::class)->defaultPerPage(), $table->getDefaultPaginationPageOption());
        $this->assertEquals('created_at', $table->getDefaultSortColumn());
        $this->assertEquals('desc', $table->getDefaultSortDirection());
    }
}
