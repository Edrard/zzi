<?php

namespace Tests\Unit\Services\Znuny;

use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyInlineImageService;
use App\Services\Znuny\ZnunyInlineImageWarmerService;
use App\Services\Znuny\ZnunyTicketWorkspaceCacheReader;
use App\Services\Znuny\ZnunyTicketWorkspaceStateTypeMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ZnunyInlineImageWarmerLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Redis::flushDB();

        Setting::updateOrCreate(
            ['key' => 'znuny_inline_image_warmer_enabled'],
            ['value' => '1', 'type' => 'boolean'],
        );

        Setting::updateOrCreate(
            ['key' => 'znuny_ticket_workspace_active_state_type_ids'],
            ['value' => '["open"]', 'type' => 'json'],
        );

        SettingsService::clearAllCaches();
    }

    protected function tearDown(): void
    {
        Redis::flushDB();
        SettingsService::clearAllCaches();

        parent::tearDown();
    }

    public function test_existing_running_lock_blocks_second_service_run(): void
    {
        Redis::set('znuny:inline_image_warmer:running', 'existing-run', 'EX', 3600);

        $client = Mockery::mock(ZnunyClient::class);
        $client->shouldNotReceive('getTicketInlineAttachmentReferences');

        $inlineImageService = Mockery::mock(ZnunyInlineImageService::class);
        $inlineImageService->shouldNotReceive('getInlineImage');

        $mapper = Mockery::mock(ZnunyTicketWorkspaceStateTypeMapper::class);
        $mapper->shouldNotReceive('mapInternalIdsToZnunyTypes');

        $reader = Mockery::mock(ZnunyTicketWorkspaceCacheReader::class);
        $reader->shouldNotReceive('getTickets');

        $service = new ZnunyInlineImageWarmerService(
            $client,
            $inlineImageService,
            $mapper,
            $reader,
        );

        $result = $service->warm();

        $this->assertSame('already running', $result['status']);
        $this->assertSame('existing-run', Redis::get('znuny:inline_image_warmer:running'));
        $this->assertNull(Redis::get('znuny:inline_image_warmer:last_started_at'));
    }

    public function test_acquired_lock_has_ttl_and_is_cleared_on_normal_early_return(): void
    {
        $client = Mockery::mock(ZnunyClient::class);
        $inlineImageService = Mockery::mock(ZnunyInlineImageService::class);
        $reader = Mockery::mock(ZnunyTicketWorkspaceCacheReader::class);

        $mapper = Mockery::mock(ZnunyTicketWorkspaceStateTypeMapper::class);
        $mapper->shouldReceive('mapInternalIdsToZnunyTypes')
            ->once()
            ->andReturnUsing(function (): array {
                $this->assertSame(1, Redis::exists('znuny:inline_image_warmer:running'));

                $ttl = Redis::ttl('znuny:inline_image_warmer:running');
                $this->assertGreaterThan(0, $ttl);
                $this->assertLessThanOrEqual(3600, $ttl);

                return [];
            });

        $service = new ZnunyInlineImageWarmerService(
            $client,
            $inlineImageService,
            $mapper,
            $reader,
        );

        $result = $service->warm();

        $this->assertSame('no mapped state types found', $result['status']);
        $this->assertSame(0, Redis::exists('znuny:inline_image_warmer:running'));
        $this->assertNotNull(Redis::get('znuny:inline_image_warmer:last_started_at'));
    }

    public function test_running_lock_is_cleared_from_finally_when_warmer_throws(): void
    {
        $client = Mockery::mock(ZnunyClient::class);
        $inlineImageService = Mockery::mock(ZnunyInlineImageService::class);
        $reader = Mockery::mock(ZnunyTicketWorkspaceCacheReader::class);

        $mapper = Mockery::mock(ZnunyTicketWorkspaceStateTypeMapper::class);
        $mapper->shouldReceive('mapInternalIdsToZnunyTypes')
            ->once()
            ->andThrow(new RuntimeException('mapper failed'));

        $service = new ZnunyInlineImageWarmerService(
            $client,
            $inlineImageService,
            $mapper,
            $reader,
        );

        try {
            $service->warm();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('mapper failed', $e->getMessage());
        }

        $this->assertSame(0, Redis::exists('znuny:inline_image_warmer:running'));
        $this->assertNotNull(Redis::get('znuny:inline_image_warmer:last_started_at'));
    }
}
