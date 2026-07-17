<?php

return [
    'resource' => [
        'singular' => 'User',
        'plural' => 'Users',
    ],
    'pages' => [
        'list' => [
            'title' => 'Users',
        ],
        'create' => [
            'title' => 'Create user',
        ],
        'edit' => [
            'title' => 'Edit user',
        ],
    ],
    'form' => [
        'sections' => [
            'user_details' => [
                'heading' => 'User details',
                'description' => 'Manage the user’s identity, role, status, and password.',
            ],
        ],
        'fields' => [
            'name' => [
                'label' => 'Name',
            ],
            'email' => [
                'label' => 'Email',
            ],
            'role' => [
                'label' => 'Role',
            ],
            'is_active' => [
                'label' => 'Active',
            ],
            'password' => [
                'label' => 'Password',
            ],
            'password_confirmation' => [
                'label' => 'Password confirmation',
            ],
        ],
    ],
    'table' => [
        'columns' => [
            'name' => [
                'label' => 'Name',
            ],
            'email' => [
                'label' => 'Email',
            ],
            'role' => [
                'label' => 'Role',
            ],
            'is_active' => [
                'label' => 'Active',
            ],
            'created_at' => [
                'label' => 'Created at',
            ],
            'updated_at' => [
                'label' => 'Updated at',
            ],
        ],
    ],
    'roles' => [
        'admin' => 'Administrator',
        'operator' => 'Operator',
        'viewer' => 'Viewer',
    ],
];
