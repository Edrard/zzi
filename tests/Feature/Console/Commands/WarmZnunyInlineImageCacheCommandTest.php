<?php

namespace Tests\Feature\Console\Commands;

use App\Models\Setting;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyInlineImageService;
use App\Services\Znuny\ZnunyTicketWorkspaceCacheReader;
use App\Services\Znuny\ZnunyTicketWorkspaceStateTypeMapper;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class WarmZnunyInlineImageCacheCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::flushDB();
    }

    private function enableWarmer(array $stateIds = ['open']): void
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_inline_image_warmer_enabled'],
            ['value' => '1', 'type' => 'boolean']
        );
        Setting::updateOrCreate(
            ['key' => 'znuny_ticket_workspace_active_state_type_ids'],
            ['value' => json_encode($stateIds), 'type' => 'json']
        );
    }

    private function bindMapper(array $mapped = ['open']): void
    {
        $mapper = Mockery::mock(ZnunyTicketWorkspaceStateTypeMapper::class);
        $mapper->shouldReceive('mapInternalIdsToZnunyTypes')->andReturn($mapped);
        $this->app->instance(ZnunyTicketWorkspaceStateTypeMapper::class, $mapper);
    }

    public function test_disabled_command_exits_without_warmer_work(): void
    {
        Setting::updateOrCreate(
            ['key' => 'znuny_inline_image_warmer_enabled'],
            ['value' => '0', 'type' => 'boolean']
        );

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldNotReceive('searchTicketsWithMetadata');
        $client->shouldNotReceive('getTicketInlineAttachmentReferences');
        $this->app->instance(ZnunyClient::class, $client);

        $reader = Mockery::mock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->shouldNotReceive('getTickets');
        $this->app->instance(ZnunyTicketWorkspaceCacheReader::class, $reader);

        $this->artisan('znuny:warm-inline-image-cache')
            ->expectsOutput('Inline image warmer is disabled in settings. Exiting cleanly.')
            ->assertSuccessful();

        $this->assertNull(Redis::get('znuny:inline_image_warmer:last_run_at'));
        $this->assertNull(Redis::get('znuny:inline_image_warmer:tail_offset'));
    }

    public function test_scheduled_command_not_due_does_no_warmer_work(): void
    {
        $this->enableWarmer();
        Setting::updateOrCreate(
            ['key' => 'znuny_inline_image_warmer_interval_minutes'],
            ['value' => '5', 'type' => 'integer']
        );

        $marker = Carbon::now()->subMinute()->timestamp;
        Redis::set('znuny:inline_image_warmer:last_run_at', $marker);

        $reader = Mockery::mock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->shouldNotReceive('getTickets');
        $this->app->instance(ZnunyTicketWorkspaceCacheReader::class, $reader);

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldNotReceive('searchTicketsWithMetadata');
        $this->app->instance(ZnunyClient::class, $client);

        $this->artisan('znuny:warm-inline-image-cache', ['--scheduled' => true])
            ->expectsOutput('Scheduled warmer is not due yet. Exiting cleanly.')
            ->assertSuccessful();

        $this->assertSame((string) $marker, (string) Redis::get('znuny:inline_image_warmer:last_run_at'));
    }

    public function test_scheduled_due_zero_active_runs_cycle_and_updates_marker(): void
    {
        $this->enableWarmer();
        $this->bindMapper();

        Setting::updateOrCreate(
            ['key' => 'znuny_inline_image_warmer_interval_minutes'],
            ['value' => '5', 'type' => 'integer']
        );

        $oldMarker = Carbon::now()->subMinutes(6)->timestamp;
        Redis::set('znuny:inline_image_warmer:last_run_at', $oldMarker);

        $reader = Mockery::mock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->shouldReceive('getTickets')
            ->once()
            ->with(['state_types' => ['open']])
            ->andReturn([]);
        $this->app->instance(ZnunyTicketWorkspaceCacheReader::class, $reader);

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldNotReceive('searchTicketsWithMetadata');
        $this->app->instance(ZnunyClient::class, $client);

        $this->artisan('znuny:warm-inline-image-cache', ['--scheduled' => true])
            ->expectsOutput('Starting Znuny inline image cache warmer...')
            ->expectsOutput('Completed warmer cycle:')
            ->expectsOutput('- Total active tickets: 0')
            ->assertSuccessful();

        $this->assertGreaterThan($oldMarker, (int) Redis::get('znuny:inline_image_warmer:last_run_at'));
    }

    public function test_manual_run_ignores_recent_interval_marker(): void
    {
        $this->enableWarmer();
        $this->bindMapper();

        $recent = Carbon::now()->timestamp;
        Redis::set('znuny:inline_image_warmer:last_run_at', $recent);

        $reader = Mockery::mock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->shouldReceive('getTickets')
            ->once()
            ->with(['state_types' => ['open']])
            ->andReturn([]);
        $this->app->instance(ZnunyTicketWorkspaceCacheReader::class, $reader);

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldNotReceive('searchTicketsWithMetadata');
        $this->app->instance(ZnunyClient::class, $client);

        $this->artisan('znuny:warm-inline-image-cache')
            ->expectsOutput('Starting Znuny inline image cache warmer...')
            ->expectsOutput('Completed warmer cycle:')
            ->assertSuccessful();

        $this->assertGreaterThanOrEqual($recent, (int) Redis::get('znuny:inline_image_warmer:last_run_at'));
    }

    public function test_scheduler_registers_every_minute_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'znuny:warm-inline-image-cache --scheduled'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_config_50_10_produces_five_hot_and_forty_five_tail_slots(): void
    {
        $this->enableWarmer();
        $this->bindMapper();
        config()->set('znuny.inline_image_warmer_batch_size', 50);
        config()->set('znuny.inline_image_warmer_hot_percentage', 10);

        $tickets = [];
        for ($i = 0; $i < 100; $i++) {
            $tickets[] = ['TicketID' => $i + 1, 'InlineAttachmentCount' => 0]; // No attachments, so 0 eligible
        }

        $reader = Mockery::mock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->shouldReceive('getTickets')
            ->once()
            ->with(['state_types' => ['open']])
            ->andReturn($tickets);
        $this->app->instance(ZnunyTicketWorkspaceCacheReader::class, $reader);

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldNotReceive('searchTicketsWithMetadata');
        $client->shouldNotReceive('getTicketInlineAttachmentReferences');
        $this->app->instance(ZnunyClient::class, $client);

        $this->artisan('znuny:warm-inline-image-cache')
            ->expectsOutput('- Hot slots max: 5')
            ->expectsOutput('- Tail slots max: 45')
            ->expectsOutput('- Selected unique tickets: 0')
            ->assertSuccessful();
    }

    public function test_batch_50_selects_all_25_eligible_tickets_from_138_local_active_tickets(): void
    {
        $this->enableWarmer();
        $this->bindMapper();
        config()->set('znuny.inline_image_warmer_batch_size', 50);
        config()->set('znuny.inline_image_warmer_hot_percentage', 10);

        $tickets = [];
        for ($id = 1; $id <= 138; $id++) {
            $tickets[] = [
                'TicketID' => $id,
                'InlineAttachmentCount' => $id <= 25 ? 1 : 0,
            ];
        }

        $reader = Mockery::mock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->shouldReceive('getTickets')
            ->once()
            ->with(['state_types' => ['open']])
            ->andReturn($tickets);
        $this->app->instance(ZnunyTicketWorkspaceCacheReader::class, $reader);

        $selectedTicketIds = [];

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldNotReceive('searchTicketsWithMetadata');
        $client->shouldReceive('getTicketInlineAttachmentReferences')
            ->times(25)
            ->andReturnUsing(function (int $ticketId) use (&$selectedTicketIds): array {
                $selectedTicketIds[] = $ticketId;

                return [];
            });
        $this->app->instance(ZnunyClient::class, $client);

        $this->artisan('znuny:warm-inline-image-cache')
            ->expectsOutput('- Total active tickets: 138')
            ->expectsOutput('- Hot slots max: 5')
            ->expectsOutput('- Tail slots max: 45')
            ->expectsOutput('- Selected unique tickets: 25')
            ->assertSuccessful();

        sort($selectedTicketIds);
        $this->assertSame(range(1, 25), $selectedTicketIds);
        $this->assertSame('0', (string) Redis::get('znuny:inline_image_warmer:tail_offset'));
    }

    public function test_batch_50_never_selects_more_than_50_eligible_tickets(): void
    {
        $this->enableWarmer();
        $this->bindMapper();
        config()->set('znuny.inline_image_warmer_batch_size', 50);
        config()->set('znuny.inline_image_warmer_hot_percentage', 10);

        $tickets = [];
        for ($id = 1; $id <= 80; $id++) {
            $tickets[] = [
                'TicketID' => $id,
                'InlineAttachmentCount' => 1,
            ];
        }

        $reader = Mockery::mock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->shouldReceive('getTickets')->once()->andReturn($tickets);
        $this->app->instance(ZnunyTicketWorkspaceCacheReader::class, $reader);

        $selectedTicketIds = [];

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldNotReceive('searchTicketsWithMetadata');
        $client->shouldReceive('getTicketInlineAttachmentReferences')
            ->times(50)
            ->andReturnUsing(function (int $ticketId) use (&$selectedTicketIds): array {
                $selectedTicketIds[] = $ticketId;

                return [];
            });
        $this->app->instance(ZnunyClient::class, $client);

        $this->artisan('znuny:warm-inline-image-cache')
            ->expectsOutput('- Selected unique tickets: 50')
            ->assertSuccessful();

        $this->assertCount(50, array_unique($selectedTicketIds));
        $this->assertSame('45', (string) Redis::get('znuny:inline_image_warmer:tail_offset'));
    }

    public function test_batch_one_and_hot_hundred_have_no_tail(): void
    {
        $this->enableWarmer();
        $this->bindMapper();
        config()->set('znuny.inline_image_warmer_batch_size', 1);
        config()->set('znuny.inline_image_warmer_hot_percentage', 100);

        $tickets = [];
        for ($i = 0; $i < 10; $i++) {
            $tickets[] = ['TicketID' => $i + 1, 'InlineAttachmentCount' => 1];
        }

        $reader = Mockery::mock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->shouldReceive('getTickets')
            ->once()
            ->with(['state_types' => ['open']])
            ->andReturn($tickets);
        $this->app->instance(ZnunyTicketWorkspaceCacheReader::class, $reader);

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldReceive('getTicketInlineAttachmentReferences')
            ->once() // only 1 ticket selected
            ->andReturn([]);
        $client->shouldNotReceive('searchTicketsWithMetadata');
        $this->app->instance(ZnunyClient::class, $client);

        $this->artisan('znuny:warm-inline-image-cache')
            ->expectsOutput('- Hot slots max: 1')
            ->expectsOutput('- Tail slots max: 0')
            ->assertSuccessful();

        $this->assertNull(Redis::get('znuny:inline_image_warmer:tail_offset'));
    }

    public function test_filters_non_positive_counts_and_caps_selection_to_batch(): void
    {
        $this->enableWarmer();
        $this->bindMapper();
        config()->set('znuny.inline_image_warmer_batch_size', 10);
        config()->set('znuny.inline_image_warmer_hot_percentage', 20); // 2 hot, 8 tail

        $tickets = [
            ['TicketID' => 1, 'InlineAttachmentCount' => 1],
            ['TicketID' => 2, 'InlineAttachmentCount' => 0],
            ['TicketID' => 3, 'InlineAttachmentCount' => null],
            ['TicketID' => 4],
            ['TicketID' => 5, 'InlineAttachmentCount' => -1],
        ];

        for ($i = 10; $i <= 20; $i++) { // 11 more eligible
            $tickets[] = ['TicketID' => $i, 'InlineAttachmentCount' => 1];
        }

        $reader = Mockery::mock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->shouldReceive('getTickets')->once()->andReturn($tickets);
        $this->app->instance(ZnunyTicketWorkspaceCacheReader::class, $reader);

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldNotReceive('searchTicketsWithMetadata');
        $client->shouldReceive('getTicketInlineAttachmentReferences')->times(10)->andReturn([]);
        $this->app->instance(ZnunyClient::class, $client);

        $this->artisan('znuny:warm-inline-image-cache')
            ->expectsOutput('- Hot slots max: 2')
            ->expectsOutput('- Tail slots max: 8')
            ->expectsOutput('- Selected unique tickets: 10')
            ->assertSuccessful();

        $this->assertSame('8', (string) Redis::get('znuny:inline_image_warmer:tail_offset'));
    }

    public function test_hot_and_tail_are_deduplicated_by_ticket_id(): void
    {
        $this->enableWarmer();
        $this->bindMapper();
        config()->set('znuny.inline_image_warmer_batch_size', 4);
        config()->set('znuny.inline_image_warmer_hot_percentage', 50); // 2 hot, 2 tail

        $tickets = [
            ['TicketID' => 1, 'InlineAttachmentCount' => 1],
            ['TicketID' => 2, 'InlineAttachmentCount' => 1],
            ['TicketID' => 3, 'InlineAttachmentCount' => 1],
            ['TicketID' => 4, 'InlineAttachmentCount' => 1],
            ['TicketID' => 5, 'InlineAttachmentCount' => 1],
            ['TicketID' => 6, 'InlineAttachmentCount' => 1],
        ]; // Total eligible = 6. > 4.

        Redis::set('znuny:inline_image_warmer:tail_offset', 0);

        $reader = Mockery::mock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->shouldReceive('getTickets')->once()->andReturn($tickets);
        $this->app->instance(ZnunyTicketWorkspaceCacheReader::class, $reader);

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldNotReceive('searchTicketsWithMetadata');
        $client->shouldReceive('getTicketInlineAttachmentReferences')->times(4)->andReturn([]);
        $this->app->instance(ZnunyClient::class, $client);

        $this->artisan('znuny:warm-inline-image-cache')
            ->expectsOutput('- Selected unique tickets: 4')
            ->assertSuccessful();
    }

    public function test_tail_cursor_advances_then_next_cycle_uses_later_offset(): void
    {
        $this->enableWarmer();
        $this->bindMapper();
        config()->set('znuny.inline_image_warmer_batch_size', 2);
        config()->set('znuny.inline_image_warmer_hot_percentage', 50); // 1 hot, 1 tail

        $tickets = [
            ['TicketID' => 1, 'InlineAttachmentCount' => 1],
            ['TicketID' => 2, 'InlineAttachmentCount' => 1],
            ['TicketID' => 3, 'InlineAttachmentCount' => 1],
        ]; // 3 eligible

        $reader = Mockery::mock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->shouldReceive('getTickets')->twice()->andReturn($tickets);
        $this->app->instance(ZnunyTicketWorkspaceCacheReader::class, $reader);

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldNotReceive('searchTicketsWithMetadata');
        $client->shouldReceive('getTicketInlineAttachmentReferences')->times(4)->andReturn([]);
        $this->app->instance(ZnunyClient::class, $client);

        $this->artisan('znuny:warm-inline-image-cache')->assertSuccessful();
        $this->assertSame('1', (string) Redis::get('znuny:inline_image_warmer:tail_offset'));

        $this->artisan('znuny:warm-inline-image-cache')->assertSuccessful();
        $this->assertSame('0', (string) Redis::get('znuny:inline_image_warmer:tail_offset'));
    }

    public function test_tail_cursor_wraps_to_zero_at_total(): void
    {
        $this->enableWarmer();
        $this->bindMapper();
        config()->set('znuny.inline_image_warmer_batch_size', 2);
        config()->set('znuny.inline_image_warmer_hot_percentage', 50); // 1 hot, 1 tail

        $tickets = [
            ['TicketID' => 1, 'InlineAttachmentCount' => 1],
            ['TicketID' => 2, 'InlineAttachmentCount' => 1],
            ['TicketID' => 3, 'InlineAttachmentCount' => 1],
        ]; // 3 eligible

        Redis::set('znuny:inline_image_warmer:tail_offset', 1);

        $reader = Mockery::mock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->shouldReceive('getTickets')->once()->andReturn($tickets);
        $this->app->instance(ZnunyTicketWorkspaceCacheReader::class, $reader);

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldNotReceive('searchTicketsWithMetadata');
        $client->shouldReceive('getTicketInlineAttachmentReferences')->times(2)->andReturn([]);
        $this->app->instance(ZnunyClient::class, $client);

        $this->artisan('znuny:warm-inline-image-cache')->assertSuccessful();

        $this->assertSame('0', (string) Redis::get('znuny:inline_image_warmer:tail_offset'));
    }

    public function test_duplicate_references_are_processed_once_and_throwable_does_not_abort_cycle(): void
    {
        $this->enableWarmer();
        $this->bindMapper();
        config()->set('znuny.inline_image_warmer_batch_size', 2);
        config()->set('znuny.inline_image_warmer_hot_percentage', 100);

        $tickets = [
            ['TicketID' => 1, 'InlineAttachmentCount' => 2],
            ['TicketID' => 2, 'InlineAttachmentCount' => 1],
        ]; // 2 eligible => selects both as batch <= eligible

        $reader = Mockery::mock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->shouldReceive('getTickets')->once()->andReturn($tickets);
        $this->app->instance(ZnunyTicketWorkspaceCacheReader::class, $reader);

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldNotReceive('searchTicketsWithMetadata');
        $client->shouldReceive('getTicketInlineAttachmentReferences')
            ->once()
            ->with(1)
            ->andReturn([
                ['TicketID' => 1, 'ArticleID' => 10, 'ContentID' => 'c1'],
                ['TicketID' => 1, 'ArticleID' => 10, 'ContentID' => 'c1'],
                ['TicketID' => 1, 'ArticleID' => 11, 'ContentID' => 'c2'],
            ]);
        $client->shouldReceive('getTicketInlineAttachmentReferences')
            ->once()
            ->with(2)
            ->andReturn([
                ['TicketID' => 2, 'ArticleID' => 20, 'ContentID' => 'c3'],
            ]);
        $this->app->instance(ZnunyClient::class, $client);

        $images = Mockery::mock(ZnunyInlineImageService::class);
        $images->shouldReceive('getInlineImage')->once()->with(1, 10, 'c1')->andThrow(new \Error('boom'));
        $images->shouldReceive('getInlineImage')->once()->with(1, 11, 'c2')->andReturn(['content' => 'x']);
        $images->shouldReceive('getInlineImage')->once()->with(2, 20, 'c3')->andReturn(['content' => 'x']);
        $this->app->instance(ZnunyInlineImageService::class, $images);

        $this->artisan('znuny:warm-inline-image-cache')
            ->expectsOutput('- Inline references discovered: 4')
            ->expectsOutput('- Inline references processed: 2')
            ->expectsOutput('- Errors encountered: 1')
            ->assertSuccessful();
    }
}
