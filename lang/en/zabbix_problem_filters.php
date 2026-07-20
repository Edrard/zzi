<?php

return [
    'resource' => [
        'singular' => 'Ignore filter',
        'plural' => 'Ignore filters',
    ],
    'form' => [
        'enabled' => 'Enabled',
        'name' => 'Name',
        'field' => 'Field',
        'match_type' => 'Match type',
        'pattern' => 'Pattern',
        'pattern_helper' => 'Example: /^Zabbix proxy.*$/ (must include delimiters)',
        'pattern_invalid' => 'Invalid regular expression. Ensure you include delimiters (e.g. /pattern/).',
        'case_sensitive' => 'Case sensitive',
        'description' => 'Description',
        'field_options' => [
            'name' => 'Problem name',
            'host' => 'Host name',
        ],
        'match_type_options' => [
            'contains' => 'Contains',
            'regex' => 'Regular expression',
        ],
    ],
    'table' => [
        'enabled' => 'Enabled',
        'name' => 'Name',
        'field' => 'Field',
        'match_type' => 'Match type',
        'pattern' => 'Pattern',
        'updated_at' => 'Updated at',
    ],
];
