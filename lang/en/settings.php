<?php

return [
    'general' => [
        'main' => [
            'ui_locale' => [
                'label' => 'Default interface language',
                'helper_text' => 'Used on the sign-in page and for users who have not selected a personal interface language.',
            ],
        ],
    ],
    'my_settings' => [
        'sections' => [
            'profile_password' => [
                'title' => 'Profile / Password',
                'description' => 'Update your account password. Leave blank if you do not wish to change it.',
            ],
            'personalization' => [
                'title' => 'Personalization',
                'description' => 'Customize your interface.',
            ],
            'startup' => [
                'title' => 'Startup / Default page',
                'description' => 'Choose which page you land on after logging in.',
            ],
            'admin_ui_preferences' => [
                'title' => 'Admin UI Preferences',
                'description' => 'Toggle visibility of diagnostic panels.',
            ],
        ],
        'fields' => [
            'current_password' => [
                'label' => 'Current password',
            ],
            'new_password' => [
                'label' => 'New password',
            ],
            'new_password_confirmation' => [
                'label' => 'Confirm new password',
            ],
            'default_landing_page' => [
                'label' => 'Default landing page',
            ],
            'show_current_problems_status_panel' => [
                'label' => 'Show Current Problems polling status panel',
            ],
            'show_znuny_closed_ticket_status_panel' => [
                'label' => 'Show Znuny closed ticket status panel',
            ],
            'show_scheduled_tasks_status_panel' => [
                'label' => 'Show Scheduled Tasks status panel',
            ],
        ],
        'notifications' => [
            'saved' => [
                'title' => 'Settings saved successfully',
            ],
        ],
        'ui_locale' => [
            'label' => 'Interface language',
            'helper_text' => 'Choose a personal interface language or use the system default.',
            'system_default' => 'Use system default',
        ],
        'actions' => [
            'save' => 'Save settings',
        ],
    ],
];
