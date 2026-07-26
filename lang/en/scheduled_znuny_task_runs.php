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
        'review_attempt' => 'Review Attempt',
    ],

    'review' => [
        'title' => 'Review Creation Attempt',
        'sections' => [
            'task' => 'Scheduled Task',
            'run' => 'Original Run',
            'attempt' => 'Creation Attempt',
            'lookup' => 'Latest Lookup',
            'matches' => 'Lookup Matches',
        ],
        'actions' => [
            'review_attempt' => 'Review Attempt',
            'recheck' => 'Fresh Recheck',
        ],
        'fields' => [
            'task_id' => 'Task ID',
            'task_name' => 'Task Name',
            'task_enabled' => 'Task Enabled',
            'run_id' => 'Run ID',
            'run_type' => 'Run Type',
            'run_status' => 'Run Status',
            'scheduled_time' => 'Scheduled Time',
            'start_time' => 'Start Time',
            'finish_time' => 'Finish Time',
            'attempt_id' => 'Attempt ID',
            'attempt_status' => 'Attempt Status',
            'source_type' => 'Source Type',
            'marker' => 'Marker',
            'subject_original' => 'Original Subject',
            'subject_sent' => 'Sent Subject',
            'check_count' => 'Check Count',
            'started_time' => 'Started Time',
            'last_checked_time' => 'Last Checked Time',
            'stored_ticket_id' => 'Stored Ticket ID',
            'stored_ticket_number' => 'Stored Ticket Number',
            'lookup_status' => 'Lookup Status',
            'lookup_reason' => 'Lookup Reason',
            'refresh_attempted' => 'Refresh Attempted',
            'refresh_succeeded' => 'Refresh Succeeded',
            'refresh_exit_code' => 'Refresh Exit Code',
            'last_rechecked_at' => 'Last Rechecked At',
            'ticket_id' => 'Ticket ID',
            'ticket_number' => 'Ticket Number',
            'ticket_title' => 'Title',
            'ticket_state' => 'State',
            'ticket_state_type' => 'State Type',
            'ticket_queue' => 'Queue',
            'yes' => 'Yes',
            'no' => 'No',
        ],
        'lookup_statuses' => [
            'found' => 'Found',
            'multiple' => 'Multiple Matches',
            'not_found' => 'Not Found',
            'unavailable' => 'Unavailable',
        ],
        'notifications' => [
            'found' => [
                'title' => 'Ticket Found',
                'body' => 'A matching ticket was found.',
            ],
            'multiple' => [
                'title' => 'Multiple Tickets Found',
                'body' => 'Multiple matching tickets were found. Please review the matches.',
            ],
            'not_found' => [
                'title' => 'No Ticket Found',
                'body' => 'No matching ticket was found.',
            ],
            'unavailable' => [
                'title' => 'Lookup Unavailable',
                'body' => 'The ticket system is currently unavailable.',
            ],
            'changed' => [
                'title' => 'Attempt State Changed',
                'body' => 'The attempt changed while the operation was being performed. The current state has been reloaded.',
            ],
        ],
        'empty' => [
            'matches' => 'No matching tickets found.',
            'reason' => 'No reason provided.',
            'not_available' => 'Not available.',
        ],
    ],
];
