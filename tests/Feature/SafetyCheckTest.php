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

    public function test_safety_check_detects_dangerous_tinker_workflows_and_ignores_safe_documentation()
    {
        $scriptPath = base_path('scripts/check-dangerous-db-workflows.sh');
        $this->assertFileExists($scriptPath);

        $timestamp = date('Ymd_His');
        $tempSafeFile = base_path('gpt/' . $timestamp . '_safe_documentation.txt');
        $tempDangerousFile = base_path('gpt/' . $timestamp . '_dangerous_tinker.txt');

        try {
            // A. SAFE DOCUMENTATION MENTION MUST NOT FAIL
            file_put_contents($tempSafeFile, 'Here is some documentation about RefreshDatabase, DatabaseMigrations, and DatabaseTransactions.');

            exec("bash {$scriptPath}", $outputSafe, $returnCodeSafe);
            $this->assertEquals(0, $returnCodeSafe, 'Safety check script failed on safe documentation: '.implode("\n", $outputSafe));

            // B. ACTUAL DANGEROUS TINKER WORKFLOW MUST FAIL
            // Build the string dynamically to avoid poisoning the test file itself.
            $dangerousString = 'artisan ' . 'tinker ' . 'with ' . 'RefreshDatabase';
            file_put_contents($tempDangerousFile, $dangerousString);

            exec("bash {$scriptPath}", $outputDangerous, $returnCodeDangerous);
            $this->assertNotEquals(0, $returnCodeDangerous, 'Safety check script should have failed on dangerous tinker workflow.');
        } finally {
            if (file_exists($tempSafeFile)) {
                unlink($tempSafeFile);
            }
            if (file_exists($tempDangerousFile)) {
                unlink($tempDangerousFile);
            }
        }
    }
}
