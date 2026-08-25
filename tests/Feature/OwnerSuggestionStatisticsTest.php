<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\User;
use App\Models\ZnunyOwnerSuggestionObservation;
use App\Models\ZnunyOwnerSuggestionStat;
use App\Support\Settings\DefaultSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OwnerSuggestionStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_settings_include_statistics_keys()
    {
        $defaults = DefaultSettings::all();
        $keys = collect($defaults)->pluck('key')->toArray();

        $this->assertContains('owner_suggestion_similarity_threshold', $keys);
        $this->assertContains('owner_suggestion_statistics_retention_days', $keys);
        $this->assertContains('owner_suggestion_old_weight_coefficient', $keys);
        $this->assertContains('owner_suggestion_observation_cleanup_days', $keys);
        $this->assertContains('owner_suggestion_rebuild_interval_minutes', $keys);
    }

    public function test_settings_page_validates_statistics_settings()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            // Test similarity threshold bounds
            ->set('data.owner_suggestion_similarity_threshold', 0)
            ->call('save')
            ->assertHasErrors(['data.owner_suggestion_similarity_threshold' => 'min'])

            ->set('data.owner_suggestion_similarity_threshold', 101)
            ->call('save')
            ->assertHasErrors(['data.owner_suggestion_similarity_threshold' => 'max'])

            ->set('data.owner_suggestion_similarity_threshold', 80)
            ->call('save')
            ->assertHasNoErrors(['data.owner_suggestion_similarity_threshold'])

            // Test rebuild interval bounds
            ->set('data.owner_suggestion_rebuild_interval_minutes', 9)
            ->call('save')
            ->assertHasErrors(['data.owner_suggestion_rebuild_interval_minutes' => 'min'])

            ->set('data.owner_suggestion_rebuild_interval_minutes', 1441)
            ->call('save')
            ->assertHasErrors(['data.owner_suggestion_rebuild_interval_minutes' => 'max'])

            ->set('data.owner_suggestion_rebuild_interval_minutes', 180)
            ->call('save')
            ->assertHasNoErrors(['data.owner_suggestion_rebuild_interval_minutes'])

            // Test old weight coefficient allows > 1 and decimal
            ->set('data.owner_suggestion_old_weight_coefficient', 1.5)
            ->call('save')
            ->assertHasNoErrors(['data.owner_suggestion_old_weight_coefficient'])

            ->set('data.owner_suggestion_old_weight_coefficient', -0.5)
            ->call('save')
            ->assertHasErrors(['data.owner_suggestion_old_weight_coefficient' => 'min']);
    }

    public function test_can_create_observation_model()
    {
        $user = User::factory()->create();

        $observation = ZnunyOwnerSuggestionObservation::create([
            'problem_name' => 'High CPU load',
            'normalized_problem_key' => 'high_cpu_load',
            'queue_name' => 'LinuxServers',
            'owner_id' => '12',
            'owner_login' => 'johndoe',
            'zabbix_event_id' => '999111',
            'created_by_user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('znuny_owner_suggestion_observations', [
            'id' => $observation->id,
            'problem_name' => 'High CPU load',
            'owner_login' => 'johndoe',
        ]);

        $this->assertEquals($user->id, $observation->createdBy->id);
    }

    public function test_can_create_stat_model()
    {
        $stat = ZnunyOwnerSuggestionStat::create([
            'normalized_problem_key' => 'high_cpu_load',
            'queue_name' => 'LinuxServers',
            'owner_id' => '12',
            'owner_login' => 'johndoe',
            'score' => 75.5,
            'sample_count' => 10,
            'recent_count' => 8,
            'old_count' => 2,
        ]);

        $this->assertDatabaseHas('znuny_owner_suggestion_stats', [
            'id' => $stat->id,
            'normalized_problem_key' => 'high_cpu_load',
            'score' => 75.5,
        ]);

        $this->assertEquals(75.5, $stat->score);
    }
}
