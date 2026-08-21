<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyInlineImageService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ZnunyInlineImageServiceTest extends TestCase
{
    private ZnunyClient $client;

    private ZnunyInlineImageService $service;

    private \Illuminate\Contracts\Cache\Repository $cacheRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Mockery::mock(ZnunyClient::class);
        $this->cacheRepository = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
        $this->service = new ZnunyInlineImageService($this->client);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function getCacheKey(string $ticketId, string $articleId, string $contentId): string
    {
        return 'znuny:inline-image:v1:'.hash('sha256', "{$ticketId}|{$articleId}|{$contentId}");
    }

    public function test_valid_cache_hit_returns_without_calling_znuny(): void
    {
        $cacheKey = $this->getCacheKey('123', '456', 'image1@domain.com');

        Cache::shouldReceive('store')
            ->with('redis')
            ->andReturn($this->cacheRepository);

        $this->cacheRepository->shouldReceive('get')
            ->once()
            ->with($cacheKey)
            ->andReturn([
                'content_type' => 'image/png',
                'content_id' => 'image1@domain.com',
                'content' => 'fake_binary_bytes',
            ]);

        $this->client->shouldNotReceive('getInlineAttachment');

        $result = $this->service->getInlineImage(123, 456, 'image1@domain.com');

        $this->assertEquals([
            'content_type' => 'image/png',
            'content_id' => 'image1@domain.com',
            'content' => 'fake_binary_bytes',
        ], $result);
    }

    public function test_cache_hit_image_jpg_returns_normalized_image_jpeg(): void
    {
        $cacheKey = $this->getCacheKey('123', '456', 'image1@domain.com');

        Cache::shouldReceive('store')->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('get')
            ->once()
            ->with($cacheKey)
            ->andReturn([
                'content_type' => 'image/jpg',
                'content_id' => 'image1@domain.com',
                'content' => 'fake_binary_bytes',
            ]);

        $this->client->shouldNotReceive('getInlineAttachment');

        $result = $this->service->getInlineImage(123, 456, 'image1@domain.com');

        $this->assertEquals('image/jpeg', $result['content_type']);
    }

    public function test_cache_hit_canonical_content_id_is_returned_canonical(): void
    {
        $cacheKey = $this->getCacheKey('123', '456', 'Image1@Domain.COM');

        Cache::shouldReceive('store')->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('get')
            ->once()
            ->with($cacheKey)
            ->andReturn([
                'content_type' => 'image/png',
                'content_id' => '<Image1@Domain.COM>',
                'content' => 'fake_binary_bytes',
            ]);

        $this->client->shouldNotReceive('getInlineAttachment');

        $result = $this->service->getInlineImage(123, 456, 'cid:<Image1@Domain.COM>');

        $this->assertEquals('Image1@Domain.COM', $result['content_id']);
    }

    public function test_cache_hit_content_id_mismatch_misses(): void
    {
        $cacheKey = $this->getCacheKey('123', '456', 'image1@domain.com');

        Cache::shouldReceive('store')->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('get')
            ->once()
            ->with($cacheKey)
            ->andReturn([
                'content_type' => 'image/png',
                'content_id' => 'mismatch@domain.com',
                'content' => 'fake_binary_bytes',
            ]);

        $this->client->shouldReceive('getInlineAttachment')
            ->once()
            ->andReturn(['found' => false]);

        $result = $this->service->getInlineImage(123, 456, 'image1@domain.com');

        $this->assertNull($result);
    }

    public function test_cache_hit_invalid_mime_misses(): void
    {
        $cacheKey = $this->getCacheKey('123', '456', 'image1@domain.com');

        Cache::shouldReceive('store')->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('get')
            ->once()
            ->with($cacheKey)
            ->andReturn([
                'content_type' => 'application/json',
                'content_id' => 'image1@domain.com',
                'content' => 'fake_binary_bytes',
            ]);

        $this->client->shouldReceive('getInlineAttachment')
            ->once()
            ->andReturn(['found' => false]);

        $result = $this->service->getInlineImage(123, 456, 'image1@domain.com');

        $this->assertNull($result);
    }

    public function test_cache_hit_empty_body_misses(): void
    {
        $cacheKey = $this->getCacheKey('123', '456', 'image1@domain.com');

        Cache::shouldReceive('store')->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('get')
            ->once()
            ->with($cacheKey)
            ->andReturn([
                'content_type' => 'image/png',
                'content_id' => 'image1@domain.com',
                'content' => '',
            ]);

        $this->client->shouldReceive('getInlineAttachment')
            ->once()
            ->andReturn(['found' => false]);

        $result = $this->service->getInlineImage(123, 456, 'image1@domain.com');

        $this->assertNull($result);
    }

    public function test_cache_hit_over_25_mib_misses(): void
    {
        $cacheKey = $this->getCacheKey('123', '456', 'image1@domain.com');

        Cache::shouldReceive('store')->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('get')
            ->once()
            ->with($cacheKey)
            ->andReturn([
                'content_type' => 'image/png',
                'content_id' => 'image1@domain.com',
                'content' => str_repeat('a', 25 * 1024 * 1024 + 1),
            ]);

        $this->client->shouldReceive('getInlineAttachment')
            ->once()
            ->andReturn(['found' => false]);

        $result = $this->service->getInlineImage(123, 456, 'image1@domain.com');

        $this->assertNull($result);
    }

    public function test_malformed_cache_misses(): void
    {
        $cacheKey = $this->getCacheKey('123', '456', 'image1@domain.com');

        Cache::shouldReceive('store')->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('get')
            ->once()
            ->with($cacheKey)
            ->andReturn([
                'content_type' => 'image/png',
                // missing id and content
            ]);

        $this->client->shouldReceive('getInlineAttachment')
            ->once()
            ->andReturn(['found' => false]);

        $result = $this->service->getInlineImage(123, 456, 'image1@domain.com');

        $this->assertNull($result);
    }

    public function test_miss_client_called_once_and_caches_binary(): void
    {
        $cacheKey = $this->getCacheKey('123', '456', 'image1@domain.com');

        Cache::shouldReceive('store')->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('get')
            ->once()
            ->andReturn(null);

        $this->client->shouldReceive('getInlineAttachment')
            ->once()
            ->with(123, 456, 'image1@domain.com')
            ->andReturn([
                'found' => true,
                'content_type' => 'image/png',
                'content_id' => 'image1@domain.com',
                'content_base64' => base64_encode('decoded_bytes'),
            ]);

        $this->cacheRepository->shouldReceive('put')
            ->once()
            ->with($cacheKey, [
                'content_type' => 'image/png',
                'content_id' => 'image1@domain.com',
                'content' => 'decoded_bytes',
            ], 3600);

        $result = $this->service->getInlineImage(123, 456, 'image1@domain.com');

        $this->assertEquals('decoded_bytes', $result['content']);
    }

    public function test_miss_invalid_strict_base64_returns_null_no_put(): void
    {
        Cache::shouldReceive('store')->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('get')->once()->andReturn(null);

        $this->client->shouldReceive('getInlineAttachment')
            ->once()
            ->andReturn([
                'found' => true,
                'content_type' => 'image/png',
                'content_id' => 'image1@domain.com',
                'content_base64' => 'invälid=base64',
            ]);

        $this->cacheRepository->shouldNotReceive('put');

        $result = $this->service->getInlineImage(123, 456, 'image1@domain.com');

        $this->assertNull($result);
    }

    public function test_miss_empty_decoded_bytes_returns_null(): void
    {
        Cache::shouldReceive('store')->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('get')->once()->andReturn(null);

        $this->client->shouldReceive('getInlineAttachment')
            ->once()
            ->andReturn([
                'found' => true,
                'content_type' => 'image/png',
                'content_id' => 'image1@domain.com',
                'content_base64' => '',
            ]);

        $this->cacheRepository->shouldNotReceive('put');

        $result = $this->service->getInlineImage(123, 456, 'image1@domain.com');

        $this->assertNull($result);
    }

    public function test_miss_over_25_mib_decoded_bytes_returns_null(): void
    {
        Cache::shouldReceive('store')->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('get')->once()->andReturn(null);

        $this->client->shouldReceive('getInlineAttachment')
            ->once()
            ->andReturn([
                'found' => true,
                'content_type' => 'image/png',
                'content_id' => 'image1@domain.com',
                'content_base64' => base64_encode(str_repeat('a', 25 * 1024 * 1024 + 1)),
            ]);

        $this->cacheRepository->shouldNotReceive('put');

        $result = $this->service->getInlineImage(123, 456, 'image1@domain.com');

        $this->assertNull($result);
    }

    public function test_miss_image_jpg_returns_normalized_image_jpeg(): void
    {
        Cache::shouldReceive('store')->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('get')->once()->andReturn(null);
        $this->cacheRepository->shouldReceive('put')->once();

        $this->client->shouldReceive('getInlineAttachment')
            ->once()
            ->andReturn([
                'found' => true,
                'content_type' => 'image/jpg',
                'content_id' => 'image1@domain.com',
                'content_base64' => base64_encode('bytes'),
            ]);

        $result = $this->service->getInlineImage(123, 456, 'image1@domain.com');

        $this->assertEquals('image/jpeg', $result['content_type']);
    }

    public function test_miss_svg_or_non_allowlisted_mime_returns_null(): void
    {
        Cache::shouldReceive('store')->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('get')->once()->andReturn(null);
        $this->cacheRepository->shouldNotReceive('put');

        $this->client->shouldReceive('getInlineAttachment')
            ->once()
            ->andReturn([
                'found' => true,
                'content_type' => 'image/svg+xml',
                'content_id' => 'image1@domain.com',
                'content_base64' => base64_encode('bytes'),
            ]);

        $result = $this->service->getInlineImage(123, 456, 'image1@domain.com');

        $this->assertNull($result);
    }

    public function test_miss_returned_content_id_mismatch_returns_null(): void
    {
        Cache::shouldReceive('store')->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('get')->once()->andReturn(null);
        $this->cacheRepository->shouldNotReceive('put');

        $this->client->shouldReceive('getInlineAttachment')
            ->once()
            ->andReturn([
                'found' => true,
                'content_type' => 'image/png',
                'content_id' => 'mismatch@domain.com',
                'content_base64' => base64_encode('bytes'),
            ]);

        $result = $this->service->getInlineImage(123, 456, 'image1@domain.com');

        $this->assertNull($result);
    }

    public function test_miss_filesize_raw_exact_match_accepted(): void
    {
        Cache::shouldReceive('store')->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('get')->once()->andReturn(null);
        $this->cacheRepository->shouldReceive('put')->once();

        $bytes = '12345'; // length 5

        $this->client->shouldReceive('getInlineAttachment')
            ->once()
            ->andReturn([
                'found' => true,
                'content_type' => 'image/png',
                'content_id' => 'image1@domain.com',
                'filesize_raw' => 5,
                'content_base64' => base64_encode($bytes),
            ]);

        $result = $this->service->getInlineImage(123, 456, 'image1@domain.com');

        $this->assertNotNull($result);
    }

    public function test_miss_filesize_raw_mismatch_rejected(): void
    {
        Cache::shouldReceive('store')->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('get')->once()->andReturn(null);
        $this->cacheRepository->shouldNotReceive('put');

        $bytes = '12345'; // length 5

        $this->client->shouldReceive('getInlineAttachment')
            ->once()
            ->andReturn([
                'found' => true,
                'content_type' => 'image/png',
                'content_id' => 'image1@domain.com',
                'filesize_raw' => 999, // mismatch
                'content_base64' => base64_encode($bytes),
            ]);

        $result = $this->service->getInlineImage(123, 456, 'image1@domain.com');

        $this->assertNull($result);
    }

    public function test_miss_found_0_returns_null_no_cache_put(): void
    {
        Cache::shouldReceive('store')->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('get')->once()->andReturn(null);
        $this->cacheRepository->shouldNotReceive('put');

        $this->client->shouldReceive('getInlineAttachment')
            ->once()
            ->andReturn(['found' => false]);

        $result = $this->service->getInlineImage(123, 456, 'image1@domain.com');

        $this->assertNull($result);
    }

    public function test_client_exception_propagates(): void
    {
        Cache::shouldReceive('store')->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('get')->once()->andReturn(null);

        $this->client->shouldReceive('getInlineAttachment')
            ->once()
            ->andThrow(new \Exception('API Error'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('API Error');

        $this->service->getInlineImage(123, 456, 'image1@domain.com');
    }

    public function test_hashed_key_namespace_and_identity_behavior(): void
    {
        Cache::shouldReceive('store')->with('redis')->andReturn($this->cacheRepository);

        $expectedKey1 = 'znuny:inline-image:v1:'.hash('sha256', '123|456|image1@domain.com');
        $expectedKey2 = 'znuny:inline-image:v1:'.hash('sha256', '123|999|image1@domain.com');
        $expectedKey3 = 'znuny:inline-image:v1:'.hash('sha256', '999|456|image1@domain.com');
        $expectedKey4 = 'znuny:inline-image:v1:'.hash('sha256', '123|456|image2@domain.com');

        $keys = [$expectedKey1, $expectedKey2, $expectedKey3, $expectedKey4];
        $this->assertCount(4, array_unique($keys), 'Each key combination must produce a unique hash identity');

        $this->cacheRepository->shouldReceive('get')
            ->once()
            ->with($expectedKey1)
            ->andReturn(null);

        $this->client->shouldReceive('getInlineAttachment')
            ->once()
            ->andReturn(['found' => false]);

        $this->service->getInlineImage(123, 456, 'image1@domain.com');

        // Different article ID yields a different cache key
        $this->cacheRepository->shouldReceive('get')
            ->once()
            ->with($expectedKey2)
            ->andReturn(null);

        $this->client->shouldReceive('getInlineAttachment')
            ->once()
            ->andReturn(['found' => false]);

        $this->service->getInlineImage(123, 999, 'image1@domain.com');

        // Different ticket ID yields a different cache key
        $this->cacheRepository->shouldReceive('get')
            ->once()
            ->with($expectedKey3)
            ->andReturn(null);

        $this->client->shouldReceive('getInlineAttachment')
            ->once()
            ->andReturn(['found' => false]);

        $this->service->getInlineImage(999, 456, 'image1@domain.com');

        // Different content ID yields a different cache key
        $this->cacheRepository->shouldReceive('get')
            ->once()
            ->with($expectedKey4)
            ->andReturn(null);

        $this->client->shouldReceive('getInlineAttachment')
            ->once()
            ->andReturn(['found' => false]);

        $this->service->getInlineImage(123, 456, 'image2@domain.com');
    }
}
