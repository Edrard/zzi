<?php

return [
    'navigation_label' => 'Create ticket',
    'title' => 'Create Znuny ticket',

    'sections' => [
        'ticket_details' => 'Ticket details',
        'advanced_options' => 'Advanced ticket options',
    ],

    'fields' => [
        'queue' => 'Queue',
        'owner' => 'Owner',
        'customer_user' => 'Customer user',
        'title' => 'Title',
        'body' => 'Article body',
        'priority' => 'Priority',
        'state' => 'State',
        'lock' => 'Lock',
    ],

    'actions' => [
        'submit' => 'Create ticket',
    ],

    'messages' => [
        'no_options_available' => 'No options available.',
    ],

    'notifications' => [
        'creation_failed' => [
            'title' => 'Ticket Creation Failed',
        ],
        'created' => [
            'title' => 'Ticket Created',
            'body' => 'Znuny ticket :ticket_number has been created successfully.',
        ],
    ],

    'priorities' => [
        '1 very low' => '1 very low',
        '2 low' => '2 low',
        '3 normal' => '3 normal',
        '4 high' => '4 high',
        '5 very high' => '5 very high',
    ],

    'states' => [
        'closed successful' => 'Closed successfully',
        'closed unsuccessful' => 'Closed unsuccessfully',
        'merged' => 'Merged',
        'new' => 'New',
        'open' => 'Open',
        'pending auto close+' => 'Pending auto close+',
        'pending auto close-' => 'Pending auto close-',
        'pending reminder' => 'Pending reminder',
        'removed' => 'Removed',
    ],

    'locks' => [
        'lock' => 'Locked',
        'unlock' => 'Unlocked',
    ],

    'errors' => [
        'missing_owner_queue_user' => 'Owner, queue, and customer user are required.',
        'missing_title_body' => 'Ticket title and article body are required.',
        'missing_state_priority' => 'State and priority are required by the Znuny API.',
        'missing_ticket_number' => 'Znuny reported success but did not return a ticket ID or ticket number.',
        'failed_to_resolve_user' => 'Could not resolve the customer user: :customer_user',
        'user_has_no_customer_id' => 'The customer user “:customer_user” has no CustomerID/UserCustomerID assigned.',
        'unexpected_error' => 'An unexpected error occurred while creating the ticket.',
    ],
];
