<?php

return [
    'navigation_label' => 'Znuny Data Status',
    'title' => 'Znuny Data Status',

    'datasets' => [
        'queues' => 'Queues',
        'agents' => 'Agents and Queue Access',
        'lookups' => 'Lookups',
        'customer_users' => 'CustomerUsers by Queues',
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
    ],

    'status' => [
        'ready' => 'Ready',
        'refreshing' => 'Refreshing',
        'stale' => 'Stale',
        'failed' => 'Failed',
        'missing' => 'Missing',
        'unknown' => 'Unknown',
    ],

    'notifications' => [
        'success_title' => 'Dataset ":dataset" successfully refreshed',
        'skipped_locked_title' => 'Refresh skipped because process is already running',
        'timeout_title' => 'Timeout refreshing ":dataset"',
        'error_title' => 'Error refreshing ":dataset"',
    ],

    'descriptions' => [
        'queues' => 'Number of normalized queues.',
        'agents' => 'Number of agents. The matrix of agent access to queues is stored in the dataset but not counted separately.',
        'lookups' => 'Total number of states, priorities, and types.',
        'customer_users' => 'Sum of final CustomerUsers variants across all queues. This is not the number of globally unique users.',
    ],

    'actions' => [
        'refresh_now' => 'Refresh Now',
    ],



    'values' => [
        'never' => 'Never',
        'none' => 'None',
        'minutes' => 'min',
    ],
];
