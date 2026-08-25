<?php

return [
    'navigation_label' => 'Znuny Data Status',
    'title' => 'Znuny Data Status',

    'datasets' => [
        'queues' => 'Queues',
        'agents' => 'Agents and Queue Access',
        'lookups' => 'Lookups',
        'customer_users' => 'CustomerUsers by Queues',
        'inline_images' => 'Inline Images Cache',
    ],

    'fields' => [
        'dataset_name' => 'Dataset Name',
        'internal_key' => 'Internal Key',
        'status' => 'Status',
        'item_count' => 'Item Count',
        'last_attempt_at' => 'Last Attempt',
        'last_successful_refresh_at' => 'Last Successful Refresh',
        'active_generation' => 'Active Generation',
        'interval' => 'Refresh Interval',
        'last_error' => 'Last Error',
        'tail_offset' => 'Tail Offset',
        'ttl' => 'Cache TTL',
        'warmer_parameters' => 'Warmer Parameters',
    ],

    'status' => [
        'ready' => 'Ready',
        'refreshing' => 'Refreshing',
        'stale' => 'Stale',
        'failed' => 'Failed',
        'missing' => 'Missing',
        'unknown' => 'Unknown',
        'disabled' => 'Disabled',
        'pending' => 'Pending',
        'stale_inline' => 'Expired',
        'running' => 'Running',
    ],

    'notifications' => [
        'success_title' => 'Dataset ":dataset" successfully refreshed',
        'skipped_locked_title' => 'Refresh skipped because process is already running',
        'timeout_title' => 'Timeout refreshing ":dataset"',
        'error_title' => 'Error refreshing ":dataset"',
        'inline_disabled_title' => 'Warming ":dataset" is disabled',
        'inline_warning_title' => 'Warming ":dataset" completed with warnings',
        'inline_warning_body' => 'Processing errors: :count.',
        'inline_skipped_title' => 'Warming ":dataset" was not performed',
        'inline_skipped_body' => 'The current configuration does not allow warming to run.',
    ],

    'descriptions' => [
        'queues' => 'Number of normalized queues.',
        'agents' => 'Number of agents. The matrix of agent access to queues is stored in the dataset but not counted separately.',
        'lookups' => 'Total number of states, priorities, and types.',
        'customer_users' => 'Sum of final CustomerUsers variants across all queues. This is not the number of globally unique users.',
        'inline_images' => 'Number of cached inline images.',
        'tail_offset' => 'Rotating cursor position for the tail ticket window.',
        'warmer_parameters' => 'Maximum batch / hot ticket percentage.',
    ],

    'actions' => [
        'refresh_now' => 'Refresh Now',
    ],

    'values' => [
        'never' => 'Never',
        'none' => 'None',
        'minutes' => 'min',
        'unknown' => 'Unknown',
    ],

    'consumer' => [
        'unavailable' => 'Znuny reference data is currently unavailable.',
        'stale' => 'Using older cached Znuny reference data.',
        'refreshing' => 'Znuny reference data is currently refreshing. Using the latest available cached data.',
        'customer_users_unavailable_search_live' => 'CustomerUser preload is currently unavailable. You can still type to search CustomerUsers.',
    ],
];
