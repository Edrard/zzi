<?php

namespace Tests\Feature\Services\OwnerSuggestion;

use App\Models\Setting;
use App\Services\OwnerSuggestion\ProblemNameNormalizer;
use App\Services\OwnerSuggestion\ProblemSimilarityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProblemSimilarityServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProblemSimilarityService $similarityService;

    private ProblemNameNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->similarityService = new ProblemSimilarityService;
        $this->normalizer = new ProblemNameNormalizer;
    }

    public function test_exact_same_normalized_string_returns_one()
    {
        $this->assertSame(1.0, $this->similarityService->similarity('a b c', 'a b c'));
        $this->assertSame(1.0, $this->similarityService->similarity('', ''));
    }

    public function test_empty_vs_non_empty_returns_zero()
    {
        $this->assertSame(0.0, $this->similarityService->similarity('', 'a b c'));
        $this->assertSame(0.0, $this->similarityService->similarity('a b c', ''));
    }

    public function test_disk_space_examples_are_similar_at_default_80_threshold()
    {
        $str1 = $this->normalizer->normalize('Free disk space is less than 20% on volume /var');
        $str2 = $this->normalizer->normalize('Free disk space is less than 10% on volume /home');

        $this->assertSame($str1, $str2); // Normalizer makes them exactly the same
        $this->assertTrue($this->similarityService->isSimilar($str1, $str2, 80));
    }

    public function test_cpu_vs_memory_is_below_80_threshold()
    {
        $cpu = $this->normalizer->normalize('CPU utilization high');
        $mem = $this->normalizer->normalize('Memory utilization high');

        $this->assertFalse($this->similarityService->isSimilar($cpu, $mem, 80));
    }

    public function test_icmp_ping_variations_are_similar()
    {
        $str1 = $this->normalizer->normalize('ICMP ping unavailable');
        $str2 = $this->normalizer->normalize('ICMP ping is unavailable');

        $this->assertTrue($this->similarityService->isSimilar($str1, $str2, 80));
    }

    public function test_icmp_vs_zabbix_agent_unavailable_is_not_similar()
    {
        $str1 = $this->normalizer->normalize('Host is unavailable by ICMP ping');
        $str2 = $this->normalizer->normalize('Host is unavailable by Zabbix agent');

        $this->assertFalse($this->similarityService->isSimilar($str1, $str2, 80));
    }

    public function test_different_problem_categories_are_not_similar()
    {
        $str1 = $this->normalizer->normalize('CPU utilization high');
        $str2 = $this->normalizer->normalize('Free disk space is less than 20%');

        $this->assertFalse($this->similarityService->isSimilar($str1, $str2, 80));
    }

    public function test_is_similar_respects_explicit_threshold_parameter()
    {
        $a = 'word1 word2 word3';
        $b = 'word1 word2 word4';

        // Check the exact float so we know what threshold to use for test
        $sim = $this->similarityService->similarity($a, $b);
        $percent = (int) ($sim * 100);

        $this->assertTrue($this->similarityService->isSimilar($a, $b, $percent - 1));
        $this->assertFalse($this->similarityService->isSimilar($a, $b, $percent + 1));
    }

    public function test_is_similar_uses_settings_service_threshold_when_null()
    {
        // By default, owner_suggestion_similarity_threshold is 80
        $a = 'word1 word2 word3 word4 word5 word6 word7 word8 word9';
        $b = 'word1 word2 word3 word4 word5 word6 word7 word8 word10';

        $this->assertTrue($this->similarityService->isSimilar($a, $b, null));

        // Let's set it to 100 (which means exact match required)
        Setting::updateOrCreate(
            ['key' => 'owner_suggestion_similarity_threshold'],
            ['value' => '100', 'type' => 'integer']
        );

        // SettingsService will read from DB now
        $this->assertFalse($this->similarityService->isSimilar($a, $b, null));
    }
}
