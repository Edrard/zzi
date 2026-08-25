<?php

namespace Tests\Feature\Scheduler;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillScheduledZnunyTaskOwnerIdMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_backfills_numeric_owner_login_to_owner_id()
    {
        // Insert dummy data directly via DB to bypass Eloquent casts/mutators that might set nulls or other defaults
        DB::table('scheduled_znuny_tasks')->insert([
            ['id' => 1, 'name' => 'Valid task', 'owner_login' => '5', 'owner_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Valid task string', 'owner_login' => 'non.numeric', 'owner_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Already has id', 'owner_login' => '10', 'owner_id' => 8, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'Zero', 'owner_login' => '0', 'owner_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Require the migration file which returns an anonymous class instance
        $migration = require database_path('migrations/2026_07_12_092510_backfill_scheduled_znuny_task_owner_id.php');

        // Run the up() method
        $migration->up();

        // Assertions
        $task1 = DB::table('scheduled_znuny_tasks')->where('id', 1)->first();
        $this->assertEquals(5, $task1->owner_id);
        $this->assertEquals('5', $task1->owner_login); // Does not delete it

        $task2 = DB::table('scheduled_znuny_tasks')->where('id', 2)->first();
        $this->assertNull($task2->owner_id);
        $this->assertEquals('non.numeric', $task2->owner_login);

        $task3 = DB::table('scheduled_znuny_tasks')->where('id', 3)->first();
        $this->assertEquals(8, $task3->owner_id); // Does not overwrite existing
        $this->assertEquals('10', $task3->owner_login);

        $task4 = DB::table('scheduled_znuny_tasks')->where('id', 4)->first();
        $this->assertNull($task4->owner_id);
        $this->assertEquals('0', $task4->owner_login);
    }
}
