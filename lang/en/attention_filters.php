<?php

return [
    'resource' => [
        'singular' => 'Attention filter',
        'plural' => 'Attention filters',
    ],
    'form' => [
        'enabled' => 'Enabled',
        'name' => 'Name',
        'name_helper' => 'A clear name that explains what this filter detects.',
        'pattern' => 'Pattern',
        'pattern_helper' => 'Example: /^Zabbix proxy.*$/ (must include delimiters)',
        'pattern_invalid' => 'Invalid regular expression. Ensure you include delimiters (e.g. /pattern/).',
        'description' => 'Description',
    ],
    'table' => [
        'enabled' => 'Enabled',
        'name' => 'Name',
        'pattern' => 'Pattern',
        'updated_at' => 'Updated at',
    ],
    'actions' => [
        'create' => [
            'heading' => 'Create attention filter',
        ],
        'edit' => [
            'heading' => 'Edit attention filter',
        ],
        'delete' => [
            'heading' => 'Delete attention filter',
            'description' => 'Are you sure you want to delete this attention filter?',
        ],
    ],
    'empty_states' => [
        'no_records' => 'No attention filters',
        'no_records_description' => 'Create an attention filter to define items that require attention.',
        'no_matches' => 'No attention filters match your search.',
    ],
    'search' => [
        'placeholder' => 'Search attention filters',
    ],
    'notifications' => [
        'created' => 'Attention filter created',
        'updated' => 'Attention filter updated',
        'deleted' => 'Attention filter deleted',
        'save_failed' => 'Unable to save attention filter',
        'delete_failed' => 'Unable to delete attention filter',
    ],
];
