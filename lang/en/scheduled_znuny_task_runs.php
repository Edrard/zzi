<?php

return [
    'singular' => 'Run log entry',
    'plural' => 'Run log entries',
    'navigation_label' => 'Run log',
    'empty_state' => 'No run log entries found',

    'table' => [
        'created_at' => 'Time',
        'task_name_snapshot' => 'Task',
        'run_type' => 'Run type',
        'scheduled_for' => 'Scheduled for',
        'started_at' => 'Started at',
        'finished_at' => 'Finished at',
        'duration_ms' => 'Execution time',
        'status' => 'Status',
        'ticket_number' => 'Ticket number',
        'error_summary' => 'Error summary',
    ],

    'filters' => [
        'scheduled_znuny_task_id' => 'Task',
        'status' => 'Status',
        'run_type' => 'Run type',
        'has_ticket' => 'Has ticket',
        'has_error' => 'Has error',
        'created_at_from' => 'From',
        'created_at_until' => 'Until',
    ],

    'statuses' => [
        'pending' => 'Pending',
        'running' => 'Running',
        'success' => 'Success',
        'failed' => 'Failed',
        'skipped' => 'Skipped',
        'duplicate' => 'Duplicate',
        'uncertain' => 'Uncertain',
    ],

    'run_types' => [
        'scheduled' => 'Scheduled',
        'manual' => 'Manual',
        'catch_up' => 'Catch-up',
        'manual_retry' => 'Manual retry',
    ],

    'units' => [
        'sec' => 'sec',
    ],

    'sections' => [
        'run_information' => 'Run Information',
        'ticket_details' => 'Ticket Details',
        'errors' => 'Errors',
        'snapshots' => 'Snapshots',
    ],

    'actions' => [
        'requeue_run' => 'Requeue Run',
        'run_requeued_title' => 'Run Requeued',
        'run_requeued_body' => 'A new pending run has been created.',
        'resolve_run' => 'Resolve Run',
        'manual_review_note' => 'Manual Review Note',
        'manual_review_help' => 'Explain how this uncertain run was resolved manually in Znuny.',
        'run_resolved_title' => 'Run Resolved',
        'open_ticket' => 'Open Ticket',
        'open_task' => 'Open Task',
    ],
];
