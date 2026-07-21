<?php

return [
    'navigation' => [
        'label' => 'Audit Log',
    ],
    'model' => [
        'label' => 'Audit Log',
        'plural_label' => 'Audit Logs',
    ],
    'table' => [
        'columns' => [
            'id' => [
                'label' => 'ID',
            ],
            'created_at' => [
                'label' => 'Timestamp',
            ],
            'user' => [
                'label' => 'User',
            ],
            'action' => [
                'label' => 'Action',
            ],
            'entity_type' => [
                'label' => 'Entity Type',
            ],
            'entity_id' => [
                'label' => 'Entity ID',
            ],
            'ip_address' => [
                'label' => 'IP Address',
            ],
            'user_agent' => [
                'label' => 'User Agent',
            ],
        ],
    ],
    'infolist' => [
        'sections' => [
            'details' => [
                'heading' => 'Log Details',
            ],
            'context' => [
                'heading' => 'Context',
            ],
        ],
        'entries' => [
            'id' => [
                'label' => 'ID',
            ],
            'created_at' => [
                'label' => 'Timestamp',
            ],
            'user' => [
                'label' => 'User',
            ],
            'action' => [
                'label' => 'Action',
            ],
            'entity_type' => [
                'label' => 'Entity Type',
            ],
            'entity_id' => [
                'label' => 'Entity ID',
            ],
            'ip_address' => [
                'label' => 'IP Address',
            ],
            'user_agent' => [
                'label' => 'User Agent',
            ],
        ],
    ],
    'entity_types' => [
        'system' => 'System',
        'settings' => 'Settings',
        'zabbix_problem' => 'Zabbix Problem',
        'zabbix_problem_filter' => 'Zabbix Problem Filter',
        'zabbix_ticket' => 'Zabbix Ticket',
        'user' => 'User',
        'cleanup' => 'Cleanup',
    ],
    'actions' => [
        'settings' => [
            'updated' => 'Settings Updated',
            'znuny_connection_tested' => 'Znuny Connection Tested',
            'zabbix_connection_tested' => 'Zabbix Connection Tested',
        ],
        'user' => [
            'locked' => 'User Locked',
            'updated' => 'User Updated',
            'created' => 'User Created',
        ],
        'zabbix_problem_filter' => [
            'updated' => 'Problem Filter Updated',
            'created' => 'Problem Filter Created',
            'deleted' => 'Problem Filter Deleted',
        ],
        'cleanup' => [
            'finished' => 'Cleanup Finished',
        ],
        'znuny_ticket_sync_updated' => 'Znuny Ticket Sync Updated',
        'znuny_ticket_sync_missing' => 'Znuny Ticket Sync Missing',
        'znuny_ticket_sync_failed' => 'Znuny Ticket Sync Failed',
        'zabbix_ticket' => [
            'link_created' => 'Zabbix Ticket Link Created',
        ],
        'znuny' => [
            'manual_ticket_create' => [
                'attempt' => 'Manual Ticket Create Attempt',
                'locked' => 'Manual Ticket Create Locked',
                'duplicate' => 'Manual Ticket Create Duplicate',
                'failed' => 'Manual Ticket Create Failed',
                'orphaned' => 'Manual Ticket Create Orphaned',
                'created' => 'Manual Ticket Created',
            ],
            'closed_ticket' => [
                'sync' => 'Closed Ticket Sync',
            ],
            'ticket_workspace_sync' => [
                'skipped' => 'Ticket Workspace Sync Skipped',
                'completed' => 'Ticket Workspace Sync Completed',
                'failed' => 'Ticket Workspace Sync Failed',
            ],
            'linked_tickets_sync' => [
                'failed' => 'Linked Tickets Sync Failed',
                'completed' => 'Linked Tickets Sync Completed',
            ],
            'auto_close' => [
                'dry_run' => 'Auto Close Dry Run',
                'success' => 'Auto Close Success',
                'failed' => 'Auto Close Failed',
            ],
        ],
        'zabbix' => [
            'problems_poll_recovered' => 'Problems Poll Recovered',
            'problems_poll_completed' => 'Problems Poll Completed',
            'problems_poll_failed' => 'Problems Poll Failed',
        ],
    ],
    'labels' => [
        'no_context' => 'No context',
        'raw_context' => 'Raw context',
        'stats' => 'Stats',
        'warnings' => 'Warnings:',
    ],
];
