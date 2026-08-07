<?php

namespace Tests\Feature\Filament;

use Tests\TestCase;

class ZnunyPrewarmCacheMissUxBoundaryTest extends TestCase
{
    private function assertFileContains(string $path, string $fragment, string $message = '')
    {
        $content = file_get_contents(base_path($path));
        $this->assertStringContainsString($fragment, $content, $message);
    }

    private function assertFileNotContains(string $path, string $fragment, string $message = '')
    {
        $content = file_get_contents(base_path($path));
        $this->assertStringNotContainsString($fragment, $content, $message);
    }

    private function assertFileContainsFragment(string $path, string $fragment, string $message = '')
    {
        $content = file_get_contents(base_path($path));
        $normalizedContent = preg_replace('/\s+/', '', $content);
        $normalizedFragment = preg_replace('/\s+/', '', $fragment);
        $this->assertStringContainsString($normalizedFragment, $normalizedContent, $message);
    }

    public function test_create_ticket_schema_contains_ux_and_preserves_live_search()
    {
        $file = 'app/Filament/Schemas/ZnunyTicketCreationSchema.php';
        $this->assertFileContains($file, 'getPrewarmDatasetState');
        $this->assertFileContains($file, 'queues');
        $this->assertFileContains($file, 'agents');
        $this->assertFileContains($file, 'customer_users');
        $this->assertFileContains($file, 'lookups');

        $this->assertFileContains($file, 'znuny_data_status.consumer.unavailable');
        $this->assertFileContains($file, 'znuny_data_status.consumer.stale');
        $this->assertFileContains($file, 'znuny_data_status.consumer.refreshing');
        $this->assertFileContains($file, 'znuny_data_status.consumer.customer_users_unavailable_search_live');

        $this->assertFileContains($file, 'searchCustomerUserOptions');
        $this->assertFileNotContains($file, '->searchCustomerUsers(');
        $this->assertFileNotContains($file, '->getCustomerUser(');
    }

    public function test_scheduled_form_preserves_current_values()
    {
        $file = 'app/Filament/Resources/ScheduledZnunyTasks/Schemas/ScheduledZnunyTaskForm.php';

        $this->assertFileContainsFragment($file, "
            \$current = \$get('queue_name');
            if (\$current && ! isset(\$options[\$current])) {
                \$options[\$current] = (string) \$current;
            }
        ");

        $this->assertFileContainsFragment($file, "
            \$current = \$get('owner_id');
            \$currentDisplay = \$get('owner_login') ?: \$current;
            if (\$current && ! isset(\$options[\$current])) {
                \$options[\$current] = (string) \$currentDisplay;
            }
        ");

        $this->assertFileContainsFragment($file, "
            \$current = \$get('customer_user_login');
            if (\$current && ! isset(\$options[\$current])) {
                \$label = null;
                try {
                    \$label = \$lookupService->getCustomerUserLabel(\$current);
                } catch (\Throwable \$e) {
                }

                if (\$label) {
                    \$options[\$current] = \$label;
                } else {
                    \$options[\$current] = (string) \$current;
                }
            }
        ");

        $this->assertFileContainsFragment($file, "
            \$current = \$get('priority_name');
            if (\$current && ! isset(\$options[\$current])) {
                \$options[\$current] = (string) \$current;
            }
        ");

        $this->assertFileContainsFragment($file, "
            \$current = \$get('state_name');
            if (\$current && ! isset(\$options[\$current])) {
                \$options[\$current] = (string) \$current;
            }
        ");

        $this->assertFileContains($file, 'searchCustomerUserOptions');
        $this->assertFileNotContains($file, '->searchCustomerUsers(');
        $this->assertFileNotContains($file, '->getCustomerUser(');
    }

    public function test_management_action_preserves_values_and_has_availability_guards()
    {
        $file = 'app/Filament/Support/ZnunyTicketManagementActions.php';
        $content = file_get_contents(base_path($file));

        // Assert current queue fallback from $payload->znuny_queue_name
        $this->assertStringContainsString('$payload->znuny_queue_name', $content);
        // Assert current owner fallback from $payload->znuny_owner_name
        $this->assertStringContainsString('$payload->znuny_owner_name', $content);

        // CustomerUser uses cached preload/label + typed live compatibility method
        $this->assertStringContainsString('getCustomerUserPrimaryOptionsForQueue', $content);
        $this->assertStringContainsString('getCustomerUserLabel', $content);
        $this->assertStringContainsString('searchCustomerUserOptions', $content);

        // Queue/agent availability state is consulted in BOTH reactive invalidation paths
        $normalizedContent = preg_replace('/\s+/', '', $content);
        $normalizedFragment = preg_replace('/\s+/', '', '$qState[\'available\'] && $aState[\'available\']');
        $this->assertSame(
            2,
            substr_count($normalizedContent, $normalizedFragment)
        );

        // No direct CustomerUser client reads
        $this->assertStringNotContainsString('->searchCustomerUsers(', $content);
        $this->assertStringNotContainsString('->getCustomerUser(', $content);
    }

    public function test_modal_builder_contains_dataset_warnings()
    {
        $file = 'app/Services/Znuny/ZnunyTicketModalStateBuilder.php';
        $this->assertFileContains($file, 'getPrewarmDatasetState');
        $this->assertFileContains($file, 'queues');
        $this->assertFileContains($file, 'agents');
        $this->assertFileContains($file, 'customer_users');
        $this->assertFileContains($file, 'lookups');
        $this->assertFileContains($file, 'array_unique');
    }

    public function test_queue_service_contains_prewarm_wording()
    {
        $file = 'app/Services/Znuny/ZnunyQueueService.php';
        $this->assertFileContains($file, 'prewarmed Znuny queue reference data');
        $this->assertFileNotContains($file, 'Could not load queues from Znuny API');
    }

    public function test_translations_contain_ux_keys()
    {
        $files = [
            'lang/en/znuny_data_status.php',
            'lang/uk/znuny_data_status.php',
        ];

        foreach ($files as $file) {
            $translations = require base_path($file);
            $this->assertSame([
                'unavailable',
                'stale',
                'refreshing',
                'customer_users_unavailable_search_live',
            ], array_keys($translations['consumer']));
        }
    }
}
