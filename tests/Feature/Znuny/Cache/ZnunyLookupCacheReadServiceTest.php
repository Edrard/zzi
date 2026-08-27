<?php

namespace Tests\Feature\Znuny\Cache;

use App\Services\Znuny\Cache\PrewarmSnapshotManager;
use App\Services\Znuny\Cache\ZnunyLookupCacheReadService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ZnunyLookupCacheReadServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
    }

    public function test_missing_snapshot_returns_null_and_empty_arrays()
    {
        $service = new ZnunyLookupCacheReadService();
        $this->assertNull($service->getSnapshot());
        $this->assertEquals([], $service->getStates());
        $this->assertEquals([], $service->getPriorities());
        $this->assertEquals([], $service->getTypes());
    }

    public function test_successful_coherent_option_map_shape_and_metadata()
    {
        Cache::put('znuny_prewarm_lookups_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', [
            'states' => ['open' => 'open'],
            'priorities' => ['high' => 'high'],
            'types' => ['incident' => 'incident'],
        ], now()->addMinutes(10));

        $service = new ZnunyLookupCacheReadService();
        $snapshot = $service->getSnapshot();

        $this->assertEquals('gen_1', $snapshot['generation']);
        $this->assertEquals(['open' => 'open'], $snapshot['states']);
        $this->assertEquals(['high' => 'high'], $snapshot['priorities']);
        $this->assertEquals(['incident' => 'incident'], $snapshot['types']);
        $this->assertEquals('ready', $snapshot['metadata']['status']);
    }

    public function test_successful_coherent_option_map_with_integer_key()
    {
        Cache::put('znuny_prewarm_lookups_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', [
            'states' => [123 => '123'],
            'priorities' => ['high' => 'high'],
            'types' => ['incident' => 'incident'],
        ], now()->addMinutes(10));

        $service = new ZnunyLookupCacheReadService();
        $snapshot = $service->getSnapshot();

        $this->assertEquals('gen_1', $snapshot['generation']);
        $this->assertEquals([123 => '123'], $snapshot['states']);
    }

    public function test_convenience_methods_return_exactly_same_maps()
    {
        Cache::put('znuny_prewarm_lookups_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', [
            'states' => ['open' => 'open'],
            'priorities' => ['high' => 'high'],
            'types' => ['incident' => 'incident'],
        ], now()->addMinutes(10));

        $service = new ZnunyLookupCacheReadService();
        $this->assertEquals(['open' => 'open'], $service->getStates());
        $this->assertEquals(['high' => 'high'], $service->getPriorities());
        $this->assertEquals(['incident' => 'incident'], $service->getTypes());
    }

    public function test_metadata_remains_while_payload_is_missing_returns_null_and_empty_arrays()
    {
        Cache::put('znuny_prewarm_lookups_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));

        $service = new ZnunyLookupCacheReadService();
        $this->assertNull($service->getSnapshot());
        $this->assertEquals([], $service->getStates());
        $this->assertEquals([], $service->getPriorities());
        $this->assertEquals([], $service->getTypes());
    }

    public function test_scalar_payload_returns_null_and_empty_arrays()
    {
        Cache::put('znuny_prewarm_lookups_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', 'scalar', now()->addMinutes(10));

        $service = new ZnunyLookupCacheReadService();
        $this->assertNull($service->getSnapshot());
        $this->assertEquals([], $service->getStates());
        $this->assertEquals([], $service->getPriorities());
        $this->assertEquals([], $service->getTypes());
    }

    #[DataProvider('missingCategoryProvider')]
    public function test_missing_required_category_returns_null(array $payload)
    {
        Cache::put('znuny_prewarm_lookups_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', $payload, now()->addMinutes(10));

        $service = new ZnunyLookupCacheReadService();
        $this->assertNull($service->getSnapshot());
        $this->assertEquals([], $service->getStates());
        $this->assertEquals([], $service->getPriorities());
        $this->assertEquals([], $service->getTypes());
    }

    public static function missingCategoryProvider(): array
    {
        return [
            'missing states' => [[
                'priorities' => ['high' => 'high'],
                'types' => ['incident' => 'incident'],
            ]],
            'missing priorities' => [[
                'states' => ['open' => 'open'],
                'types' => ['incident' => 'incident'],
            ]],
            'missing types' => [[
                'states' => ['open' => 'open'],
                'priorities' => ['high' => 'high'],
            ]],
        ];
    }

    #[DataProvider('nonArrayCategoryProvider')]
    public function test_non_array_category_returns_null(array $payload)
    {
        Cache::put('znuny_prewarm_lookups_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', $payload, now()->addMinutes(10));

        $service = new ZnunyLookupCacheReadService();
        $this->assertNull($service->getSnapshot());
        $this->assertEquals([], $service->getStates());
        $this->assertEquals([], $service->getPriorities());
        $this->assertEquals([], $service->getTypes());
    }

    public static function nonArrayCategoryProvider(): array
    {
        return [
            'states string' => [[
                'states' => 'not an array',
                'priorities' => ['high' => 'high'],
                'types' => ['incident' => 'incident'],
            ]],
            'priorities string' => [[
                'states' => ['open' => 'open'],
                'priorities' => 'not an array',
                'types' => ['incident' => 'incident'],
            ]],
            'types string' => [[
                'states' => ['open' => 'open'],
                'priorities' => ['high' => 'high'],
                'types' => 'not an array',
            ]],
        ];
    }

    #[DataProvider('emptyCategoryProvider')]
    public function test_empty_category_returns_null(array $payload)
    {
        Cache::put('znuny_prewarm_lookups_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', $payload, now()->addMinutes(10));

        $service = new ZnunyLookupCacheReadService();
        $this->assertNull($service->getSnapshot());
        $this->assertEquals([], $service->getStates());
        $this->assertEquals([], $service->getPriorities());
        $this->assertEquals([], $service->getTypes());
    }

    public static function emptyCategoryProvider(): array
    {
        return [
            'empty states' => [[
                'states' => [],
                'priorities' => ['high' => 'high'],
                'types' => ['incident' => 'incident'],
            ]],
            'empty priorities' => [[
                'states' => ['open' => 'open'],
                'priorities' => [],
                'types' => ['incident' => 'incident'],
            ]],
            'empty types' => [[
                'states' => ['open' => 'open'],
                'priorities' => ['high' => 'high'],
                'types' => [],
            ]],
        ];
    }

    #[DataProvider('malformedCategoryProvider')]
    public function test_malformed_key_value_pairs_returns_null(array $payload)
    {
        Cache::put('znuny_prewarm_lookups_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', $payload, now()->addMinutes(10));

        $service = new ZnunyLookupCacheReadService();
        $this->assertNull($service->getSnapshot());
        $this->assertEquals([], $service->getStates());
        $this->assertEquals([], $service->getPriorities());
        $this->assertEquals([], $service->getTypes());
    }

    public static function malformedCategoryProvider(): array
    {
        return [
            'numeric key mismatch' => [[
                'states' => [1 => '2'],
                'priorities' => ['high' => 'high'],
                'types' => ['incident' => 'incident'],
            ]],
            'non-string value' => [[
                'states' => ['open' => 1],
                'priorities' => ['high' => 'high'],
                'types' => ['incident' => 'incident'],
            ]],
            'blank key' => [[
                'states' => [' ' => ' '],
                'priorities' => ['high' => 'high'],
                'types' => ['incident' => 'incident'],
            ]],
            'blank value' => [[
                'states' => ['open' => '   '],
                'priorities' => ['high' => 'high'],
                'types' => ['incident' => 'incident'],
            ]],
            'key value mismatch' => [[
                'states' => ['open' => 'closed'],
                'priorities' => ['high' => 'high'],
                'types' => ['incident' => 'incident'],
            ]],
            'surrounding whitespace key' => [[
                'states' => [' open ' => ' open '],
                'priorities' => ['high' => 'high'],
                'types' => ['incident' => 'incident'],
            ]],
        ];
    }

    public function test_cache_only_behavior_sends_no_http_requests()
    {
        Http::fake();

        Cache::put('znuny_prewarm_lookups_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', [
            'states' => ['open' => 'open'],
            'priorities' => ['high' => 'high'],
            'types' => ['incident' => 'incident'],
        ], now()->addMinutes(10));

        $service = new ZnunyLookupCacheReadService();
        $this->assertEquals(['open' => 'open'], $service->getStates());

        Http::assertNothingSent();
    }

    public function test_reads_perform_no_cache_writes()
    {
        $mock = $this->createMock(PrewarmSnapshotManager::class);
        $mock->expects($this->once())->method('readActiveSnapshot')->willReturn(null);
        $mock->expects($this->never())->method('refresh');

        Cache::shouldReceive('put')->never();
        Cache::shouldReceive('forever')->never();

        $service = new ZnunyLookupCacheReadService($mock);
        $this->assertNull($service->getSnapshot());
    }

    public function test_metadata_exposure_remains_normalized()
    {
        $service = new ZnunyLookupCacheReadService();
        $meta = $service->getMetadata();

        $this->assertEquals('lookups', $meta['dataset_name']);
        $this->assertEquals('missing', $meta['status']);
    }

    public function test_old_snapshot_without_customer_companies_is_valid_and_returns_empty_companies()
    {
        Cache::put('znuny_prewarm_lookups_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', [
            'states' => ['open' => 'open'],
            'priorities' => ['high' => 'high'],
            'types' => ['incident' => 'incident'],
            // no customer_companies
        ], now()->addMinutes(10));

        $service = new ZnunyLookupCacheReadService();
        $snapshot = $service->getSnapshot();

        $this->assertNotNull($snapshot);
        $this->assertEquals(['open' => 'open'], $service->getStates());
        $this->assertEquals(['high' => 'high'], $service->getPriorities());
        $this->assertEquals(['incident' => 'incident'], $service->getTypes());
        $this->assertEquals([], $service->getCustomerCompanies());
    }

    public function test_new_snapshot_with_customer_companies_returns_exact_map()
    {
        Cache::put('znuny_prewarm_lookups_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', [
            'states' => ['open' => 'open'],
            'priorities' => ['high' => 'high'],
            'types' => ['incident' => 'incident'],
            'customer_companies' => ['c1' => 'Company 1', 'c2' => 'Company 2'],
        ], now()->addMinutes(10));

        $service = new ZnunyLookupCacheReadService();
        $snapshot = $service->getSnapshot();

        $this->assertNotNull($snapshot);
        $this->assertEquals(['c1' => 'Company 1', 'c2' => 'Company 2'], $service->getCustomerCompanies());
    }

    public static function malformedCompaniesProvider(): array
    {
        return [
            'not an array' => ['string'],
            'empty key' => [['' => 'name']],
            'invalid value array' => [['c1' => ['name']]],
            'invalid value object' => [['c1' => (object)['name']]],
            'boolean value' => [['c1' => true]],
            'numeric value' => [['c1' => 123]],
            'empty string value' => [['c1' => '']],
        ];
    }

    #[DataProvider('malformedCompaniesProvider')]
    public function test_malformed_customer_companies_returns_empty_array_and_preserves_others($malformedCompanies)
    {

        Cache::put('znuny_prewarm_lookups_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', [
            'states' => ['open' => 'open'],
            'priorities' => ['high' => 'high'],
            'types' => ['incident' => 'incident'],
            'customer_companies' => $malformedCompanies,
        ], now()->addMinutes(10));

        $service = new ZnunyLookupCacheReadService();
        $snapshot = $service->getSnapshot();

        $this->assertNotNull($snapshot);
        $this->assertEquals(['open' => 'open'], $service->getStates());
        $this->assertEquals(['high' => 'high'], $service->getPriorities());
        $this->assertEquals(['incident' => 'incident'], $service->getTypes());
        $this->assertEquals([], $service->getCustomerCompanies());
    }

    public function test_has_customer_company()
    {
        Cache::put('znuny_prewarm_lookups_meta', [
            'active_generation' => 'gen_1',
            'status' => 'ready',
        ], now()->addMinutes(10));
        Cache::put('gen_1', [
            'states' => ['open' => 'open'],
            'priorities' => ['high' => 'high'],
            'types' => ['incident' => 'incident'],
            'customer_companies' => [
                'agrotekhnik' => 'agrotekhnik Agrotekhnik',
                'finbert' => 'finbert Finbert',
            ],
        ], now()->addMinutes(10));

        $service = new ZnunyLookupCacheReadService();

        $this->assertTrue($service->hasCustomerCompany('agrotekhnik'));
        $this->assertTrue($service->hasCustomerCompany('finbert'));
        $this->assertFalse($service->hasCustomerCompany('oleksandr.ustinov@tmm.ua'));
        $this->assertFalse($service->hasCustomerCompany(''));
    }
}
