<?php

namespace Tests\Feature\Filament\Support;

use App\Filament\Pages\CurrentZabbixProblems;
use App\Filament\Support\ZnunyTicketManagementActions;
use App\Models\Setting;
use App\Models\User;
use App\Models\ZabbixTicket;
use App\Services\SettingsService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyTicketArticleWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class ZnunyTicketArticleSendToCustomerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ZabbixTicket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::updateOrCreate(['key' => 'znuny_api_url'], ['value' => 'https://example.invalid/api']);
        Setting::updateOrCreate(['key' => 'znuny_username'], ['value' => 'agent']);
        Setting::updateOrCreate(['key' => 'znuny_password'], ['value' => app(SettingsService::class)->encryptForStorage('znuny_password', 'secret'), 'type' => 'string']);

        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->ticket = ZabbixTicket::create([
            'zabbix_event_id' => '9001',
            'zabbix_host_id' => '8001',
            'zabbix_host_name' => 'HostTest',
            'zabbix_severity' => 4,
            'zabbix_trigger_id' => '8001',
            'zabbix_problem_name' => 'HostTest problem',
            'znuny_ticket_id' => 60001,
            'znuny_ticket_number' => '9001',
            'znuny_ticket_state_type' => 'open',
            'manual_lifecycle_status' => 'active',
        ]);
    }

    public function test_form_has_no_kind_field_and_footer_buttons_are_authority()
    {
        config(['znuny.article_send_to_customer_default' => true]);

        Livewire::actingAs($this->admin)
            ->test(CurrentZabbixProblems::class)
            ->mountAction('viewTicket', ['zabbix_ticket_id' => $this->ticket->id])
            ->mountAction('add_note_or_article', ['zabbix_ticket_id' => $this->ticket->id])
            ->assertActionDataSet([
                'subject' => '',
                'body' => '',
                'send_to_customer' => true,
            ]);

        $action = ZnunyTicketManagementActions::addNoteOrArticleAction('add_note_or_article');
        $property = new \ReflectionProperty($action, 'schema');
        $property->setAccessible(true);
        $schema = $property->getValue($action);

        $componentNames = array_map(fn ($component) => $component->getName(), $schema);

        $this->assertNotContains('kind', $componentNames, 'Form should not contain kind selector');
        $this->assertContains('subject', $componentNames);
        $this->assertContains('body', $componentNames);
        $this->assertContains('send_to_customer', $componentNames);
    }

    public function test_config_default_true_sets_checkbox_initially_on()
    {
        config(['znuny.article_send_to_customer_default' => true]);

        Livewire::actingAs($this->admin)
            ->test(CurrentZabbixProblems::class)
            ->mountAction('viewTicket', ['zabbix_ticket_id' => $this->ticket->id])
            ->mountAction('add_note_or_article', ['zabbix_ticket_id' => $this->ticket->id])
            ->assertActionDataSet([
                'send_to_customer' => true,
            ]);
    }

    public function test_config_default_false_sets_checkbox_initially_off()
    {
        config(['znuny.article_send_to_customer_default' => false]);

        Livewire::actingAs($this->admin)
            ->test(CurrentZabbixProblems::class)
            ->mountAction('viewTicket', ['zabbix_ticket_id' => $this->ticket->id])
            ->mountAction('add_note_or_article', ['zabbix_ticket_id' => $this->ticket->id])
            ->assertActionDataSet([
                'send_to_customer' => false,
            ]);
    }

    public function test_operator_can_toggle_checkbox_away_from_default()
    {
        config(['znuny.article_send_to_customer_default' => true]);

        $serviceMock = $this->mock(ZnunyTicketArticleWriteService::class);
        $serviceMock->shouldReceive('createTicketArticle')
            ->once()
            ->with('60001', 'Test Reply', 'Test Body', true, false)
            ->andReturn([
                'success' => true,
                'article_id' => 999,
                'ticket_id' => 60001,
            ]);

        Livewire::actingAs($this->admin)
            ->test(CurrentZabbixProblems::class)
            ->mountAction('viewTicket', ['zabbix_ticket_id' => $this->ticket->id])
            ->mountAction('add_note_or_article', ['zabbix_ticket_id' => $this->ticket->id])
            ->setActionData([
                'send_to_customer' => false,
                'subject' => 'Test Reply',
                'body' => 'Test Body',
            ])
            ->callMountedAction(['visible_for_customer' => true])
            ->assertHasNoActionErrors()
            ->assertNotified();
    }

    public function test_create_article_with_on_sends_send_to_customer_true()
    {
        config(['znuny.article_send_to_customer_default' => false]);

        $serviceMock = $this->mock(ZnunyTicketArticleWriteService::class);
        $serviceMock->shouldReceive('createTicketArticle')
            ->once()
            ->with('60001', 'Customer Reply', 'Test Body', true, true)
            ->andReturn([
                'success' => true,
                'article_id' => 1000,
                'ticket_id' => 60001,
            ]);

        Livewire::actingAs($this->admin)
            ->test(CurrentZabbixProblems::class)
            ->mountAction('viewTicket', ['zabbix_ticket_id' => $this->ticket->id])
            ->mountAction('add_note_or_article', ['zabbix_ticket_id' => $this->ticket->id])
            ->setActionData([
                'send_to_customer' => true,
                'subject' => 'Customer Reply',
                'body' => 'Test Body',
            ])
            ->callMountedAction(['visible_for_customer' => true])
            ->assertHasNoActionErrors()
            ->assertNotified();
    }

    public function test_create_note_ignores_checkbox_even_when_true_and_calls_four_arguments()
    {
        $serviceMock = $this->mock(ZnunyTicketArticleWriteService::class);
        $serviceMock->shouldReceive('createTicketArticle')
            ->once()
            ->with('60001', 'Internal Note', 'Internal Body', false)
            ->andReturn([
                'success' => true,
                'article_id' => 998,
                'ticket_id' => 60001,
            ]);

        Livewire::actingAs($this->admin)
            ->test(CurrentZabbixProblems::class)
            ->mountAction('viewTicket', ['zabbix_ticket_id' => $this->ticket->id])
            ->mountAction('add_note_or_article', ['zabbix_ticket_id' => $this->ticket->id])
            ->setActionData([
                'send_to_customer' => true,
                'subject' => 'Internal Note',
                'body' => 'Internal Body',
            ])
            ->callMountedAction(['visible_for_customer' => false])
            ->assertHasNoActionErrors()
            ->assertNotified();
    }

    public function test_reply_with_on_sends_send_to_customer_1_to_ticket_article()
    {
        Mail::fake();

        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/TicketArticle*' => Http::response([
                'ArticleID' => 1234,
                'TicketID' => 60001,
                'TicketNumber' => '9001',
                'SendRequested' => 1,
                'QueueStatus' => 'queued',
            ], 200),
        ]);

        $client = app(ZnunyClient::class);
        $response = $client->createTicketArticle(60001, 'Customer Reply', 'Hello customer', true, true);

        $this->assertTrue($response['success']);
        $this->assertEquals(1234, $response['article_id']);
        $this->assertEquals(60001, $response['ticket_id']);
        $this->assertTrue($response['send_requested']);
        $this->assertEquals('queued', $response['queue_status']);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' &&
                str_contains($request->url(), 'api/TicketArticle') &&
                $request['Kind'] === 'reply' &&
                $request['SendToCustomer'] === 1 &&
                $request['Subject'] === 'Customer Reply' &&
                $request['Body'] === 'Hello customer';
        });

        // Exactly one HTTP request to create the article, no second article or direct email
        Http::assertSentCount(2); // 1 session + 1 TicketArticle
        Mail::assertNothingSent();
    }

    public function test_reply_with_off_sends_send_to_customer_0_to_ticket_article()
    {
        Mail::fake();

        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/TicketArticle*' => Http::response([
                'ArticleID' => 1235,
                'TicketID' => 60001,
                'TicketNumber' => '9001',
            ], 200),
        ]);

        $client = app(ZnunyClient::class);
        $response = $client->createTicketArticle(60001, 'Customer Reply', 'Hello customer', true, false);

        $this->assertTrue($response['success']);
        $this->assertEquals(1235, $response['article_id']);
        $this->assertEquals(60001, $response['ticket_id']);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' &&
                str_contains($request->url(), 'api/TicketArticle') &&
                $request['Kind'] === 'reply' &&
                $request['SendToCustomer'] === 0 &&
                $request['Subject'] === 'Customer Reply' &&
                $request['Body'] === 'Hello customer';
        });

        Mail::assertNothingSent();
    }

    public function test_internal_note_client_call_omits_send_to_customer()
    {
        Mail::fake();

        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/TicketArticle*' => Http::response([
                'ArticleID' => 1237,
                'TicketID' => 60001,
                'TicketNumber' => '9001',
            ], 200),
        ]);

        $client = app(ZnunyClient::class);
        $response = $client->createTicketArticle(60001, 'Internal Note', 'Private note', false);

        $this->assertTrue($response['success']);
        $this->assertEquals(1237, $response['article_id']);
        $this->assertEquals(60001, $response['ticket_id']);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' &&
                str_contains($request->url(), 'api/TicketArticle') &&
                $request['Kind'] === 'internal_note' &&
                ! isset($request['SendToCustomer']) &&
                $request['Subject'] === 'Internal Note' &&
                $request['Body'] === 'Private note';
        });

        Mail::assertNothingSent();
    }

    public function test_existing_article_creation_4_arguments_still_works()
    {
        Http::fake([
            'https://example.invalid/api/Session*' => Http::response(['SessionID' => 'fake_session'], 200),
            'https://example.invalid/api/TicketArticle*' => Http::response([
                'ArticleID' => 1236,
                'TicketID' => 60001,
                'TicketNumber' => '9001',
            ], 200),
        ]);

        $service = app(ZnunyTicketArticleWriteService::class);
        $result = $service->createTicketArticle(60001, 'Legacy Call', 'Legacy Body', true);

        $this->assertTrue($result['success']);
        $this->assertEquals(1236, $result['article_id']);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' &&
                str_contains($request->url(), 'api/TicketArticle') &&
                $request['Kind'] === 'reply' &&
                $request['SendToCustomer'] === 0;
        });
    }
}
