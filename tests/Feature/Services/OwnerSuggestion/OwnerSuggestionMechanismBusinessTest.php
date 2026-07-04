<?php

namespace Tests\Feature\Services\OwnerSuggestion;

use App\Services\OwnerSuggestion\OwnerSuggestionObservationRecorder;
use App\Services\OwnerSuggestion\ProblemNameNormalizer;
use App\Services\OwnerSuggestion\ProblemSimilarityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerSuggestionMechanismBusinessTest extends TestCase
{
    use RefreshDatabase;

    private ProblemNameNormalizer $normalizer;

    private ProblemSimilarityService $similarityService;

    private OwnerSuggestionObservationRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new ProblemNameNormalizer;
        $this->similarityService = new ProblemSimilarityService;
        $this->recorder = new OwnerSuggestionObservationRecorder($this->normalizer);
    }

    public function test_disk_space_problems_are_grouped_structurally()
    {
        $inputA = 'Free disk space is less than 20% on volume /var';
        $inputB = 'Free disk space is less than 10% on volume /home';

        $normalizedA = $this->normalizer->normalize($inputA);
        $normalizedB = $this->normalizer->normalize($inputB);

        // They must either be exactly equal or >= 80% similar
        $areEqual = $normalizedA === $normalizedB;
        $areSimilar = $this->similarityService->isSimilar($normalizedA, $normalizedB, 80);

        $this->assertTrue($areEqual || $areSimilar);

        // Record observations
        $obsA = $this->recorder->recordManualTicketCreated([
            'problem_name' => $inputA,
            'owner_id' => '10',
        ]);

        $obsB = $this->recorder->recordManualTicketCreated([
            'problem_name' => $inputB,
            'owner_id' => '11',
        ]);

        // Assert they were created
        $this->assertNotNull($obsA);
        $this->assertNotNull($obsB);

        $this->assertDatabaseHas('znuny_owner_suggestion_observations', [
            'id' => $obsA->id,
        ]);

        $this->assertDatabaseHas('znuny_owner_suggestion_observations', [
            'id' => $obsB->id,
        ]);

        // Assert their normalized keys behave as expected structurally
        $keysEqual = $obsA->normalized_problem_key === $obsB->normalized_problem_key;
        $keysSimilar = $this->similarityService->isSimilar($obsA->normalized_problem_key, $obsB->normalized_problem_key, 80);

        $this->assertTrue($keysEqual || $keysSimilar);
    }

    public function test_cpu_and_memory_problems_stay_separate()
    {
        $inputA = 'CPU utilization high';
        $inputB = 'Memory utilization high';

        $normalizedA = $this->normalizer->normalize($inputA);
        $normalizedB = $this->normalizer->normalize($inputB);

        $this->assertNotSame($normalizedA, $normalizedB);
        $this->assertFalse($this->similarityService->isSimilar($normalizedA, $normalizedB, 80));
    }

    public function test_icmp_unavailable_and_zabbix_agent_unavailable_stay_separate()
    {
        $inputA = 'Host is unavailable by ICMP ping';
        $inputB = 'Host is unavailable by Zabbix agent';

        $normalizedA = $this->normalizer->normalize($inputA);
        $normalizedB = $this->normalizer->normalize($inputB);

        $this->assertFalse($this->similarityService->isSimilar($normalizedA, $normalizedB, 80));
    }

    public function test_equivalent_icmp_wording_is_similar()
    {
        $inputA = 'ICMP ping unavailable';
        $inputB = 'ICMP ping is unavailable';

        $normalizedA = $this->normalizer->normalize($inputA);
        $normalizedB = $this->normalizer->normalize($inputB);

        $this->assertTrue($this->similarityService->isSimilar($normalizedA, $normalizedB, 80));
    }

    public function test_observation_recorder_stores_business_fields()
    {
        $data = [
            'problem_name' => 'Service HTTP is down',
            'queue_name' => 'Network',
            'owner_id' => '99',
            'zabbix_event_id' => 'EVT-001',
            'zabbix_host_name' => 'web-server-01',
            'customer_user_login' => 'sysadmin',
            'znuny_ticket_id' => 'TKT-999',
        ];

        $observation = $this->recorder->recordManualTicketCreated($data);

        $this->assertNotNull($observation);

        $this->assertSame('Service HTTP is down', $observation->problem_name);
        $this->assertNotEmpty($observation->normalized_problem_key);
        $this->assertSame('Network', $observation->queue_name);
        $this->assertSame('99', $observation->owner_id);
        $this->assertSame('EVT-001', $observation->zabbix_event_id);
        $this->assertSame('web-server-01', $observation->zabbix_host_name);
        $this->assertSame('sysadmin', $observation->customer_user_login);
        $this->assertSame('TKT-999', $observation->znuny_ticket_id);
    }
}
