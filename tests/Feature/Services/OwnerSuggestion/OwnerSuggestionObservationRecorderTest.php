<?php

namespace Tests\Feature\Services\OwnerSuggestion;

use App\Models\User;
use App\Services\OwnerSuggestion\OwnerSuggestionObservationRecorder;
use App\Services\OwnerSuggestion\ProblemNameNormalizer;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class OwnerSuggestionObservationRecorderTest extends TestCase
{
    use RefreshDatabase;

    private OwnerSuggestionObservationRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recorder = new OwnerSuggestionObservationRecorder(new ProblemNameNormalizer);
    }

    public function test_records_observation_with_normalized_problem_key()
    {
        $data = [
            'problem_name' => 'High CPU load on server1',
            'queue_name' => 'IT Support',
            'owner_id' => '12',
            'zabbix_event_id' => '12345',
            'zabbix_host_name' => 'server1',
            'customer_user_login' => 'johndoe',
            'znuny_ticket_id' => '9988',
        ];

        $observation = $this->recorder->recordManualTicketCreated($data);

        $this->assertNotNull($observation);
        $this->assertSame('High CPU load on server1', $observation->problem_name);
        $this->assertSame('high cpu load on server1', $observation->normalized_problem_key);
        $this->assertSame('IT Support', $observation->queue_name);
        $this->assertSame('12', $observation->owner_id);
        $this->assertNull($observation->owner_login);
        $this->assertSame('12345', $observation->zabbix_event_id);
        $this->assertSame('server1', $observation->zabbix_host_name);
        $this->assertSame('johndoe', $observation->customer_user_login);
        $this->assertSame('9988', $observation->znuny_ticket_id);

        $this->assertDatabaseHas('znuny_owner_suggestion_observations', [
            'id' => $observation->id,
            'normalized_problem_key' => 'high cpu load on server1',
        ]);
    }

    public function test_skips_empty_problem_name()
    {
        $data = [
            'problem_name' => '   ',
            'owner_id' => '12',
        ];

        $this->assertNull($this->recorder->recordManualTicketCreated($data));
        $this->assertDatabaseCount('znuny_owner_suggestion_observations', 0);
    }

    public function test_skips_empty_normalized_key()
    {
        $data = [
            'problem_name' => '---', // Normalizer will strip this out
            'owner_id' => '12',
        ];

        $this->assertNull($this->recorder->recordManualTicketCreated($data));
        $this->assertDatabaseCount('znuny_owner_suggestion_observations', 0);
    }

    public function test_skips_missing_owner()
    {
        $data = [
            'problem_name' => 'High CPU',
            'owner_id' => '',
            'owner_login' => null,
        ];

        $this->assertNull($this->recorder->recordManualTicketCreated($data));
        $this->assertDatabaseCount('znuny_owner_suggestion_observations', 0);
    }

    public function test_allows_owner_login_without_id()
    {
        $data = [
            'problem_name' => 'High CPU',
            'owner_login' => 'admin',
        ];

        $observation = $this->recorder->recordManualTicketCreated($data);

        $this->assertNotNull($observation);
        $this->assertSame('admin', $observation->owner_login);
        $this->assertNull($observation->owner_id);
    }

    public function test_stores_created_by_user_id()
    {
        $user = User::factory()->create();

        $data = [
            'problem_name' => 'High CPU',
            'owner_id' => '12',
            'created_by_user_id' => $user->id,
        ];

        $observation = $this->recorder->recordManualTicketCreated($data);

        $this->assertSame($user->id, $observation->created_by_user_id);
    }

    public function test_catches_exceptions_and_logs_warning()
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Failed to record owner suggestion observation'
                    && isset($context['error'])
                    && $context['error'] === 'DB Error';
            });

        // Mock normalizer to throw exception to test non-critical failure handling
        $failingNormalizer = Mockery::mock(ProblemNameNormalizer::class);
        $failingNormalizer->shouldReceive('normalize')->andThrow(new Exception('DB Error'));

        $recorder = new OwnerSuggestionObservationRecorder($failingNormalizer);

        $data = [
            'problem_name' => 'High CPU',
            'owner_id' => '12',
        ];

        $this->assertNull($recorder->recordManualTicketCreated($data));
    }
}
