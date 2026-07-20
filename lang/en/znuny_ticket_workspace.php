<?php

return [
    'navigation' => [
        'label' => 'Ticket workspace',
        'title' => 'Ticket workspace',
    ],
    'actions' => [
        'refresh_from_znuny' => [
            'label' => 'Refresh from Znuny',
            'notifications' => [
                'disabled_title' => 'Ticket Workspace is disabled',
                'disabled_body' => 'Enable Ticket Workspace in Settings before running synchronization or refresh actions.',
                'disabled_page_body' => 'Enable Ticket Workspace in Settings to resume active and closed ticket synchronization, manual refresh actions, and cached ticket access. Existing cached data is retained.',
                'failed_title' => 'Failed to refresh Ticket Workspace',
                'failed_body_fallback' => 'The cache warmer command failed.',
                'success_title' => 'Ticket Workspace refreshed successfully',
                'success_body_prefix' => 'Active refresh completed. Article cache was cleared.',
                'closed_sync_failed' => "\nRecent closed sync failed: :error",
                'closed_sync_skipped' => "\nRecent closed sync skipped (locked).",
                'closed_sync_completed' => "\nRecent closed sync completed (Fetched :fetched, Cached :cached).",
                'exception_title' => 'An error occurred while refreshing Ticket Workspace',
            ],
        ],
    ],
    'legend' => [
        'title' => 'Ticket workspace legend',
        'active' => 'Linked to active Zabbix problem',
        'resolved' => 'Linked to resolved Zabbix problem',
        'warning' => 'Active problem on closed/merged ticket',
    ],
    'cache_diagnostics' => [
        'title' => 'Recent closed-ticket cache status',
        'status' => 'Status',
        'window_days' => 'Window days',
        'retention_days' => 'Retention days',
        'last_mode' => 'Last mode',
        'last_reason' => 'Last reason',
        'last_small_completed_at' => 'Last small update completed at',
        'last_full_completed_at' => 'Last full update completed at',
        'oldest_loaded_closed_at' => 'Oldest loaded closed at',
        'newest_loaded_closed_at' => 'Newest loaded closed at',
        'last_run_started_at' => 'Last run started at',
        'last_run_completed_at' => 'Last run completed at',
        'last_error' => 'Last Error',
        'not_completed_yet' => 'Recent closed ticket cache has not completed a full sync yet.',
        'sync_is_running' => 'Sync is currently running.',
        'values' => [
            'complete' => 'Complete',
            'small' => 'Small',
            'full' => 'Full',
            'scheduled' => 'Scheduled',
        ],
    ],
    'presets' => [
        'open' => 'Open',
        'closed' => 'Closed',
        'merged' => 'Merged',
        'all' => 'All',
    ],
    'search' => [
        'placeholder' => 'Search ticket number or title...',
    ],
    'filters' => [
        'link' => [
            'all' => 'All tickets',
            'linked' => 'Linked to a Zabbix problem',
            'linked_active' => 'Linked to an active problem',
            'linked_resolved' => 'Linked to a resolved/recovered problem',
            'unlinked' => 'Unlinked tickets',
        ],
        'state_types' => [
            'label' => 'State types: :count',
            'options' => [
                'new' => 'New',
                'open' => 'Open',
                'pending reminder' => 'Pending reminder',
                'pending auto' => 'Pending auto-close',
                'closed' => 'Closed',
                'merged' => 'Merged',
            ],
        ],
        'queue' => [
            'any' => 'Any queue',
        ],
        'owner' => [
            'any' => 'Any owner',
        ],
    ],
    'pagination' => [
        'showing' => 'Showing :from–:to of :total tickets',
        'per_page' => 'Per page',
        'previous' => 'Previous',
        'next' => 'Next',
    ],
    'table' => [
        'headings' => [
            'ticket_number' => 'Ticket number',
            'title' => 'Title',
            'queue' => 'Queue',
            'owner' => 'Owner',
            'state_type' => 'State / type',
            'priority' => 'Priority',
            'articles' => 'Articles',
            'changed' => 'Changed',
        ],
        'customer' => 'Customer:',
    ],
    'tooltips' => [
        'zabbix' => [
            'host' => 'Host',
            'problem' => 'Problem',
            'state' => 'State',
            'age' => 'Age',
            'na' => 'N/A',
            'active' => 'Active',
            'resolved' => 'Resolved',
        ],
    ],
    'empty_states' => [
        'no_tickets' => 'No tickets found',
        'no_tickets_description' => 'Run the Ticket Workspace cache warmer.',
        'no_matches' => 'No tickets match the selected filters.',
        'no_matches_description' => 'Try adjusting your search query or filters.',
        'data_unavailable' => 'Data is unavailable',
        'not_available' => 'Not available',
    ],
];
