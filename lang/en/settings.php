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
    'settings_page' => [
        'tabs' => [
            'general' => 'General',
            'scheduler' => 'Scheduler',
            'retention' => 'Retention',
            'audit_log' => 'Audit Log',
            'cache' => 'Cache',
            'zabbix' => 'Zabbix',
            'znuny' => 'Znuny',
            'znuny_ticket_defaults' => 'Znuny Ticket Defaults',
            'automation' => 'Automation',
            'statistics' => 'Statistics',
            'other' => 'Other',
            'main' => 'Main',
            'mail' => 'Mail',
            'connection' => 'Connection',
            'problem_handling_and_ui' => 'Problem Handling & UI',
            'problem_highlighting' => 'Problem Highlighting',
            'credentials' => 'Credentials',
            'endpoints_and_connection' => 'Endpoints & Connection',
            'excludes' => 'Excludes',
            'linked_tickets' => 'Linked Tickets',
            'ticket_workspace' => 'Ticket Workspace',
            'queue_host_prefix_mappings' => 'Queue Host Prefix Mappings',
            'ticket_default_rules' => 'Ticket Default Rules',
            'advanced_ticket_preset' => 'Advanced Ticket Preset',
        ],
        'sections' => [
            'statistics' => [
                'heading' => 'Statistics',
                'description' => 'Configure how owner statistics are collected and retained.',
            ],
            'audit_logging' => [
                'heading' => 'Audit Logging',
                'description' => 'Configure which synchronization and Ticket Workspace operations are recorded in the application audit log.',
            ],
            'ticket_automation' => [
                'heading' => 'Ticket Automation',
                'description' => 'Configure automatic closing, reopening, scheduling, and flapping behavior for manually created Znuny tickets.',
            ],
            'sendmail_configuration' => [
                'heading' => 'Sendmail Configuration',
            ],
            'smtp_configuration' => [
                'heading' => 'SMTP Configuration',
            ],
            'additional_mail_settings' => [
                'heading' => 'Additional Mail Settings',
            ],
            'scheduler_control' => [
                'heading' => 'Scheduler Control',
                'description' => 'Enable or disable processing of scheduled Znuny tasks.',
            ],
            'execution_limits' => [
                'heading' => 'Execution Limits',
                'description' => 'Control how much work one scheduler command may perform.',
            ],
            'recovery_and_catch_up' => [
                'heading' => 'Recovery and Catch-up',
                'description' => 'Configure temporary pauses and processing of missed scheduled runs.',
            ],
            'failure_protection' => [
                'heading' => 'Failure Protection',
                'description' => 'Automatically stop scheduler processing when repeated failures require administrator attention.',
            ],
            'additional_scheduler_settings' => [
                'heading' => 'Additional Scheduler Settings',
            ],
            'application_display' => [
                'heading' => 'Application Display',
                'description' => 'Configure how dates, times, and table page sizes are presented in the administration interface.',
            ],
            'additional_application_settings' => [
                'heading' => 'Additional Application Settings',
            ],
            'cleanup_control' => [
                'heading' => 'Cleanup Control',
                'description' => 'Controls how long this integration keeps local operational records and how scheduled cleanup removes records that exceed the retention periods configured below. These settings affect only local integration data and do not delete data from Zabbix or Znuny.',
            ],
            'integration_history' => [
                'heading' => 'Integration History',
                'description' => 'Configure how long local history linking Zabbix problems and Znuny tickets remains available in this integration.',
            ],
            'logs_and_processing_records' => [
                'heading' => 'Logs and Processing Records',
                'description' => 'Configure how long local operational logs and failed-processing records remain available for auditing and troubleshooting.',
            ],
            'additional_retention_settings' => [
                'heading' => 'Additional Retention Settings',
            ],
            'znuny_reference_data' => [
                'heading' => 'Znuny Reference Data',
                'description' => 'Configure how long reusable Znuny agent, queue, and lookup reference data may be kept before the application requests updated data from Znuny. Shorter values provide fresher reference data but may increase API requests.',
            ],
            'znuny_linked_ticket_data' => [
                'heading' => 'Znuny Linked Ticket Data',
                'description' => 'Configure caching for Znuny ticket articles and locally stored linked-ticket snapshots. These settings affect read performance and freshness only; they do not delete articles, ticket links, or data in Znuny.',
            ],
            'additional_cache_settings' => [
                'heading' => 'Additional Cache Settings',
                'description' => 'Additional cache-related settings that are not yet assigned to a dedicated Cache section.',
            ],
            'runtime_cache_maintenance' => [
                'heading' => 'Runtime Cache Maintenance',
                'description' => 'Clear individual application runtime caches without changing saved settings or clearing unrelated cache scopes.',
            ],
            'ticket_workspace' => [
                'heading' => 'Ticket Workspace',
                'description' => 'Controls the Redis-backed Ticket Workspace subsystem, including active and closed ticket synchronization, manual refresh operations, and cached ticket access.',
            ],
            'active_ticket_cache' => [
                'heading' => 'Active Ticket Cache',
                'description' => 'Controls how active Znuny tickets are fetched and retained in Redis. Shorter refresh intervals and larger API batches provide fresher data but increase Znuny API and processing load.',
            ],
            'recent_closed_tickets' => [
                'heading' => 'Recent Closed Tickets',
                'description' => 'Controls how closed tickets are synchronized and retained for Ticket Workspace. Eligibility is based on the ticket creation time, not the actual close or last-modified time, so later edits do not cause very old closed tickets to appear as recent.',
            ],
            'queue_host_prefix_mappings' => [
                'heading' => 'Queue Host Prefix Mappings',
                'description' => 'Fallback Queue mapping for standardized Zabbix host prefixes. CustomerUser is still generated from the original host prefix.',
            ],
            'ticket_default_rules' => [
                'heading' => 'Ticket Default Rules',
            ],
            'advanced_ticket_preset' => [
                'heading' => 'Advanced Ticket Preset',
            ],
        ],
        'actions' => [
            'save' => 'Save settings',
        ],
    ],
];
