<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\SettingsService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ZnunyInlineImageService;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ZnunyInlineImageServiceTest extends TestCase
{
    private ZnunyClient $client;

    private ZnunyInlineImageService $service;

    private Repository $cacheRepository;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::shouldReceive('rememberForever')->andReturn(collect());
        $this->client = Mockery::mock(ZnunyClient::class);
        $this->cacheRepository = Mockery::mock(Repository::class);
        $this->service = new ZnunyInlineImageService($this->client);
    }

    protected function tearDown(): void
    {
        SettingsService::clearRequestCache();
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
            ->with(config('znuny.inline_image_cache_store', 'redis'))
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

        Cache::shouldReceive('store')->with(config('znuny.inline_image_cache_store', 'redis'))->andReturn($this->cacheRepository);
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

        Cache::shouldReceive('store')->with(config('znuny.inline_image_cache_store', 'redis'))->andReturn($this->cacheRepository);
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

        Cache::shouldReceive('store')->with(config('znuny.inline_image_cache_store', 'redis'))->andReturn($this->cacheRepository);
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

        Cache::shouldReceive('store')->with(config('znuny.inline_image_cache_store', 'redis'))->andReturn($this->cacheRepository);
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

        Cache::shouldReceive('store')->with(config('znuny.inline_image_cache_store', 'redis'))->andReturn($this->cacheRepository);
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

        Cache::shouldReceive('store')->with(config('znuny.inline_image_cache_store', 'redis'))->andReturn($this->cacheRepository);
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

        Cache::shouldReceive('store')->with(config('znuny.inline_image_cache_store', 'redis'))->andReturn($this->cacheRepository);
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

        Cache::shouldReceive('store')->with(config('znuny.inline_image_cache_store', 'redis'))->andReturn($this->cacheRepository);
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

    public function test_miss_uses_explicit_cache_ttl_seconds_from_settings(): void
    {
        $cacheKey = $this->getCacheKey('123', '456', 'image1@domain.com');

        Cache::shouldReceive('store')->with(config('znuny.inline_image_cache_store', 'redis'))->andReturn($this->cacheRepository);
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

        // Reflection is used here to narrowly seed the private static SettingsService
        // request cache without complex Cache facade mocking, since this test
        // already heavily mocks Cache::store() to verify put() behavior.
        $prop = new \ReflectionProperty(SettingsService::class, 'requestCache');
        $prop->setAccessible(true);
        $prop->setValue(null, [
            'znuny_inline_image_cache_ttl_minutes' => (object) [
                'key' => 'znuny_inline_image_cache_ttl_minutes',
                'value' => '120',
                'type' => 'integer',
            ],
        ]);

        $propLoaded = new \ReflectionProperty(SettingsService::class, 'allLoaded');
        $propLoaded->setAccessible(true);
        $propLoaded->setValue(null, true);

        $this->cacheRepository->shouldReceive('put')
            ->once()
            ->with($cacheKey, [
                'content_type' => 'image/png',
                'content_id' => 'image1@domain.com',
                'content' => 'decoded_bytes',
            ], 7200); // 120 minutes * 60 = 7200 seconds

        $result = $this->service->getInlineImage(123, 456, 'image1@domain.com');

        $this->assertEquals('decoded_bytes', $result['content']);
    }

    public function test_miss_invalid_strict_base64_returns_null_no_put(): void
    {
        Cache::shouldReceive('store')->with(config('znuny.inline_image_cache_store', 'redis'))->andReturn($this->cacheRepository);
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
        Cache::shouldReceive('store')->with(config('znuny.inline_image_cache_store', 'redis'))->andReturn($this->cacheRepository);
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
        Cache::shouldReceive('store')->with(config('znuny.inline_image_cache_store', 'redis'))->andReturn($this->cacheRepository);
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
        Cache::shouldReceive('store')->with(config('znuny.inline_image_cache_store', 'redis'))->andReturn($this->cacheRepository);
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
        Cache::shouldReceive('store')->with(config('znuny.inline_image_cache_store', 'redis'))->andReturn($this->cacheRepository);
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
        Cache::shouldReceive('store')->with(config('znuny.inline_image_cache_store', 'redis'))->andReturn($this->cacheRepository);
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
        Cache::shouldReceive('store')->with(config('znuny.inline_image_cache_store', 'redis'))->andReturn($this->cacheRepository);
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
        Cache::shouldReceive('store')->with(config('znuny.inline_image_cache_store', 'redis'))->andReturn($this->cacheRepository);
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
        Cache::shouldReceive('store')->with(config('znuny.inline_image_cache_store', 'redis'))->andReturn($this->cacheRepository);
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
        Cache::shouldReceive('store')->with(config('znuny.inline_image_cache_store', 'redis'))->andReturn($this->cacheRepository);
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
        Cache::shouldReceive('store')->with(config('znuny.inline_image_cache_store', 'redis'))->andReturn($this->cacheRepository);

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

    public function test_redis_fallback_configuration_structure(): void
    {
        $keys = [
            'REDIS_INLINE_IMAGE_DB',
            'REDIS_CACHE_DB',
            'ZNUNY_INLINE_IMAGE_CACHE_STORE',
            'CACHE_STORE',
        ];

        $original = [];

        foreach ($keys as $key) {
            $original[$key] = [
                'getenv' => getenv($key),
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];
        }

        try {
            putenv('REDIS_INLINE_IMAGE_DB');
            unset($_ENV['REDIS_INLINE_IMAGE_DB'], $_SERVER['REDIS_INLINE_IMAGE_DB']);

            putenv('REDIS_CACHE_DB=88');
            $_ENV['REDIS_CACHE_DB'] = '88';
            $_SERVER['REDIS_CACHE_DB'] = '88';

            $dbConfig = require config_path('database.php');
            $this->assertEquals('88', $dbConfig['redis']['inline_images']['database']);

            putenv('REDIS_INLINE_IMAGE_DB=99');
            $_ENV['REDIS_INLINE_IMAGE_DB'] = '99';
            $_SERVER['REDIS_INLINE_IMAGE_DB'] = '99';

            $dbConfig = require config_path('database.php');
            $this->assertEquals('99', $dbConfig['redis']['inline_images']['database']);

            $cacheConfig = require config_path('cache.php');
            $this->assertEquals('inline_images', $cacheConfig['stores']['znuny_inline_images']['connection']);

            putenv('ZNUNY_INLINE_IMAGE_CACHE_STORE');
            unset($_ENV['ZNUNY_INLINE_IMAGE_CACHE_STORE'], $_SERVER['ZNUNY_INLINE_IMAGE_CACHE_STORE']);

            putenv('CACHE_STORE=sentinel_store');
            $_ENV['CACHE_STORE'] = 'sentinel_store';
            $_SERVER['CACHE_STORE'] = 'sentinel_store';

            $znunyConfig = require config_path('znuny.php');
            $this->assertEquals('sentinel_store', $znunyConfig['inline_image_cache_store']);

            putenv('ZNUNY_INLINE_IMAGE_CACHE_STORE=specific_store');
            $_ENV['ZNUNY_INLINE_IMAGE_CACHE_STORE'] = 'specific_store';
            $_SERVER['ZNUNY_INLINE_IMAGE_CACHE_STORE'] = 'specific_store';

            $znunyConfig = require config_path('znuny.php');
            $this->assertEquals('specific_store', $znunyConfig['inline_image_cache_store']);
        } finally {
            foreach ($original as $key => $state) {
                if ($state['getenv'] === false) {
                    putenv($key);
                } else {
                    putenv($key.'='.$state['getenv']);
                }

                if ($state['env_exists']) {
                    $_ENV[$key] = $state['env'];
                } else {
                    unset($_ENV[$key]);
                }

                if ($state['server_exists']) {
                    $_SERVER[$key] = $state['server'];
                } else {
                    unset($_SERVER[$key]);
                }
            }
        }
    }

    public function test_warmer_config_fallback_and_bounds(): void
    {
        $keys = [
            'ZNUNY_INLINE_IMAGE_WARMER_BATCH_SIZE',
            'ZNUNY_INLINE_IMAGE_WARMER_HOT_PERCENTAGE',
        ];

        $original = [];

        foreach ($keys as $key) {
            $original[$key] = [
                'getenv' => getenv($key),
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];
        }

        try {
            putenv('ZNUNY_INLINE_IMAGE_WARMER_BATCH_SIZE');
            unset($_ENV['ZNUNY_INLINE_IMAGE_WARMER_BATCH_SIZE'], $_SERVER['ZNUNY_INLINE_IMAGE_WARMER_BATCH_SIZE']);

            putenv('ZNUNY_INLINE_IMAGE_WARMER_HOT_PERCENTAGE');
            unset($_ENV['ZNUNY_INLINE_IMAGE_WARMER_HOT_PERCENTAGE'], $_SERVER['ZNUNY_INLINE_IMAGE_WARMER_HOT_PERCENTAGE']);

            $znunyConfig = require config_path('znuny.php');
            $this->assertEquals(50, $znunyConfig['inline_image_warmer_batch_size']);
            $this->assertEquals(10, $znunyConfig['inline_image_warmer_hot_percentage']);

            putenv('ZNUNY_INLINE_IMAGE_WARMER_BATCH_SIZE=200');
            $_ENV['ZNUNY_INLINE_IMAGE_WARMER_BATCH_SIZE'] = '200';
            $_SERVER['ZNUNY_INLINE_IMAGE_WARMER_BATCH_SIZE'] = '200';

            putenv('ZNUNY_INLINE_IMAGE_WARMER_HOT_PERCENTAGE=30');
            $_ENV['ZNUNY_INLINE_IMAGE_WARMER_HOT_PERCENTAGE'] = '30';
            $_SERVER['ZNUNY_INLINE_IMAGE_WARMER_HOT_PERCENTAGE'] = '30';

            $znunyConfig = require config_path('znuny.php');
            $this->assertEquals(200, $znunyConfig['inline_image_warmer_batch_size']);
            $this->assertEquals(30, $znunyConfig['inline_image_warmer_hot_percentage']);

            // Test clamping min
            putenv('ZNUNY_INLINE_IMAGE_WARMER_BATCH_SIZE=0');
            $_ENV['ZNUNY_INLINE_IMAGE_WARMER_BATCH_SIZE'] = '0';
            $_SERVER['ZNUNY_INLINE_IMAGE_WARMER_BATCH_SIZE'] = '0';

            putenv('ZNUNY_INLINE_IMAGE_WARMER_HOT_PERCENTAGE=-5');
            $_ENV['ZNUNY_INLINE_IMAGE_WARMER_HOT_PERCENTAGE'] = '-5';
            $_SERVER['ZNUNY_INLINE_IMAGE_WARMER_HOT_PERCENTAGE'] = '-5';

            $znunyConfig = require config_path('znuny.php');
            $this->assertEquals(1, $znunyConfig['inline_image_warmer_batch_size']);
            $this->assertEquals(1, $znunyConfig['inline_image_warmer_hot_percentage']);

            // Test clamping max
            putenv('ZNUNY_INLINE_IMAGE_WARMER_BATCH_SIZE=2000');
            $_ENV['ZNUNY_INLINE_IMAGE_WARMER_BATCH_SIZE'] = '2000';
            $_SERVER['ZNUNY_INLINE_IMAGE_WARMER_BATCH_SIZE'] = '2000';

            putenv('ZNUNY_INLINE_IMAGE_WARMER_HOT_PERCENTAGE=150');
            $_ENV['ZNUNY_INLINE_IMAGE_WARMER_HOT_PERCENTAGE'] = '150';
            $_SERVER['ZNUNY_INLINE_IMAGE_WARMER_HOT_PERCENTAGE'] = '150';

            $znunyConfig = require config_path('znuny.php');
            $this->assertEquals(1000, $znunyConfig['inline_image_warmer_batch_size']);
            $this->assertEquals(100, $znunyConfig['inline_image_warmer_hot_percentage']);

        } finally {
            foreach ($original as $key => $state) {
                if ($state['getenv'] === false) {
                    putenv($key);
                } else {
                    putenv($key.'='.$state['getenv']);
                }

                if ($state['env_exists']) {
                    $_ENV[$key] = $state['env'];
                } else {
                    unset($_ENV[$key]);
                }

                if ($state['server_exists']) {
                    $_SERVER[$key] = $state['server'];
                } else {
                    unset($_SERVER[$key]);
                }
            }
        }
    }

    public function test_effective_ttl_clamping_and_cache_usage(): void
    {
        SettingsService::clearRequestCache();
        $this->assertEquals(60 * 60, $this->service->getCacheTtlSeconds()); // Default 60 minutes

        $setSetting = function ($value) {
            $prop = new \ReflectionProperty(SettingsService::class, 'requestCache');
            $prop->setAccessible(true);
            $prop->setValue(null, ['znuny_inline_image_cache_ttl_minutes' => (object) ['key' => 'znuny_inline_image_cache_ttl_minutes', 'value' => (string) $value, 'type' => 'integer']]);

            $propLoaded = new \ReflectionProperty(SettingsService::class, 'allLoaded');
            $propLoaded->setAccessible(true);
            $propLoaded->setValue(null, true);
        };

        $setSetting(0);
        $this->assertEquals(1 * 60, $this->service->getCacheTtlSeconds());

        $setSetting(20000);
        $this->assertEquals(10080 * 60, $this->service->getCacheTtlSeconds());

        $setSetting(120);
        $this->assertEquals(120 * 60, $this->service->getCacheTtlSeconds());
    }
}
