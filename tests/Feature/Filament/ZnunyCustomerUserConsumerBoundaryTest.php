<?php

namespace Tests\Feature\Filament;

use Tests\TestCase;

class ZnunyCustomerUserConsumerBoundaryTest extends TestCase
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

    public function test_migrated_ui_files_contain_no_direct_live_customer_user_calls()
    {
        $files = [
            'app/Filament/Schemas/ZnunyTicketCreationSchema.php',
            'app/Filament/Resources/ScheduledZnunyTasks/Schemas/ScheduledZnunyTaskForm.php',
            'app/Filament/Support/ZnunyTicketManagementActions.php',
            'app/Filament/Pages/CurrentZabbixProblems.php',
        ];

        foreach ($files as $file) {
            $this->assertFileNotContains($file, '->searchCustomerUsers(');
            $this->assertFileNotContains($file, '->getCustomerUser(');
        }
    }

    public function test_form_schemas_delegate_non_empty_typed_search()
    {
        $schemas = [
            'app/Filament/Schemas/ZnunyTicketCreationSchema.php',
            'app/Filament/Resources/ScheduledZnunyTasks/Schemas/ScheduledZnunyTaskForm.php',
        ];

        foreach ($schemas as $schema) {
            $this->assertFileContains($schema, '->searchCustomerUserOptions(');
        }
    }

    public function test_form_schemas_retain_queue_based_empty_search_preload()
    {
        $schemas = [
            'app/Filament/Schemas/ZnunyTicketCreationSchema.php',
            'app/Filament/Resources/ScheduledZnunyTasks/Schemas/ScheduledZnunyTaskForm.php',
        ];

        foreach ($schemas as $schema) {
            $this->assertFileContains($schema, '->getCustomerUserPrimaryOptionsForQueue(');
        }
    }

    public function test_form_schemas_use_cache_only_label_compatibility()
    {
        $schemas = [
            'app/Filament/Schemas/ZnunyTicketCreationSchema.php',
            'app/Filament/Resources/ScheduledZnunyTasks/Schemas/ScheduledZnunyTaskForm.php',
        ];

        foreach ($schemas as $schema) {
            $this->assertFileContains($schema, '->getCustomerUserLabel(');
        }
    }

    public function test_form_schemas_retain_cache_only_queue_candidate_resolution()
    {
        $schemas = [
            'app/Filament/Schemas/ZnunyTicketCreationSchema.php',
            'app/Filament/Resources/ScheduledZnunyTasks/Schemas/ScheduledZnunyTaskForm.php',
        ];

        foreach ($schemas as $schema) {
            $this->assertFileContains($schema, '->resolveTemplateCandidate(');
        }
    }

    public function test_management_action_uses_allowed_cached_methods()
    {
        $file = 'app/Filament/Support/ZnunyTicketManagementActions.php';
        $this->assertFileContains($file, '->searchCustomerUserOptions(');
        $this->assertFileContains($file, '->getCustomerUserPrimaryOptionsForQueue(');
        $this->assertFileContains($file, '->getCustomerUserLabel(');
    }

    public function test_current_zabbix_problems_search_ticket_customer_users_delegates_to_options()
    {
        $file = 'app/Filament/Pages/CurrentZabbixProblems.php';
        $this->assertFileContains($file, '->searchCustomerUserOptions(');
    }

    public function test_unchanged_scheduled_task_table_uses_only_allowed_cached_methods_and_no_direct_read()
    {
        $file = 'app/Filament/Resources/ScheduledZnunyTasks/Tables/ScheduledZnunyTasksTable.php';
        $this->assertFileContains($file, '->getCustomerUserLabel(');
        $this->assertFileNotContains($file, '->searchCustomerUsers(');
        $this->assertFileNotContains($file, '->getCustomerUser(');
    }
}
