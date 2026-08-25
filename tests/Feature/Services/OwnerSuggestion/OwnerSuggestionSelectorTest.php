<?php

namespace Tests\Feature\Services\OwnerSuggestion;

use App\Models\ZnunyOwnerSuggestionStat;
use App\Services\OwnerSuggestion\OwnerSuggestionSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerSuggestionSelectorTest extends TestCase
{
    use RefreshDatabase;

    private OwnerSuggestionSelector $selector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->selector = app(OwnerSuggestionSelector::class);
    }

    public function test_returns_null_for_empty_problem_name()
    {
        $this->assertNull($this->selector->suggest('', 'Queue A'));
        $this->assertNull($this->selector->suggest('   ', 'Queue A'));
    }

    public function test_returns_best_exact_match()
    {
        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'exact problem',
            'queue_name' => 'Q1',
            'owner_id' => 10,
            'owner_login' => 'user_10',
            'sample_count' => 5,
            'recent_count' => 5,
            'old_count' => 0,
            'score' => 5.0,
        ]);

        $result = $this->selector->suggest('exact problem', 'Q1');

        $this->assertNotNull($result);
        $this->assertEquals(10, $result['owner_id']);
        $this->assertEquals('exact problem', $result['matched_normalized_problem_key']);
        $this->assertEquals(1.0, $result['similarity']);
    }

    public function test_rank_returns_multiple_candidates_in_correct_order()
    {
        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'exact problem',
            'queue_name' => 'Q1',
            'owner_id' => 1,
            'owner_login' => 'user_1',
            'sample_count' => 5,
            'recent_count' => 5,
            'old_count' => 0,
            'score' => 5.0,
        ]);

        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'exact problem',
            'queue_name' => 'Q1',
            'owner_id' => 2,
            'owner_login' => 'user_2',
            'sample_count' => 10,
            'recent_count' => 10,
            'old_count' => 0,
            'score' => 10.0,
        ]);

        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'exact problem',
            'queue_name' => 'Q2',
            'owner_id' => 3,
            'owner_login' => 'user_3',
            'sample_count' => 20,
            'recent_count' => 20,
            'old_count' => 0,
            'score' => 20.0,
        ]);

        $results = $this->selector->rank('exact problem', 'Q1');

        $this->assertCount(3, $results);
        $this->assertEquals(2, $results[0]['owner_id']); // Q1, score 10
        $this->assertEquals(1, $results[1]['owner_id']); // Q1, score 5
        $this->assertEquals(3, $results[2]['owner_id']); // Q2, score 20 (Q2 sorted after Q1)
    }

    public function test_uses_similarity_for_structural_disk_space_problems()
    {
        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'free disk space is less than <percent> on volume <path>',
            'queue_name' => 'Q1',
            'owner_id' => 99,
            'owner_login' => 'disk_guru',
            'sample_count' => 10,
            'recent_count' => 10,
            'old_count' => 0,
            'score' => 10.0,
        ]);

        $result = $this->selector->suggest('Free disk space is less than 10% on volume /home', 'Q1');

        $this->assertNotNull($result);
        $this->assertEquals(99, $result['owner_id']);
        $this->assertTrue($result['similarity'] > 0.8);
    }

    public function test_keeps_cpu_and_memory_separate()
    {
        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'memory utilization high',
            'queue_name' => 'Q1',
            'owner_id' => 55,
            'owner_login' => 'mem_master',
            'sample_count' => 10,
            'recent_count' => 10,
            'old_count' => 0,
            'score' => 10.0,
        ]);

        $result = $this->selector->suggest('CPU utilization high', 'Q1');

        $this->assertNull($result);
    }

    public function test_prefers_same_queue_over_different_queue()
    {
        // Different queue, but higher score
        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'some error',
            'queue_name' => 'Q2',
            'owner_id' => 1,
            'owner_login' => 'u1',
            'sample_count' => 100,
            'recent_count' => 100,
            'old_count' => 0,
            'score' => 100.0,
        ]);

        // Same queue, but lower score
        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'some error',
            'queue_name' => 'Q1',
            'owner_id' => 2,
            'owner_login' => 'u2',
            'sample_count' => 5,
            'recent_count' => 5,
            'old_count' => 0,
            'score' => 5.0,
        ]);

        $result = $this->selector->suggest('Some error', 'Q1');

        $this->assertNotNull($result);
        $this->assertEquals(2, $result['owner_id']);
        $this->assertEquals('Q1', $result['queue_name']);
    }

    public function test_same_queue_candidates_rank_before_different_queue_candidates()
    {
        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'same error',
            'queue_name' => 'Queue A',
            'owner_id' => 1,
            'owner_login' => 'u1',
            'score' => 5.0,
        ]);

        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'same error',
            'queue_name' => 'Queue B',
            'owner_id' => 2,
            'owner_login' => 'u2',
            'score' => 100.0,
        ]);

        $results = $this->selector->rank('Same error', 'Queue A');

        $this->assertCount(2, $results);
        $this->assertEquals(1, $results[0]['owner_id']);
        $this->assertEquals('Queue A', $results[0]['queue_name']);
        $this->assertEquals(2, $results[1]['owner_id']);
        $this->assertEquals('Queue B', $results[1]['queue_name']);
    }

    public function test_rank_falls_back_to_different_queue_candidates_when_current_queue_has_no_matching_stats()
    {
        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'fallback error',
            'queue_name' => 'Queue A',
            'owner_id' => 99,
            'owner_login' => 'fallback_user',
            'score' => 50.0,
        ]);

        $results = $this->selector->rank('fallback error', 'Queue B');

        $this->assertCount(1, $results);
        $this->assertEquals(99, $results[0]['owner_id']);
        $this->assertEquals('Queue A', $results[0]['queue_name']);
    }

    public function test_suggest_returns_first_ranked_fallback_candidate_when_current_queue_has_no_stats()
    {
        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'fallback error',
            'queue_name' => 'Queue A',
            'owner_id' => 88,
            'owner_login' => 'fallback_user_2',
            'score' => 50.0,
        ]);

        $result = $this->selector->suggest('fallback error', 'Queue B');

        $this->assertNotNull($result);
        $this->assertEquals(88, $result['owner_id']);
        $this->assertEquals('Queue A', $result['queue_name']);
    }

    public function test_uses_score_when_queue_and_similarity_are_equal()
    {
        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'same error',
            'queue_name' => 'Q1',
            'owner_id' => 1,
            'owner_login' => 'u1',
            'sample_count' => 5,
            'recent_count' => 5,
            'old_count' => 0,
            'score' => 5.0,
        ]);

        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'same error',
            'queue_name' => 'Q1',
            'owner_id' => 2,
            'owner_login' => 'u2',
            'sample_count' => 15,
            'recent_count' => 15,
            'old_count' => 0,
            'score' => 15.0,
        ]);

        $result = $this->selector->suggest('Same error', 'Q1');

        $this->assertNotNull($result);
        $this->assertEquals(2, $result['owner_id']);
    }

    public function test_allowed_owner_ids_filters_candidates()
    {
        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'same error',
            'queue_name' => 'Q1',
            'owner_id' => 1, // Higher score, but not allowed
            'owner_login' => 'u1',
            'score' => 50.0,
        ]);

        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'same error',
            'queue_name' => 'Q1',
            'owner_id' => 2, // Lower score, but allowed
            'owner_login' => 'u2',
            'score' => 10.0,
        ]);

        $result = $this->selector->suggest('Same error', 'Q1', [2, 3]);

        $this->assertNotNull($result);
        $this->assertEquals(2, $result['owner_id']);
    }

    public function test_allowed_owner_logins_filters_candidates()
    {
        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'same error',
            'queue_name' => 'Q1',
            'owner_id' => 1,
            'owner_login' => 'u1',
            'score' => 50.0,
        ]);

        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'same error',
            'queue_name' => 'Q1',
            'owner_id' => 2,
            'owner_login' => 'u2',
            'score' => 10.0,
        ]);

        $result = $this->selector->suggest('Same error', 'Q1', [], ['u2']);

        $this->assertNotNull($result);
        $this->assertEquals('u2', $result['owner_login']);
    }

    public function test_ownerless_stats_are_ignored()
    {
        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'same error',
            'queue_name' => 'Q1',
            'owner_id' => null,
            'owner_login' => null,
            'score' => 100.0, // High score, but ownerless
        ]);

        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'same error',
            'queue_name' => 'Q1',
            'owner_id' => 10,
            'owner_login' => 'valid_user',
            'score' => 5.0, // Low score, valid owner
        ]);

        $result = $this->selector->suggest('Same error', 'Q1');

        $this->assertNotNull($result);
        $this->assertEquals(10, $result['owner_id']);
    }

    public function test_returns_null_when_no_candidate_passes_similarity_threshold()
    {
        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'completely unrelated string of text',
            'queue_name' => 'Q1',
            'owner_id' => 1,
            'owner_login' => 'u1',
            'score' => 10.0,
        ]);

        $result = $this->selector->suggest('Network switch is down', 'Q1');

        $this->assertNull($result);
    }

    public function test_allowed_owner_ids_and_logins_are_or_conditions()
    {
        // Matches by owner_id only
        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'same error',
            'queue_name' => 'Q1',
            'owner_id' => 99,
            'owner_login' => 'unmatched_login',
            'score' => 50.0,
        ]);

        // Matches by owner_login only
        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'same error',
            'queue_name' => 'Q1',
            'owner_id' => 100, // not allowed
            'owner_login' => 'allowed_login',
            'score' => 40.0,
        ]);

        // Matches neither
        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'same error',
            'queue_name' => 'Q1',
            'owner_id' => 101,
            'owner_login' => 'unmatched_too',
            'score' => 100.0, // Highest score, but should be filtered out
        ]);

        // Provide allowedOwnerIds = [99] and allowedOwnerLogins = ['allowed_login']
        $result = $this->selector->suggest('Same error', 'Q1', [99], ['allowed_login']);

        $this->assertNotNull($result);

        // The one with owner_id = 99 has score 50, the one with owner_login = allowed_login has score 40.
        // So owner_id 99 should win.
        $this->assertEquals(99, $result['owner_id']);

        // If we only allow 'allowed_login' and some other ID
        $result2 = $this->selector->suggest('Same error', 'Q1', [999], ['allowed_login']);
        $this->assertNotNull($result2);
        $this->assertEquals('allowed_login', $result2['owner_login']);
    }

    public function test_rank_respects_allowed_owners_when_top_1_is_not_allowed()
    {
        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'filter error',
            'queue_name' => 'Q1',
            'owner_id' => 1,
            'owner_login' => 'blocked@example.invalid',
            'score' => 100.0,
        ]);

        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'filter error',
            'queue_name' => 'Q1',
            'owner_id' => 2,
            'owner_login' => 'allowed@example.invalid',
            'score' => 50.0,
        ]);

        $results = $this->selector->rank('filter error', 'Q1', [], ['allowed@example.invalid']);

        $this->assertCount(1, $results);
        $this->assertEquals('allowed@example.invalid', $results[0]['owner_login']);
    }

    public function test_suggest_returns_next_allowed_owner_when_top_1_is_filtered_out()
    {
        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'filter error',
            'queue_name' => 'Q1',
            'owner_id' => 1,
            'owner_login' => 'blocked@example.invalid',
            'score' => 100.0,
        ]);

        ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'filter error',
            'queue_name' => 'Q1',
            'owner_id' => 2,
            'owner_login' => 'allowed@example.invalid',
            'score' => 50.0,
        ]);

        $result = $this->selector->suggest('filter error', 'Q1', [], ['allowed@example.invalid']);

        $this->assertNotNull($result);
        $this->assertEquals('allowed@example.invalid', $result['owner_login']);
    }
}
