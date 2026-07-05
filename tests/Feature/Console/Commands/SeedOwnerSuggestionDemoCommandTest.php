<?php

namespace Tests\Feature\Console\Commands;

use App\Models\ZnunyOwnerSuggestionObservation;
use App\Models\ZnunyOwnerSuggestionStat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedOwnerSuggestionDemoCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_problem_and_does_not_delete_old_rows()
    {
        ZnunyOwnerSuggestionObservation::create([
            'zabbix_event_id' => 'owner-suggestion-demo-abc-123',
            'problem_name' => 'Demo problem',
            'normalized_problem_key' => 'demo_problem',
            'queue_name' => 'Social product',
            'owner_id' => null,
            'owner_login' => 'first@example.invalid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('owner-suggestion:seed-demo', [
            '--owner-logins' => 'first@example.invalid',
        ])
            ->expectsOutput('The --problem option is required.')
            ->assertExitCode(1);

        $this->assertDatabaseCount(ZnunyOwnerSuggestionObservation::class, 1);
    }

    public function test_requires_owner_logins_and_does_not_delete_old_rows()
    {
        ZnunyOwnerSuggestionObservation::create([
            'zabbix_event_id' => 'owner-suggestion-demo-abc-123',
            'problem_name' => 'Demo problem',
            'normalized_problem_key' => 'demo_problem',
            'queue_name' => 'Social product',
            'owner_id' => null,
            'owner_login' => 'first@example.invalid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('owner-suggestion:seed-demo', [
            '--problem' => 'Some problem',
        ])
            ->expectsOutput('The --owner-logins option is required.')
            ->assertExitCode(1);

        $this->assertDatabaseCount(ZnunyOwnerSuggestionObservation::class, 1);
    }

    public function test_seeds_demo_data_and_rebuilds_stats()
    {
        $this->artisan('owner-suggestion:seed-demo', [
            '--problem' => 'Demo router unavailable by ICMP ping',
            '--owner-logins' => 'first@example.invalid,second@example.invalid,third@example.invalid',
        ])
            ->expectsOutput('Demo data seeded successfully.')
            ->expectsOutput('Problem name: Demo router unavailable by ICMP ping')
            ->expectsOutput('Queue: Social product')
            ->expectsOutputToContain('Demo prefix: owner-suggestion-demo-')
            ->expectsOutput('Owner logins in rank order: first@example.invalid, second@example.invalid, third@example.invalid')
            ->expectsOutput('Observations inserted: 60') // 30 + 20 + 10 = 60
            ->expectsOutput('Stats written: Rebuild complete.')
            ->assertExitCode(0);

        $this->assertDatabaseCount(ZnunyOwnerSuggestionObservation::class, 60);

        $this->assertTrue(ZnunyOwnerSuggestionStat::count() > 0);
        $this->assertDatabaseHas(ZnunyOwnerSuggestionStat::class, [
            'owner_login' => 'first@example.invalid',
            'sample_count' => 30,
        ]);
        $this->assertDatabaseHas(ZnunyOwnerSuggestionStat::class, [
            'owner_login' => 'second@example.invalid',
            'sample_count' => 20,
        ]);
        $this->assertDatabaseHas(ZnunyOwnerSuggestionStat::class, [
            'owner_login' => 'third@example.invalid',
            'sample_count' => 10,
        ]);
    }

    public function test_uses_stable_cleanup_prefix_per_problem_and_queue()
    {
        $this->artisan('owner-suggestion:seed-demo', [
            '--problem' => 'Problem A',
            '--queue' => 'Queue A',
            '--owner-logins' => 'first@example.invalid',
        ])->assertExitCode(0);

        $this->artisan('owner-suggestion:seed-demo', [
            '--problem' => 'Problem B',
            '--queue' => 'Queue A',
            '--owner-logins' => 'second@example.invalid',
        ])->assertExitCode(0);

        $this->assertDatabaseCount(ZnunyOwnerSuggestionObservation::class, 60); // 30 + 30

        $this->artisan('owner-suggestion:seed-demo', [
            '--problem' => 'Problem A',
            '--queue' => 'Queue A',
            '--owner-logins' => 'first@example.invalid',
        ])
            ->expectsOutputToContain('Cleanup: deleted 30 old demo observations.')
            ->assertExitCode(0);

        $this->assertDatabaseCount(ZnunyOwnerSuggestionObservation::class, 60);
        $this->assertDatabaseHas(ZnunyOwnerSuggestionObservation::class, [
            'problem_name' => 'Problem B',
        ]);
        $this->assertDatabaseHas(ZnunyOwnerSuggestionObservation::class, [
            'problem_name' => 'Problem A',
        ]);
    }

    public function test_trims_and_deduplicates_owner_logins()
    {
        $this->artisan('owner-suggestion:seed-demo', [
            '--problem' => 'Some problem',
            '--owner-logins' => '  first@example.invalid , second@example.invalid  ,first@example.invalid,   , ',
        ])
            ->expectsOutputToContain('Owner logins in rank order: first@example.invalid, second@example.invalid')
            ->expectsOutput('Observations inserted: 50') // 30 + 20 = 50
            ->assertExitCode(0);

        $this->assertDatabaseCount(ZnunyOwnerSuggestionObservation::class, 50);
        $this->assertDatabaseHas(ZnunyOwnerSuggestionStat::class, [
            'owner_login' => 'first@example.invalid',
            'sample_count' => 30,
        ]);
        $this->assertDatabaseHas(ZnunyOwnerSuggestionStat::class, [
            'owner_login' => 'second@example.invalid',
            'sample_count' => 20,
        ]);
    }

    public function test_does_not_delete_non_demo_observations()
    {
        ZnunyOwnerSuggestionObservation::create([
            'zabbix_event_id' => 'real-event-123',
            'problem_name' => 'Real problem',
            'normalized_problem_key' => 'real_problem',
            'queue_name' => 'Social product',
            'owner_id' => '1',
            'owner_login' => 'real.user',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('owner-suggestion:seed-demo', [
            '--problem' => 'Real problem',
            '--queue' => 'Social product',
            '--owner-logins' => 'first@example.invalid',
        ])->assertExitCode(0);

        $this->assertDatabaseCount(ZnunyOwnerSuggestionObservation::class, 31);
        $this->assertDatabaseHas(ZnunyOwnerSuggestionObservation::class, [
            'zabbix_event_id' => 'real-event-123',
        ]);
    }

    public function test_supports_custom_queue_and_problem_options()
    {
        $this->artisan('owner-suggestion:seed-demo', [
            '--queue' => 'Custom Queue',
            '--problem' => 'Custom Problem',
            '--owner-logins' => 'first@example.invalid',
        ])->assertExitCode(0);

        $this->assertDatabaseHas(ZnunyOwnerSuggestionObservation::class, [
            'queue_name' => 'Custom Queue',
            'problem_name' => 'Custom Problem',
        ]);
    }

    public function test_clear_mode_requires_problem()
    {
        $this->artisan('owner-suggestion:seed-demo', [
            '--clear' => true,
        ])
            ->expectsOutput('The --problem option is required.')
            ->assertExitCode(1);
    }

    public function test_clear_mode_does_not_require_owner_logins_and_clears_matching_rows()
    {
        $this->artisan('owner-suggestion:seed-demo', [
            '--problem' => 'Problem A',
            '--queue' => 'Queue A',
            '--owner-logins' => 'test@example.invalid',
        ])->assertExitCode(0);

        $this->assertDatabaseCount(ZnunyOwnerSuggestionObservation::class, 30);

        $this->artisan('owner-suggestion:seed-demo', [
            '--clear' => true,
            '--problem' => 'Problem A',
            '--queue' => 'Queue A',
        ])
            ->expectsOutputToContain('Cleanup: deleted 30 demo observations.')
            ->assertExitCode(0);

        $this->assertDatabaseCount(ZnunyOwnerSuggestionObservation::class, 0);
    }

    public function test_clear_mode_deletes_only_matching_queue_problem_demo_rows()
    {
        $this->artisan('owner-suggestion:seed-demo', [
            '--problem' => 'Problem A',
            '--queue' => 'Queue A',
            '--owner-logins' => 'test@example.invalid',
        ])->assertExitCode(0);

        $this->artisan('owner-suggestion:seed-demo', [
            '--problem' => 'Problem B',
            '--queue' => 'Queue A',
            '--owner-logins' => 'test2@example.invalid',
        ])->assertExitCode(0);

        $this->assertDatabaseCount(ZnunyOwnerSuggestionObservation::class, 60);

        $this->artisan('owner-suggestion:seed-demo', [
            '--clear' => true,
            '--problem' => 'Problem A',
            '--queue' => 'Queue A',
        ])->assertExitCode(0);

        $this->assertDatabaseCount(ZnunyOwnerSuggestionObservation::class, 30);
        $this->assertDatabaseHas(ZnunyOwnerSuggestionObservation::class, [
            'problem_name' => 'Problem B',
        ]);
        $this->assertDatabaseMissing(ZnunyOwnerSuggestionObservation::class, [
            'problem_name' => 'Problem A',
        ]);
    }

    public function test_clear_mode_does_not_delete_non_demo_observations()
    {
        ZnunyOwnerSuggestionObservation::create([
            'zabbix_event_id' => 'real-event-123',
            'problem_name' => 'Real problem',
            'normalized_problem_key' => 'real_problem',
            'queue_name' => 'Social product',
            'owner_id' => '1',
            'owner_login' => 'real.user',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('owner-suggestion:seed-demo', [
            '--clear' => true,
            '--problem' => 'Real problem',
            '--queue' => 'Social product',
        ])->assertExitCode(0);

        $this->assertDatabaseCount(ZnunyOwnerSuggestionObservation::class, 1);
        $this->assertDatabaseHas(ZnunyOwnerSuggestionObservation::class, [
            'zabbix_event_id' => 'real-event-123',
        ]);
    }

    public function test_clear_mode_rebuilds_stats()
    {
        $this->artisan('owner-suggestion:seed-demo', [
            '--problem' => 'Problem A',
            '--queue' => 'Queue A',
            '--owner-logins' => 'test@example.invalid',
        ])->assertExitCode(0);

        $this->assertTrue(ZnunyOwnerSuggestionStat::count() > 0);

        $this->artisan('owner-suggestion:seed-demo', [
            '--clear' => true,
            '--problem' => 'Problem A',
            '--queue' => 'Queue A',
        ])
            ->expectsOutputToContain('Stats written: Rebuild complete.')
            ->assertExitCode(0);

        // After clear, stats should be rebuilt and reflect 0 rows for that problem.
        // Actually, rebuild removes stats that have no recent/old counts.
        $this->assertDatabaseMissing(ZnunyOwnerSuggestionStat::class, [
            'owner_login' => 'test@example.invalid',
        ]);
    }
}
