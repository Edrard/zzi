<?php

namespace Tests\Feature\Console\Commands;

use App\Models\Setting;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyInlineImageService;
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

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->with(['StateType' => 'open', 'CountOnly' => 1])
            ->andReturn(['total_count' => 0]);
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

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->with(['StateType' => 'open', 'CountOnly' => 1])
            ->andReturn(['total_count' => 0]);
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

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->with(['StateType' => 'open', 'CountOnly' => 1])
            ->andReturn(['total_count' => 100]);

        $client->shouldReceive('searchTicketsWithMetadata')
            ->twice()
            ->with(Mockery::on(fn ($args) => ! isset($args['CountOnly'])
                && $args['SortBy'] === 'Changed'
                && $args['SortDirection'] === 'DESC'
                && $args['Limit'] === 50))
            ->andReturn(['tickets' => []]);

        $this->app->instance(ZnunyClient::class, $client);

        $this->artisan('znuny:warm-inline-image-cache')
            ->expectsOutput('- Hot slots max: 5')
            ->expectsOutput('- Tail slots max: 45')
            ->expectsOutput('- Selected unique tickets: 0')
            ->assertSuccessful();
    }

    public function test_batch_one_and_hot_hundred_have_no_tail(): void
    {
        $this->enableWarmer();
        $this->bindMapper();
        config()->set('znuny.inline_image_warmer_batch_size', 1);
        config()->set('znuny.inline_image_warmer_hot_percentage', 100);

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->with(['StateType' => 'open', 'CountOnly' => 1])
            ->andReturn(['total_count' => 10]);
        $client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->with(Mockery::on(fn ($args) => ($args['Offset'] ?? null) === 0
                && ($args['Limit'] ?? null) === 1
                && ($args['SortBy'] ?? null) === 'Changed'
                && ($args['SortDirection'] ?? null) === 'DESC'))
            ->andReturn(['tickets' => []]);
        $this->app->instance(ZnunyClient::class, $client);

        $this->artisan('znuny:warm-inline-image-cache')
            ->expectsOutput('- Hot slots max: 1')
            ->expectsOutput('- Tail slots max: 0')
            ->assertSuccessful();

        $this->assertNull(Redis::get('znuny:inline_image_warmer:tail_offset'));
    }

    public function test_hot_underfill_does_not_expand_tail_quota_and_filters_non_positive_counts(): void
    {
        $this->enableWarmer();
        $this->bindMapper();
        config()->set('znuny.inline_image_warmer_batch_size', 10);
        config()->set('znuny.inline_image_warmer_hot_percentage', 20);

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->with(['StateType' => 'open', 'CountOnly' => 1])
            ->andReturn(['total_count' => 100]);

        $nonCountCall = 0;
        $client->shouldReceive('searchTicketsWithMetadata')
            ->twice()
            ->with(Mockery::on(fn ($args) => ! isset($args['CountOnly'])
                && ($args['SortBy'] ?? null) === 'Changed'
                && ($args['SortDirection'] ?? null) === 'DESC'))
            ->andReturnUsing(function () use (&$nonCountCall) {
                $nonCountCall++;

                if ($nonCountCall === 1) {
                    return ['tickets' => [
                        ['TicketID' => 1, 'InlineAttachmentCount' => 1],
                        ['TicketID' => 2, 'InlineAttachmentCount' => 0],
                        ['TicketID' => 3, 'InlineAttachmentCount' => null],
                        ['TicketID' => 4],
                        ['TicketID' => 5, 'InlineAttachmentCount' => -1],
                    ]];
                }

                $tickets = [];
                for ($id = 10; $id <= 19; $id++) {
                    $tickets[] = ['TicketID' => $id, 'InlineAttachmentCount' => 1];
                }

                return ['tickets' => $tickets];
            });

        $client->shouldReceive('getTicketInlineAttachmentReferences')->times(9)->andReturn([]);
        $this->app->instance(ZnunyClient::class, $client);

        $this->artisan('znuny:warm-inline-image-cache')
            ->expectsOutput('- Hot slots max: 2')
            ->expectsOutput('- Tail slots max: 8')
            ->expectsOutput('- Selected unique tickets: 9')
            ->assertSuccessful();

        $this->assertSame('8', (string) Redis::get('znuny:inline_image_warmer:tail_offset'));
    }

    public function test_hot_and_tail_are_deduplicated_by_ticket_id(): void
    {
        $this->enableWarmer();
        $this->bindMapper();
        config()->set('znuny.inline_image_warmer_batch_size', 4);
        config()->set('znuny.inline_image_warmer_hot_percentage', 50);

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->with(['StateType' => 'open', 'CountOnly' => 1])
            ->andReturn(['total_count' => 10]);
        $client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->andReturn(['tickets' => [
                ['TicketID' => 1, 'InlineAttachmentCount' => 1],
                ['TicketID' => 2, 'InlineAttachmentCount' => 1],
            ]]);
        $client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->andReturn(['tickets' => [
                ['TicketID' => 1, 'InlineAttachmentCount' => 1],
                ['TicketID' => 3, 'InlineAttachmentCount' => 1],
                ['TicketID' => 4, 'InlineAttachmentCount' => 1],
            ]]);
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
        config()->set('znuny.inline_image_warmer_hot_percentage', 50);

        $nonCountCall = 0;
        $seenOffsets = [];

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldReceive('searchTicketsWithMetadata')
            ->twice()
            ->with(['StateType' => 'open', 'CountOnly' => 1])
            ->andReturn(['total_count' => 10]);

        $client->shouldReceive('searchTicketsWithMetadata')
            ->times(4)
            ->with(Mockery::on(function ($args) use (&$seenOffsets) {
                $seenOffsets[] = $args['Offset'] ?? null;

                return ($args['SortBy'] ?? null) === 'Changed'
                    && ($args['SortDirection'] ?? null) === 'DESC';
            }))
            ->andReturnUsing(function () use (&$nonCountCall) {
                $nonCountCall++;

                return match ($nonCountCall) {
                    1 => ['tickets' => [['TicketID' => 1, 'InlineAttachmentCount' => 1]]],
                    2 => ['tickets' => [['TicketID' => 2, 'InlineAttachmentCount' => 1]]],
                    3 => ['tickets' => [['TicketID' => 1, 'InlineAttachmentCount' => 1]]],
                    default => ['tickets' => [['TicketID' => 3, 'InlineAttachmentCount' => 1]]],
                };
            });

        $client->shouldReceive('getTicketInlineAttachmentReferences')->times(4)->andReturn([]);
        $this->app->instance(ZnunyClient::class, $client);

        $this->artisan('znuny:warm-inline-image-cache')->assertSuccessful();
        $this->assertSame('1', (string) Redis::get('znuny:inline_image_warmer:tail_offset'));

        $this->artisan('znuny:warm-inline-image-cache')->assertSuccessful();
        $this->assertSame('2', (string) Redis::get('znuny:inline_image_warmer:tail_offset'));

        $this->assertSame([0, 0, 0, 1], $seenOffsets);
    }

    public function test_tail_cursor_wraps_to_zero_at_total(): void
    {
        $this->enableWarmer();
        $this->bindMapper();
        config()->set('znuny.inline_image_warmer_batch_size', 2);
        config()->set('znuny.inline_image_warmer_hot_percentage', 50);
        Redis::set('znuny:inline_image_warmer:tail_offset', 2);

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->with(['StateType' => 'open', 'CountOnly' => 1])
            ->andReturn(['total_count' => 3]);
        $client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->andReturn(['tickets' => [['TicketID' => 1, 'InlineAttachmentCount' => 1]]]);
        $client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->with(Mockery::on(fn ($args) => ($args['Offset'] ?? null) === 2))
            ->andReturn(['tickets' => [['TicketID' => 3, 'InlineAttachmentCount' => 1]]]);
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

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->with(['StateType' => 'open', 'CountOnly' => 1])
            ->andReturn(['total_count' => 2]);
        $client->shouldReceive('searchTicketsWithMetadata')
            ->once()
            ->andReturn(['tickets' => [
                ['TicketID' => 1, 'InlineAttachmentCount' => 2],
                ['TicketID' => 2, 'InlineAttachmentCount' => 1],
            ]]);

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
