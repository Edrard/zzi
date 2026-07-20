<?php

return [
    'navigation' => [
        'label' => 'Linked tickets',
        'plural' => 'Linked tickets',
        'singular' => 'Linked ticket',
    ],
    'actions' => [
        'sync_tickets' => [
            'label' => 'Sync tickets',
            'notifications' => [
                'success_title' => 'Sync successful',
                'success_completed' => 'Sync command completed.',
                'lifecycle_completed' => 'Lifecycle evaluation completed.',
                'errors_title' => 'Sync completed with errors',
                'lifecycle_failed' => 'Lifecycle evaluation failed.',
                'failed_title' => 'Sync failed',
                'failed_incomplete' => 'The sync command failed to complete.',
                'failed_error' => 'An error occurred during synchronization.',
            ],
        ],
        'view_ticket' => [
            'label' => 'View ticket',
            'modal_heading' => 'Ticket details',
        ],
    ],
    'table' => [
        'columns' => [
            'host' => 'Host',
            'problem' => 'Problem',
            'state' => 'State',
            'zabbix' => 'Zabbix',
            'ticket_age' => 'Ticket age',
        ],
        'empty_state' => [
            'heading' => 'No linked tickets',
            'description' => 'No linked tickets match your search.',
        ],
        'search_placeholder' => 'Search linked tickets',
    ],
    'znuny_states' => [
        'new' => 'New',
        'open' => 'Open',
        'pending reminder' => 'Pending reminder',
        'pending auto close+' => 'Pending auto close (successful)',
        'pending auto close-' => 'Pending auto close (unsuccessful)',
        'closed successful' => 'Closed successfully',
        'closed unsuccessful' => 'Closed unsuccessfully',
        'merged' => 'Merged',
        'removed' => 'Removed',
    ],
    'zabbix_statuses' => [
        'flapping' => [
            'label' => 'Flapping',
            'tooltip' => 'Flapping problem detected.',
        ],
        'reopen_candidate' => [
            'label' => 'Manual reopen candidate',
            'tooltip' => 'The Znuny ticket is closed, but the linked Zabbix problem is active again within the reopen window. Review manually.',
        ],
        'reopened' => [
            'label' => 'Reopened',
            'tooltip' => 'Manually reopened ticket.',
        ],
        'closed' => [
            'label' => 'Closed',
        ],
        'ready' => [
            'label' => 'Ready',
            'tooltip' => 'Linked Zabbix problem is resolved and close delay has passed.',
        ],
        'waiting' => [
            'label' => 'Waiting for close delay',
            'tooltip' => 'Linked Zabbix problem is resolved, waiting for close delay.',
        ],
        'cache_stale' => [
            'label' => 'Cache stale',
            'tooltip' => 'Zabbix problem cache may be stale. Waiting for sync.',
        ],
        'identity_missing' => [
            'label' => 'Missing Zabbix identity',
            'tooltip' => 'Missing Zabbix host/trigger identity; lifecycle cannot be evaluated safely.',
        ],
        'active' => [
            'label' => 'Active',
            'tooltip' => 'Linked Zabbix problem is still active.',
        ],
        'unknown' => [
            'label' => 'Unknown',
            'tooltip' => 'Lifecycle state has not been evaluated yet.',
        ],
    ],
    'details_modal' => [
        'sections' => [
            'ticket' => 'Ticket',
            'znuny_attributes' => 'Znuny attributes',
            'zabbix' => 'Zabbix',
            'sync' => 'Sync',
            'articles_notes' => 'Articles / Notes',
        ],
        'fields' => [
            'number' => 'Ticket number',
            'title' => 'Title',
            'created_at' => 'Created at',
            'updated_at' => 'Updated at',
            'reopened_at' => 'Reopened at',
            'context' => 'Context',
            'resolved_at' => 'Resolved at',
            'auto_close_at' => 'Auto-close at',
            'closed_at' => 'Closed at',
            'flap_count' => 'Flap count',
            'last_flap_at' => 'Last flap at',
            'queue' => 'Queue',
            'owner' => 'Owner',
            'customer' => 'Customer user',
            'priority' => 'Priority',
            'state' => 'State',
            'lock_status' => 'Lock status',
            'last_article' => 'Last article',
            'host' => 'Host',
            'problem' => 'Problem',
            'event_id' => 'Event ID',
            'last_checked' => 'Last checked',
            'last_synced' => 'Last synced',
            'sync_error' => 'Sync error',
        ],
        'lock_statuses' => [
            'locked' => 'Locked',
            'unlocked' => 'Unlocked',
            'unknown' => 'Unknown',
        ],
        'placeholders' => [
            'not_synced' => 'Not synced',
            'sync_error' => 'Sync error',
        ],
    ],
];
