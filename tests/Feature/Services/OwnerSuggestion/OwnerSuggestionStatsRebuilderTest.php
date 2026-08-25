<?php

namespace Tests\Feature\Services\OwnerSuggestion;

use App\Models\Setting;
use App\Models\ZnunyOwnerSuggestionObservation;
use App\Models\ZnunyOwnerSuggestionStat;
use App\Services\OwnerSuggestion\OwnerSuggestionStatsRebuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerSuggestionStatsRebuilderTest extends TestCase
{
    use RefreshDatabase;

    private OwnerSuggestionStatsRebuilder $rebuilder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rebuilder = new OwnerSuggestionStatsRebuilder;

        // Set default settings
        Setting::updateOrCreate(['key' => 'owner_suggestion_statistics_retention_days'], ['value' => '70']);
        Setting::updateOrCreate(['key' => 'owner_suggestion_old_weight_coefficient'], ['value' => '0.5']);
        Setting::updateOrCreate(['key' => 'owner_suggestion_observation_cleanup_days'], ['value' => '360']);
    }

    public function test_rebuild_creates_aggregate_stats()
    {
        $now = Carbon::now();

        // Create 3 recent observations for same group
        Carbon::setTestNow($now->copy()->subDays(10));
        ZnunyOwnerSuggestionObservation::create([
            'problem_name' => 'Original A',
            'normalized_problem_key' => 'norm_key',
            'queue_name' => 'QueueA',
            'owner_id' => 10,
            'owner_login' => 'user_10',
        ]);

        Carbon::setTestNow($now->copy()->subDays(5));
        ZnunyOwnerSuggestionObservation::create([
            'problem_name' => 'Original B',
            'normalized_problem_key' => 'norm_key',
            'queue_name' => 'QueueA',
            'owner_id' => 10,
            'owner_login' => 'user_10',
        ]);

        Carbon::setTestNow($now->copy()->subDays(1));
        ZnunyOwnerSuggestionObservation::create([
            'problem_name' => 'Original C',
            'normalized_problem_key' => 'norm_key',
            'queue_name' => 'QueueA',
            'owner_id' => 10,
            'owner_login' => 'user_10',
        ]);
        Carbon::setTestNow(); // reset

        $summary = $this->rebuilder->rebuild();

        $this->assertEquals(3, $summary['observations_scanned']);
        $this->assertEquals(1, $summary['stats_written']);
        $this->assertEquals(0, $summary['raw_deleted']);

        $stats = ZnunyOwnerSuggestionStat::all();
        $this->assertCount(1, $stats);

        $stat = $stats->first();
        $this->assertEquals('norm_key', $stat->normalized_problem_key);
        $this->assertEquals('QueueA', $stat->queue_name);
        $this->assertEquals(10, $stat->owner_id);
        $this->assertEquals('user_10', $stat->owner_login);

        $this->assertEquals(3, $stat->sample_count);
        $this->assertEquals(3, $stat->recent_count);
        $this->assertEquals(0, $stat->old_count);
        $this->assertEquals(3.0, $stat->score);

        // The max created_at was 1 day ago
        $this->assertTrue($stat->last_seen_at->isSameSecond($now->copy()->subDays(1)));
        $this->assertNotNull($stat->calculated_at);
    }

    public function test_old_observations_are_weighted()
    {
        $now = Carbon::now();

        // 2 recent observations (< 70 days)
        Carbon::setTestNow($now->copy()->subDays(10));
        for ($i = 0; $i < 2; $i++) {
            ZnunyOwnerSuggestionObservation::create([
                'problem_name' => 'Prob',
                'normalized_problem_key' => 'key1',
                'queue_name' => 'Q1',
                'owner_id' => 1,
            ]);
        }

        // 4 old observations (>= 70 days but < 360 days)
        Carbon::setTestNow($now->copy()->subDays(80));
        for ($i = 0; $i < 4; $i++) {
            ZnunyOwnerSuggestionObservation::create([
                'problem_name' => 'Prob',
                'normalized_problem_key' => 'key1',
                'queue_name' => 'Q1',
                'owner_id' => 1,
            ]);
        }
        Carbon::setTestNow();

        $summary = $this->rebuilder->rebuild();

        $this->assertEquals(6, $summary['observations_scanned']);
        $this->assertEquals(1, $summary['stats_written']);

        $stat = ZnunyOwnerSuggestionStat::first();
        $this->assertEquals(6, $stat->sample_count);
        $this->assertEquals(2, $stat->recent_count);
        $this->assertEquals(4, $stat->old_count);

        // score = 2 + (4 * 0.5) = 4.0
        $this->assertEquals(4.0, $stat->score);
    }

    public function test_different_owners_stay_separate()
    {
        ZnunyOwnerSuggestionObservation::create([
            'problem_name' => 'Prob',
            'normalized_problem_key' => 'key1',
            'queue_name' => 'Q1',
            'owner_id' => 1,
        ]);

        ZnunyOwnerSuggestionObservation::create([
            'problem_name' => 'Prob',
            'normalized_problem_key' => 'key1',
            'queue_name' => 'Q1',
            'owner_id' => 2,
        ]);

        $this->rebuilder->rebuild();

        $this->assertCount(2, ZnunyOwnerSuggestionStat::all());
    }

    public function test_different_queues_stay_separate()
    {
        ZnunyOwnerSuggestionObservation::create([
            'problem_name' => 'Prob',
            'normalized_problem_key' => 'key1',
            'queue_name' => 'Queue A',
            'owner_id' => 1,
        ]);

        ZnunyOwnerSuggestionObservation::create([
            'problem_name' => 'Prob',
            'normalized_problem_key' => 'key1',
            'queue_name' => 'Queue B',
            'owner_id' => 1,
        ]);

        $this->rebuilder->rebuild();

        $this->assertCount(2, ZnunyOwnerSuggestionStat::all());
    }

    public function test_cleanup_deletes_old_raw_observations()
    {
        $now = Carbon::now();

        // Older than 360 days
        Carbon::setTestNow($now->copy()->subDays(361));
        $oldObs = ZnunyOwnerSuggestionObservation::create([
            'problem_name' => 'Old',
            'normalized_problem_key' => 'old_key',
            'queue_name' => 'Q1',
            'owner_id' => 1,
        ]);

        // Recent
        Carbon::setTestNow($now->copy()->subDays(10));
        $newObs = ZnunyOwnerSuggestionObservation::create([
            'problem_name' => 'New',
            'normalized_problem_key' => 'new_key',
            'queue_name' => 'Q1',
            'owner_id' => 1,
        ]);
        Carbon::setTestNow();

        $summary = $this->rebuilder->rebuild();

        $this->assertEquals(1, $summary['raw_deleted']);
        $this->assertDatabaseMissing('znuny_owner_suggestion_observations', ['id' => $oldObs->id]);
        $this->assertDatabaseHas('znuny_owner_suggestion_observations', ['id' => $newObs->id]);

        $this->assertCount(1, ZnunyOwnerSuggestionStat::all());
    }

    public function test_owner_login_only_observation_is_aggregated()
    {
        ZnunyOwnerSuggestionObservation::create([
            'problem_name' => 'Prob',
            'normalized_problem_key' => 'key1',
            'queue_name' => 'Q1',
            'owner_id' => null,
            'owner_login' => 'system_user',
        ]);

        $this->rebuilder->rebuild();

        $stat = ZnunyOwnerSuggestionStat::first();
        $this->assertNotNull($stat);
        $this->assertNull($stat->owner_id);
        $this->assertEquals('system_user', $stat->owner_login);
    }

    public function test_rebuild_is_full_refresh()
    {
        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'stale_key',
            'queue_name' => 'Q1',
            'owner_id' => 99,
            'sample_count' => 1,
            'recent_count' => 1,
            'old_count' => 0,
            'score' => 1.0,
            'last_seen_at' => Carbon::now(),
            'calculated_at' => Carbon::now(),
        ]);

        ZnunyOwnerSuggestionObservation::create([
            'problem_name' => 'Fresh',
            'normalized_problem_key' => 'fresh_key',
            'queue_name' => 'Q2',
            'owner_id' => 100,
        ]);

        $this->rebuilder->rebuild();

        $stats = ZnunyOwnerSuggestionStat::all();
        $this->assertCount(1, $stats);

        $this->assertEquals('fresh_key', $stats->first()->normalized_problem_key);
        $this->assertDatabaseMissing('znuny_owner_suggestion_stats', ['normalized_problem_key' => 'stale_key']);
    }

    public function test_latest_non_empty_owner_login_wins()
    {
        $now = Carbon::now();

        Carbon::setTestNow($now->copy()->subDays(2));
        ZnunyOwnerSuggestionObservation::create([
            'problem_name' => 'Prob',
            'normalized_problem_key' => 'key1',
            'queue_name' => 'Q1',
            'owner_id' => 1,
            'owner_login' => 'old_login',
        ]);

        Carbon::setTestNow($now->copy()->subDays(1));
        ZnunyOwnerSuggestionObservation::create([
            'problem_name' => 'Prob',
            'normalized_problem_key' => 'key1',
            'queue_name' => 'Q1',
            'owner_id' => 1,
            'owner_login' => 'new_login',
        ]);
        Carbon::setTestNow();

        $this->rebuilder->rebuild();

        $stat = ZnunyOwnerSuggestionStat::first();
        $this->assertNotNull($stat);
        $this->assertEquals('new_login', $stat->owner_login);
    }

    public function test_empty_newer_owner_login_does_not_overwrite_older_non_empty()
    {
        $now = Carbon::now();

        Carbon::setTestNow($now->copy()->subDays(2));
        ZnunyOwnerSuggestionObservation::create([
            'problem_name' => 'Prob',
            'normalized_problem_key' => 'key1',
            'queue_name' => 'Q1',
            'owner_id' => 1,
            'owner_login' => 'valid_login',
        ]);

        Carbon::setTestNow($now->copy()->subDays(1));
        ZnunyOwnerSuggestionObservation::create([
            'problem_name' => 'Prob',
            'normalized_problem_key' => 'key1',
            'queue_name' => 'Q1',
            'owner_id' => 1,
            'owner_login' => '', // empty
        ]);
        Carbon::setTestNow();

        $this->rebuilder->rebuild();

        $stat = ZnunyOwnerSuggestionStat::first();
        $this->assertNotNull($stat);
        $this->assertEquals('valid_login', $stat->owner_login);
    }

    public function test_ownerless_observations_are_ignored()
    {
        // Ownerless (null owner_id and empty/null owner_login)
        ZnunyOwnerSuggestionObservation::create([
            'problem_name' => 'Prob1',
            'normalized_problem_key' => 'key_ownerless',
            'queue_name' => 'Q1',
            'owner_id' => null,
            'owner_login' => null,
        ]);

        // Valid observation
        ZnunyOwnerSuggestionObservation::create([
            'problem_name' => 'Prob2',
            'normalized_problem_key' => 'key_valid',
            'queue_name' => 'Q2',
            'owner_id' => 10,
            'owner_login' => 'user_10',
        ]);

        $summary = $this->rebuilder->rebuild();

        // Should only count the valid one
        $this->assertEquals(1, $summary['observations_scanned']);
        $this->assertEquals(1, $summary['stats_written']);

        $stats = ZnunyOwnerSuggestionStat::all();
        $this->assertCount(1, $stats);

        // Assert no stat row created for ownerless observation
        $this->assertDatabaseMissing('znuny_owner_suggestion_stats', [
            'normalized_problem_key' => 'key_ownerless',
        ]);
    }

    public function test_string_owner_id_is_supported_and_grouped_properly()
    {
        $now = Carbon::now();

        Carbon::setTestNow($now->copy()->subDays(2));
        ZnunyOwnerSuggestionObservation::create([
            'problem_name' => 'Prob',
            'normalized_problem_key' => 'key_string',
            'queue_name' => 'Q1',
            'owner_id' => 'agent_10',
            'owner_login' => 'old_login',
        ]);

        Carbon::setTestNow($now->copy()->subDays(1));
        ZnunyOwnerSuggestionObservation::create([
            'problem_name' => 'Prob',
            'normalized_problem_key' => 'key_string',
            'queue_name' => 'Q1',
            'owner_id' => 'agent_10',
            'owner_login' => 'new_login',
        ]);
        Carbon::setTestNow();

        $this->rebuilder->rebuild();

        $stat = ZnunyOwnerSuggestionStat::where('normalized_problem_key', 'key_string')->first();
        $this->assertNotNull($stat);

        // Assert stat owner_id is stored
        $this->assertEquals('agent_10', $stat->owner_id);

        // Assert stat owner_login = "new_login"
        $this->assertEquals('new_login', $stat->owner_login);

        // Ensure it aggregated them into 1 stat row with sample_count = 2
        $this->assertEquals(2, $stat->sample_count);
    }
}
