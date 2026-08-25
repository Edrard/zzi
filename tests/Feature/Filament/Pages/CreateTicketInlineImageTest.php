<?php

namespace Tests\Feature\Filament\Pages;

use App\Filament\Pages\CreateTicket;
use App\Models\User;
use App\Services\Znuny\ZnunyCachedLookupService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyInlineImagePayloadService;
use App\Services\Znuny\ZnunyStandaloneTicketCreationService;
use App\Services\Znuny\ZnunyTicketAdvancedDefaultsService;
use Filament\Forms\Components\RichEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class CreateTicketInlineImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_body_field_is_rich_editor_and_properly_configured()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn([])->byDefault();
            $mock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')->andReturn([])->byDefault();
            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->andReturn([])->byDefault();
            $mock->shouldReceive('resolveTemplateCandidate')->andReturn(null)->byDefault();
            $mock->shouldReceive('getTicketStates')->andReturn([])->byDefault();
            $mock->shouldReceive('getTicketPriorities')->andReturn([])->byDefault();
        });
        $livewire = Livewire::actingAs($user)->test(CreateTicket::class);
        $bodyComponent = $livewire->instance()->form->getComponent('body');

        $this->assertNotNull($bodyComponent);
        $this->assertInstanceOf(RichEditor::class, $bodyComponent);
        $this->assertTrue($bodyComponent->isRequired());

        // Editor height contract
        $extraAttributes = $bodyComponent->getExtraAttributes();
        $this->assertArrayHasKey('class', $extraAttributes);
        $this->assertStringContainsString('znuny-ticket-body-editor', $extraAttributes['class']);

        $this->assertEquals('local', $bodyComponent->getFileAttachmentsDiskName());
        $this->assertEquals('private', $bodyComponent->getFileAttachmentsVisibility());

        $allowedTypes = $bodyComponent->getFileAttachmentsAcceptedFileTypes();
        $this->assertEquals(['image/png', 'image/jpeg', 'image/gif', 'image/webp'], $allowedTypes);
        $this->assertNotContains('image/svg+xml', $allowedTypes);

        $this->assertTrue($bodyComponent->shouldPreventFileAttachmentPathTampering());
    }

    public function test_first_section_is_headingless_and_compact()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getPrewarmDatasetState')->andReturn(['available' => true, 'status' => 'ready'])->byDefault();
            $mock->shouldReceive('getFilteredQueueOptions')->andReturn([])->byDefault();
            $mock->shouldReceive('getAssignableHumanOwnerOptionsForQueue')->andReturn([])->byDefault();
            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->andReturn([])->byDefault();
            $mock->shouldReceive('resolveTemplateCandidate')->andReturn(null)->byDefault();
            $mock->shouldReceive('getTicketStates')->andReturn([])->byDefault();
            $mock->shouldReceive('getTicketPriorities')->andReturn([])->byDefault();
        });
        $livewire = Livewire::actingAs($user)->test(CreateTicket::class);

        $components = $livewire->instance()->form->getComponents();
        $section = $components[0];

        $this->assertNull($section->getHeading());
        $this->assertTrue($section->isCompact());
    }

    public function test_payload_service_processes_images_and_maintains_order()
    {
        Storage::fake('local');
        $draftDir = 'znuny-ticket-inline/guest/test-token';
        $image1 = $draftDir.'/1.png';
        $image2 = $draftDir.'/2.jpg';

        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        $jpgBytes = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');

        Storage::disk('local')->put($image1, $pngBytes);
        Storage::disk('local')->put($image2, $jpgBytes);

        $service = new ZnunyInlineImagePayloadService('local');

        $html = '<p>Test</p><img src="temp-url" data-id="'.$image1.'" /><p>Middle</p><img src="temp-url" data-id="'.$image2.'" />';
        $result = $service->processHtml($html, $draftDir);

        $this->assertStringContainsString('cid:znuny-inline-', $result['html']);
        $this->assertStringNotContainsString('data-id=', $result['html']);
        $this->assertStringNotContainsString('temp-url', $result['html']);
        $this->assertCount(2, $result['attachments']);

        $cid1 = $result['attachments'][0]['ContentID'];
        $cid2 = $result['attachments'][1]['ContentID'];

        // Prove CID survives sanitization
        $this->assertStringContainsString('src="cid:'.$cid1.'"', $result['html']);
        $this->assertStringContainsString('src="cid:'.$cid2.'"', $result['html']);

        $this->assertEquals('image/png', explode(';', $result['attachments'][0]['ContentType'])[0]);
        $this->assertEquals('inline', $result['attachments'][0]['Disposition']);

        $this->assertEquals('image/jpeg', explode(';', $result['attachments'][1]['ContentType'])[0]);
        $this->assertEquals('inline', $result['attachments'][1]['Disposition']);

        // Assert two distinct CIDs
        $this->assertNotEquals($result['attachments'][0]['ContentID'], $result['attachments'][1]['ContentID']);

        // Order must be maintained
        $pos1 = strpos($result['html'], $result['attachments'][0]['ContentID']);
        $pos2 = strpos($result['html'], $result['attachments'][1]['ContentID']);
        $this->assertTrue($pos1 < $pos2, 'Images must be in the correct original document order.');
    }

    public function test_rich_text_with_no_images_returns_zero_attachments()
    {
        $service = new ZnunyInlineImagePayloadService('local');
        $html = '<p><strong>Bold</strong> text</p>';

        $result = $service->processHtml($html, 'some-dir');

        $this->assertCount(0, $result['attachments']);
        $this->assertStringContainsString('<strong>Bold</strong>', $result['html']);
    }

    public function test_rejects_unauthorized_paths()
    {
        Storage::fake('local');
        $draftDir = 'znuny-ticket-inline/guest/test-token';
        $service = new ZnunyInlineImagePayloadService('local');

        $this->expectException(\InvalidArgumentException::class);
        $service->processHtml('<img src="temp-url" data-id="../outside/test.png" />', $draftDir);
    }

    public function test_rejects_another_users_draft()
    {
        Storage::fake('local');
        $draftDir = 'znuny-ticket-inline/guest/test-token';
        $service = new ZnunyInlineImagePayloadService('local');

        $this->expectException(\InvalidArgumentException::class);
        $service->processHtml('<img src="temp-url" data-id="znuny-ticket-inline/other/test-token/test.png" />', $draftDir);
    }

    public function test_missing_file_rejected()
    {
        Storage::fake('local');
        $draftDir = 'znuny-ticket-inline/guest/test-token';
        $service = new ZnunyInlineImagePayloadService('local');

        $this->expectException(\InvalidArgumentException::class);
        $service->processHtml('<img src="temp-url" data-id="'.$draftDir.'/missing.png" />', $draftDir);
    }

    public function test_unsupported_sources_rejected()
    {
        $service = new ZnunyInlineImagePayloadService('local');

        $tests = [
            '<img src="data:image/png;base64,iVBOR..." />',
            '<img src="http://example.com/img.png" />',
            '<img src="https://example.com/img.png" />',
            '<img src="/local-path/img.png" />',
        ];

        foreach ($tests as $html) {
            try {
                $service->processHtml($html, 'some-dir');
                $this->fail("Should have thrown exception for HTML: {$html}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Unsupported image source', $e->getMessage());
            }
        }
    }

    public function test_invalid_mime_types_rejected()
    {
        Storage::fake('local');
        $draftDir = 'znuny-ticket-inline/guest/test-token';
        $imagePath = $draftDir.'/test.svg';

        // Write fake SVG
        Storage::disk('local')->put($imagePath, '<svg></svg>');

        $service = new ZnunyInlineImagePayloadService('local');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported MIME type/');
        $service->processHtml('<img src="temp-url" data-id="'.$imagePath.'" />', $draftDir);
    }

    public function test_payload_service_rejects_corrupted_image_bytes()
    {
        Storage::fake('local');
        $draftDir = 'znuny-ticket-inline/user1/draft1';
        $imagePath = $draftDir.'/corrupt.png';

        // Write a valid PNG signature followed by garbage to pass MIME but fail getimagesizefromstring
        $corruptPng = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0DIHDR\x00\x00";
        Storage::disk('local')->put($imagePath, $corruptPng);

        $service = new ZnunyInlineImagePayloadService('local');

        $this->expectException(\InvalidArgumentException::class);
        $service->processHtml('<img src="temp-url" data-id="'.$imagePath.'" />', $draftDir);
    }

    public function test_cleanup_command_respects_retention()
    {
        Storage::fake('local');
        $disk = Storage::disk('local');

        $oldDraft = 'znuny-ticket-inline/user1/old-draft';
        $newDraft = 'znuny-ticket-inline/user1/new-draft';
        $emptyDraft = 'znuny-ticket-inline/user1/empty-draft';
        $unrelatedPath = 'unrelated/path';

        $disk->put($oldDraft.'/1.png', 'content');
        $disk->put($newDraft.'/1.png', 'content');
        $disk->makeDirectory($emptyDraft);
        $disk->put($unrelatedPath.'/keep.png', 'content');

        // Touch old draft to 48 hours ago
        $past = Carbon::now()->subHours(48)->timestamp;
        touch($disk->path($oldDraft.'/1.png'), $past);

        $this->artisan('znuny:cleanup-inline-drafts')->assertSuccessful();

        $this->assertFalse($disk->exists($oldDraft));
        $this->assertTrue($disk->exists($newDraft));
        $this->assertFalse($disk->exists($emptyDraft));
        $this->assertTrue($disk->exists($unrelatedPath.'/keep.png'));
    }

    public function test_standalone_service_sends_explicit_html_content_type()
    {
        $mockClient = Mockery::mock(ZnunyClient::class);

        $mockClient->shouldReceive('getCustomerUser')
            ->once()
            ->with('testuser')
            ->andReturn(['found' => true, 'customer_id' => 'cust-id']);

        $mockClient->shouldReceive('validateTicketCreate')
            ->once()
            ->with(Mockery::type('array'))
            ->andReturn(['valid' => true, 'errors' => [], 'warnings' => []]);

        $mockClient->shouldReceive('createTicket')
            ->once()
            ->withArgs(function ($payload) {
                return $payload['Article']['ContentType'] === 'text/html; charset=utf-8'
                    && $payload['Article']['Body'] === '<p>test html</p>'
                    && count($payload['Attachment']) === 1
                    && $payload['Attachment'][0]['Filename'] === 'test.png';
            })
            ->andReturn([
                'success' => true,
                'ticket_id' => 123,
                'ticket_number' => '100123',
                'warnings' => [],
            ]);

        $this->app->instance(ZnunyClient::class, $mockClient);

        $mockDefaultsService = Mockery::mock(ZnunyTicketAdvancedDefaultsService::class);
        $mockDefaultsService->shouldReceive('getDefaults')
            ->once()
            ->andReturn([
                'state' => 'new',
                'priority' => '3 normal',
                'lock' => 'lock',
            ]);
        $this->app->instance(ZnunyTicketAdvancedDefaultsService::class, $mockDefaultsService);

        $service = app(ZnunyStandaloneTicketCreationService::class);

        $result = $service->createTicket(
            ownerId: 1,
            queue: 'TestQueue',
            customerUser: 'testuser',
            title: 'Test Title',
            articleBody: '<p>test html</p>',
            attachments: [
                ['Filename' => 'test.png', 'Content' => 'base64', 'ContentType' => 'image/png'],
            ],
            articleContentType: 'text/html; charset=utf-8'
        );

        $this->assertTrue($result['success']);
    }

    public function test_standalone_service_defaults_to_plain_text_for_backwards_compatibility()
    {
        $mockClient = Mockery::mock(ZnunyClient::class);

        $mockClient->shouldReceive('getCustomerUser')
            ->once()
            ->with('testuser')
            ->andReturn(['found' => true, 'customer_id' => 'cust-id']);

        $mockClient->shouldReceive('validateTicketCreate')
            ->once()
            ->with(Mockery::type('array'))
            ->andReturn(['valid' => true, 'errors' => [], 'warnings' => []]);

        $mockClient->shouldReceive('createTicket')
            ->once()
            ->withArgs(function ($payload) {
                return $payload['Article']['ContentType'] === 'text/plain; charset=utf8'
                    && $payload['Article']['Body'] === 'Plain text body'
                    && ! isset($payload['Attachment']);
            })
            ->andReturn([
                'success' => true,
                'ticket_id' => 124,
                'ticket_number' => '100124',
                'warnings' => [],
            ]);

        $this->app->instance(ZnunyClient::class, $mockClient);

        $mockDefaultsService = Mockery::mock(ZnunyTicketAdvancedDefaultsService::class);
        $mockDefaultsService->shouldReceive('getDefaults')
            ->once()
            ->andReturn([
                'state' => 'new',
                'priority' => '3 normal',
                'lock' => 'lock',
            ]);
        $this->app->instance(ZnunyTicketAdvancedDefaultsService::class, $mockDefaultsService);

        $service = app(ZnunyStandaloneTicketCreationService::class);

        $result = $service->createTicket(
            ownerId: 1,
            queue: 'TestQueue',
            customerUser: 'testuser',
            title: 'Test Title',
            articleBody: 'Plain text body',
            attachments: []
            // omitted articleContentType
        );

        $this->assertTrue($result['success']);
    }
}
