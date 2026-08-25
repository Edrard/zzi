<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::clearAllCaches();
    }

    public function test_repeated_reads_do_not_cause_repeated_db_queries()
    {
        Setting::create(['key' => 'test_key_1', 'value' => 'value1', 'type' => 'string']);
        Setting::create(['key' => 'test_key_2', 'value' => 'value2', 'type' => 'string']);

        SettingsService::clearAllCaches();

        DB::enableQueryLog();

        $val1 = SettingsService::string('test_key_1');
        $this->assertEquals('value1', $val1);

        $val2 = SettingsService::string('test_key_2');
        $this->assertEquals('value2', $val2);

        $val1Again = SettingsService::string('test_key_1');
        $this->assertEquals('value1', $val1Again);

        $queries = collect(DB::getQueryLog())->filter(function ($query) {
            return str_contains($query['query'], 'settings');
        });

        // Only 1 query to load all settings initially
        $this->assertCount(1, $queries);
    }

    public function test_persistent_cache_works_across_runtime_cache_reset()
    {
        Setting::create(['key' => 'test_key_persist', 'value' => 'persisted_value', 'type' => 'string']);

        SettingsService::clearAllCaches();

        // 1. Initial read will load from DB and populate both caches
        $val1 = SettingsService::string('test_key_persist');
        $this->assertEquals('persisted_value', $val1);

        // 2. Clear only the runtime cache
        SettingsService::clearRequestCache();

        DB::enableQueryLog();

        // 3. Second read should populate runtime cache from persistent cache, without hitting DB
        $val2 = SettingsService::string('test_key_persist');
        $this->assertEquals('persisted_value', $val2);

        $queries = collect(DB::getQueryLog())->filter(function ($query) {
            return str_contains($query['query'], 'settings');
        });

        // 0 queries!
        $this->assertCount(0, $queries);
    }

    public function test_invalidation_works_on_update()
    {
        $setting = Setting::create(['key' => 'test_key_update', 'value' => 'old_value', 'type' => 'string']);

        SettingsService::clearAllCaches();

        $val1 = SettingsService::string('test_key_update');
        $this->assertEquals('old_value', $val1);

        // Update the setting through Eloquent
        $setting->update(['value' => 'new_value']);

        // Next read should see the new value because the observer/booted event cleared caches
        $val2 = SettingsService::string('test_key_update');
        $this->assertEquals('new_value', $val2);
    }

    public function test_invalidation_works_on_delete()
    {
        $setting = Setting::create(['key' => 'test_key_delete', 'value' => 'deleted_value', 'type' => 'string']);

        SettingsService::clearAllCaches();

        $val1 = SettingsService::string('test_key_delete');
        $this->assertEquals('deleted_value', $val1);

        // Delete the setting
        $setting->delete();

        // Next read should see null or default
        $val2 = SettingsService::string('test_key_delete');
        $this->assertNull($val2);
    }

    public function test_invalidation_works_on_create()
    {
        SettingsService::clearAllCaches();

        $val1 = SettingsService::string('test_key_create');
        $this->assertNull($val1);

        Setting::create(['key' => 'test_key_create', 'value' => 'created_value', 'type' => 'string']);

        $val2 = SettingsService::string('test_key_create');
        $this->assertEquals('created_value', $val2);
    }

    public function test_existing_encrypted_secret_behavior_remains_unchanged()
    {
        SettingsService::clearAllCaches();

        $plaintext = 'super_secret';
        $encrypted = SettingsService::encryptForStorage('znuny_password', $plaintext);

        Setting::updateOrCreate(
            ['key' => 'znuny_password'],
            ['value' => $encrypted, 'type' => 'string']
        );

        $read = SettingsService::string('znuny_password');

        $this->assertEquals($plaintext, $read);
    }
}
