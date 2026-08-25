<?php

namespace Tests\Feature\Filament\Resources;

use Tests\TestCase;

class ScheduledZnunyTaskRunResourceLocalizationTest extends TestCase
{
    public function test_new_translation_keys_resolve_correctly()
    {
        $originalLocale = app()->getLocale();

        try {
            app()->setLocale('en');
            // Form keys
            $this->assertEquals('Task Name', __('scheduled_znuny_tasks.form.task_name'));
            $this->assertEquals('Enabled', __('scheduled_znuny_tasks.form.enabled'));
            $this->assertEquals('Cron Expression', __('scheduled_znuny_tasks.form.cron'));
            // Run keys
            $this->assertEquals('Requeue Run', __('scheduled_znuny_task_runs.actions.requeue_run'));
            $this->assertEquals('Run Requeued', __('scheduled_znuny_task_runs.actions.run_requeued_title'));
            $this->assertEquals('Manual Review Note', __('scheduled_znuny_task_runs.actions.manual_review_note'));

            app()->setLocale('uk');
            // Form keys
            $this->assertEquals('Назва завдання', __('scheduled_znuny_tasks.form.task_name'));
            $this->assertEquals('Увімкнено', __('scheduled_znuny_tasks.form.enabled'));
            $this->assertEquals('Cron-вираз', __('scheduled_znuny_tasks.form.cron'));
            // Run keys
            $this->assertEquals('Повторно поставити запуск у чергу', __('scheduled_znuny_task_runs.actions.requeue_run'));
            $this->assertEquals('Запуск повторно поставлено в чергу', __('scheduled_znuny_task_runs.actions.run_requeued_title'));
            $this->assertEquals('Примітка ручної перевірки', __('scheduled_znuny_task_runs.actions.manual_review_note'));
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_hardcoded_literals_removed_from_class()
    {
        $formPath = app_path('Filament/Resources/ScheduledZnunyTasks/Schemas/ScheduledZnunyTaskForm.php');
        $runPath = app_path('Filament/Resources/ScheduledZnunyTaskRuns/ScheduledZnunyTaskRunResource.php');

        $formContent = file_get_contents($formPath);
        $runContent = file_get_contents($runPath);

        // Assert hard-coded strings are absent
        $this->assertStringNotContainsString("->label('Task Name')", $formContent);
        $this->assertStringNotContainsString("->label('Enabled')", $formContent);
        $this->assertStringNotContainsString("->label('Cron Expression')", $formContent);
        $this->assertStringNotContainsString("Section::make('Task Details')", $formContent);

        $this->assertStringNotContainsString("->label('Requeue Run')", $runContent);
        $this->assertStringNotContainsString("->title('Run Requeued')", $runContent);
        $this->assertStringNotContainsString("->label('Manual Review Note')", $runContent);
        $this->assertStringNotContainsString("Section::make('Run Information')", $runContent);

        // Assert dynamic values and critical bindings remain present
        $this->assertStringContainsString('__(\'scheduled_znuny_tasks.form.task_name\')', $formContent);
        $this->assertStringContainsString('TextInput::make(\'name\')', $formContent);
        $this->assertStringContainsString('Toggle::make(\'enabled\')', $formContent);

        $this->assertStringContainsString('__(\'scheduled_znuny_task_runs.actions.review_attempt\')', $runContent);
        $this->assertStringContainsString('Action::make(\'review_attempt\')', $runContent);
    }
}
