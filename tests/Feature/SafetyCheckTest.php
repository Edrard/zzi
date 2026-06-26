<?php

namespace Tests\Feature;

use Tests\TestCase;

class SafetyCheckTest extends TestCase
{
    public function test_safety_check_script_passes()
    {
        $scriptPath = base_path('scripts/check-dangerous-db-workflows.sh');

        $this->assertFileExists($scriptPath);

        exec("bash {$scriptPath}", $output, $returnCode);

        $this->assertEquals(0, $returnCode, 'Safety check script failed with output: '.implode("\n", $output));
    }
}
