<?php

namespace Tests\Feature\Filament\Pages;

use App\Filament\Pages\ZnunyTicketWorkspace;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\Znuny\ClosedTicketSyncService;
use App\Services\Znuny\ZnunyTicketCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Tests\TestCase;

class ZnunyTicketWorkspaceLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::flushall();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    protected function seedTicket(array $ticketOverrides): void
    {
        $ticket = array_merge([
            'TicketID' => 1,
            'TicketNumber' => '100000000',
            'Title' => 'Default',
            'QueueID' => 1,
            'Queue' => 'Raw',
            'OwnerID' => 1,
            'Owner' => 'Admin',
            'StateID' => 1,
            'State' => 'new',
            'StateType' => 'new',
            'PriorityID' => 1,
            'Priority' => '3 normal',
            'TypeID' => 1,
            'Type' => 'Unclassified',
            'Changed' => now()->toDateTimeString(),
            'Created' => now()->subDay()->toDateTimeString(),
            'ArticleCount' => 1,
        ], $ticketOverrides);

        app(ZnunyTicketCacheService::class)->upsertOrRefreshFromSearchResult($ticket);
    }

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

    public function test_it_preserves_navigation_and_permissions(): void
    {
        $this->assertTrue(ZnunyTicketWorkspace::canAccess());

        $originalLocale = App::getLocale();

        try {
            App::setLocale('uk');
            $this->assertEquals('Робоча область звернень', ZnunyTicketWorkspace::getNavigationLabel());
            $this->assertEquals('Робоча область звернень', (new ZnunyTicketWorkspace)->getTitle());

            App::setLocale('en');
            $this->assertEquals('Ticket workspace', ZnunyTicketWorkspace::getNavigationLabel());
            $this->assertEquals('Ticket workspace', (new ZnunyTicketWorkspace)->getTitle());
        } finally {
            App::setLocale($originalLocale);
        }
    }

    public function test_workspace_empty_state_uk(): void
    {
        $originalLocale = App::getLocale();
        App::setLocale('uk');
        try {
            Livewire::actingAs(auth()->user())->test(ZnunyTicketWorkspace::class)
                ->set('stateTypeFilter', [])
                ->assertSeeHtml('Робоча область звернень')
                ->assertSeeHtml('Звернень не знайдено')
                ->assertDontSeeHtml('Немає звернень, що відповідають вибраним фільтрам.')
                ->assertSeeHtml('Запустіть розігрів кешу робочої області звернень.');

            $this->seedTicket(['TicketID' => 101]);
            Livewire::actingAs(auth()->user())->test(ZnunyTicketWorkspace::class)
                ->set('stateTypeFilter', ['closed'])
                ->assertSeeHtml('Немає звернень, що відповідають вибраним фільтрам.')
                ->assertDontSeeHtml('Звернень не знайдено');
        } finally {
            App::setLocale($originalLocale);
        }
    }

    public function test_workspace_table_headers_uk(): void
    {
        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'Test Ticket']);
        $originalLocale = App::getLocale();
        App::setLocale('uk');
        try {
            Livewire::actingAs(auth()->user())->test(ZnunyTicketWorkspace::class)
                ->set('stateTypeFilter', ['new'])
                ->assertSeeHtml('Номер звернення')
                ->assertSeeHtml('Назва')
                ->assertSeeHtml('Черга')
                ->assertSeeHtml('Власник')
                ->assertSeeHtml('Стан / тип')
                ->assertSeeHtml('Пріоритет')
                ->assertSeeHtml('Повідомлення')
                ->assertSeeHtml('Змінено');
        } finally {
            App::setLocale($originalLocale);
        }
    }

    public function test_workspace_empty_state_en(): void
    {
        $originalLocale = App::getLocale();
        App::setLocale('en');
        try {
            Livewire::actingAs(auth()->user())->test(ZnunyTicketWorkspace::class)
                ->set('stateTypeFilter', [])
                ->assertSeeHtml('Ticket workspace')
                ->assertSeeHtml('No tickets found')
                ->assertDontSeeHtml('No tickets match the selected filters.')
                ->assertSeeHtml('Run the Ticket Workspace cache warmer.');

            $this->seedTicket(['TicketID' => 101]);
            Livewire::actingAs(auth()->user())->test(ZnunyTicketWorkspace::class)
                ->set('stateTypeFilter', ['closed'])
                ->assertSeeHtml('No tickets match the selected filters.')
                ->assertDontSeeHtml('No tickets found');
        } finally {
            App::setLocale($originalLocale);
        }
    }

    public function test_workspace_table_headers_en(): void
    {
        $this->seedTicket(['TicketID' => 101, 'TicketNumber' => 'TN101', 'Title' => 'Test Ticket']);
        $originalLocale = App::getLocale();
        App::setLocale('en');
        try {
            Livewire::actingAs(auth()->user())->test(ZnunyTicketWorkspace::class)
                ->set('stateTypeFilter', ['new'])
                ->assertSeeHtml('Ticket number')
                ->assertSeeHtml('Title')
                ->assertSeeHtml('Queue')
                ->assertSeeHtml('Owner')
                ->assertSeeHtml('State / type')
                ->assertSeeHtml('Priority')
                ->assertSeeHtml('Articles')
                ->assertSeeHtml('Changed');
        } finally {
            App::setLocale($originalLocale);
        }
    }

    public function test_refresh_action_mocking(): void
    {
        Setting::updateOrCreate(['key' => 'znuny_ticket_workspace_enabled'], ['value' => 'true']);
        SettingsService::clearAllCaches();

        Artisan::shouldReceive('call')->with('znuny:warm-ticket-workspace-cache', ['--manual' => true])->once()->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');

        $mock = \Mockery::mock(ClosedTicketSyncService::class);
        $mock->shouldReceive('syncManual')->once()->andReturn([
            'mode' => 'manual',
            'effective_mode' => 'small',
            'fetched_count' => 10,
            'cached_count' => 10,
        ]);
        $this->app->instance(ClosedTicketSyncService::class, $mock);

        $originalLocale = App::getLocale();
        App::setLocale('en');
        try {
            $component = Livewire::actingAs(auth()->user())->test(ZnunyTicketWorkspace::class)
                ->call('refreshFromZnuny');

            $component->assertNotified('Ticket Workspace refreshed successfully');
        } finally {
            App::setLocale($originalLocale);
        }
    }

    public function test_blade_row_presentation_uses_translations(): void
    {
        $this->seedTicket([
            'TicketID' => 101,
            'TicketNumber' => 'TN101',
            'Title' => 'Test Ticket',
            'State' => 'new',
            'StateType' => 'new',
            'Priority' => '3 normal',
            'CustomerUserID' => 'client@example.com',
        ]);

        $originalLocale = App::getLocale();
        App::setLocale('uk');
        try {
            Livewire::actingAs(auth()->user())->test(ZnunyTicketWorkspace::class)
                ->set('stateTypeFilter', ['new'])
                ->assertSeeHtml('Клієнт: client@example.com')
                ->assertSeeHtml('Нове') // zabbix_tickets state translation
                ->assertSeeHtml('3 нормальний') // create_ticket priority translation
                ->assertSeeHtml('(Нові)'); // filter type translation
        } finally {
            App::setLocale($originalLocale);
        }
    }

    public function test_workspace_polling_interval(): void
    {
        Livewire::actingAs(auth()->user())->test(ZnunyTicketWorkspace::class)
            ->assertSeeHtml('wire:poll.60s');
    }

    public function test_workspace_filter_and_pagination_raw_values(): void
    {
        Livewire::actingAs(auth()->user())->test(ZnunyTicketWorkspace::class)
            ->assertSeeHtml('value="all"')
            ->assertSeeHtml('value="linked"')
            ->assertSeeHtml('value="linked_active"')
            ->assertSeeHtml('value="linked_resolved"')
            ->assertSeeHtml('value="unlinked"')
            ->assertSeeHtml('value="50"')
            ->assertSeeHtml('value="100"')
            ->assertSeeHtml('value="200"')
            ->assertSeeHtml('value="300"');
    }
}
