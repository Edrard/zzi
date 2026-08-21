<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Znuny\ClosedTicketCacheService;
use App\Services\Znuny\ZnunyInlineImageContentId;
use App\Services\Znuny\ZnunyInlineImageService;
use App\Services\Znuny\ZnunyTicketCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ZnunyInlineImageControllerTest extends TestCase
{
    use RefreshDatabase;

    private $ticketCache;

    private $closedTicketCache;

    private $inlineImageService;

    private $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ticketCache = Mockery::mock(ZnunyTicketCacheService::class);
        $this->app->instance(ZnunyTicketCacheService::class, $this->ticketCache);

        $this->closedTicketCache = Mockery::mock(ClosedTicketCacheService::class);
        $this->app->instance(ClosedTicketCacheService::class, $this->closedTicketCache);

        $this->inlineImageService = Mockery::mock(ZnunyInlineImageService::class);
        $this->app->instance(ZnunyInlineImageService::class, $this->inlineImageService);

        $this->token = ZnunyInlineImageContentId::encodeToken('image1@domain.com');
    }

    public function test_unauthenticated_request_is_aborted()
    {
        $response = $this->get(route('znuny.inline-image.show', ['ticketId' => 123, 'articleId' => 456, 'token' => $this->token]));
        $response->assertNotFound();
    }

    public function test_unauthorized_role_is_aborted()
    {
        $user = User::factory()->create(['role' => 'guest']);
        $response = $this->actingAs($user)->get(route('znuny.inline-image.show', ['ticketId' => 123, 'articleId' => 456, 'token' => $this->token]));
        $response->assertNotFound();
    }

    public function test_active_ticket_cache_authorizes_and_returns_bytes()
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->ticketCache->shouldReceive('getTicket')->with('123')->andReturn(['TicketID' => 123]);
        $this->closedTicketCache->shouldNotReceive('getTicket');

        $this->inlineImageService->shouldReceive('getInlineImage')
            ->with('123', '456', 'image1@domain.com')
            ->andReturn([
                'content_type' => 'image/png',
                'content' => 'fake_bytes',
            ]);

        $response = $this->actingAs($user)->get(route('znuny.inline-image.show', ['ticketId' => 123, 'articleId' => 456, 'token' => $this->token]));

        $response->assertOk();
        $this->assertEquals('fake_bytes', $response->getContent());
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('Content-Disposition', 'inline');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $cacheControl = array_map(
            'trim',
            explode(',', (string) $response->headers->get('Cache-Control'))
        );
        sort($cacheControl);

        $this->assertSame(
            ['max-age=3600', 'private'],
            $cacheControl
        );
    }

    public function test_all_authorized_roles_can_access()
    {
        $roles = ['admin', 'operator', 'viewer'];

        $this->ticketCache->shouldReceive('getTicket')->with('123')->times(3)->andReturn(['TicketID' => 123]);
        $this->closedTicketCache->shouldNotReceive('getTicket');

        $this->inlineImageService->shouldReceive('getInlineImage')
            ->with('123', '456', 'image1@domain.com')
            ->times(3)
            ->andReturn([
                'content_type' => 'image/png',
                'content' => 'fake_bytes',
            ]);

        foreach ($roles as $role) {
            $user = User::factory()->create(['role' => $role]);
            $response = $this->actingAs($user)->get(route('znuny.inline-image.show', ['ticketId' => 123, 'articleId' => 456, 'token' => $this->token]));
            $response->assertOk();
        }
    }

    public function test_closed_ticket_cache_fallback_authorizes()
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->ticketCache->shouldReceive('getTicket')->with('123')->andReturn(null);
        $this->closedTicketCache->shouldReceive('getTicket')->with('123')->andReturn(['TicketID' => 123]);

        $this->inlineImageService->shouldReceive('getInlineImage')
            ->andReturn([
                'content_type' => 'image/jpeg',
                'content' => 'fake_bytes',
            ]);

        $response = $this->actingAs($user)->get(route('znuny.inline-image.show', ['ticketId' => 123, 'articleId' => 456, 'token' => $this->token]));

        $response->assertOk();
    }

    public function test_absent_from_both_caches_returns_404()
    {
        $user = User::factory()->create(['role' => 'viewer']);

        $this->ticketCache->shouldReceive('getTicket')->with('123')->andReturn(null);
        $this->closedTicketCache->shouldReceive('getTicket')->with('123')->andReturn(null);

        $this->inlineImageService->shouldNotReceive('getInlineImage');

        $response = $this->actingAs($user)->get(route('znuny.inline-image.show', ['ticketId' => 123, 'articleId' => 456, 'token' => $this->token]));
        $response->assertNotFound();
    }

    public function test_malformed_token_returns_404()
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->ticketCache->shouldReceive('getTicket')->with('123')->andReturn(['TicketID' => 123]);
        $this->inlineImageService->shouldNotReceive('getInlineImage');

        $response = $this->actingAs($user)->get(route('znuny.inline-image.show', ['ticketId' => 123, 'articleId' => 456, 'token' => 'invalid+token']));
        $response->assertNotFound();
    }

    public function test_malformed_canonical_decode_token_returns_404()
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->ticketCache->shouldReceive('getTicket')->with('123')->andReturn(['TicketID' => 123]);
        $this->inlineImageService->shouldNotReceive('getInlineImage');

        // This matches route regex ([a-zA-Z0-9\-_]+) but fails decode (since it's not base64 properly padded or valid utf8 or canonical)
        $malformedButMatchesRegex = '-';

        $response = $this->actingAs($user)->get(route('znuny.inline-image.show', ['ticketId' => 123, 'articleId' => 456, 'token' => $malformedButMatchesRegex]));
        $response->assertNotFound();
    }

    public function test_image_service_null_returns_404()
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->ticketCache->shouldReceive('getTicket')->with('123')->andReturn(['TicketID' => 123]);

        $this->inlineImageService->shouldReceive('getInlineImage')
            ->andReturn(null);

        $response = $this->actingAs($user)->get(route('znuny.inline-image.show', ['ticketId' => 123, 'articleId' => 456, 'token' => $this->token]));
        $response->assertNotFound();
    }

    public function test_image_service_exception_returns_502()
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->ticketCache->shouldReceive('getTicket')->with('123')->andReturn(['TicketID' => 123]);

        $this->inlineImageService->shouldReceive('getInlineImage')
            ->andThrow(new \RuntimeException('API Down'));

        $response = $this->actingAs($user)->get(route('znuny.inline-image.show', ['ticketId' => 123, 'articleId' => 456, 'token' => $this->token]));
        $response->assertStatus(502);
        $this->assertEquals('Bad Gateway', $response->getContent());
        $this->assertStringNotContainsString('API Down', $response->getContent());
    }

    public function test_positive_numeric_route_constraints()
    {
        $user = User::factory()->create(['role' => 'admin']);

        // Use an invalid ID that shouldn't match the route
        $response = $this->actingAs($user)->get('/znuny/ticket/0/article/456/inline-image/'.$this->token);
        $response->assertNotFound();

        $response2 = $this->actingAs($user)->get('/znuny/ticket/123/article/abc/inline-image/'.$this->token);
        $response2->assertNotFound();
    }
}
