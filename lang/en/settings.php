<?php

return [
    'ticket_template_presets' => [
        'defaults' => [
            'znuny_manual_ticket_footer' => 'Created manually by Zabbix Znuny Integration.',
            'linked_ticket_manual_close_default_reason' => 'Manual close from Linked Tickets UI.',
            'manual_ticket_reopen_note_template' => 'Reopening this ticket because the linked Zabbix problem became active again within the configured reopen window.',
        ],
        'action' => [
            'label' => 'Load default templates',
            'modal_heading' => 'Load default templates?',
            'modal_description' => 'The current ticket-template texts will be replaced with the defaults for the active interface language and saved immediately.',
            'confirm' => 'Load and save',
        ],
        'notifications' => [
            'saved_title' => 'Default templates saved',
            'saved_body' => 'Ticket templates for :language have been loaded and saved.',
        ],
    ],
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
            'track_new_tickets' => [
                'label' => 'Track new tickets',
                'helper_text' => 'Show a star next to new unlinked tickets you have not opened yet.',
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
        'fields' => [
            'mail_smtp_password' => [
                'placeholder' => 'Leave empty to keep current password',
            ],
            'mail_smtp_password_clear' => [
                'label' => 'Clear Stored SMTP Password',
            ],
            'znuny_password' => [
                'placeholder' => 'Leave empty to keep current password',
            ],
            'zabbix_api_token' => [
                'placeholder' => 'Leave empty to keep current password',
            ],

            'znuny_agent_exclude_logins' => [
                'description' => 'Znuny agent logins that must not be selectable as ticket owners in the manual ticket creation modal. Put one login per line.',
            ],
            'app_display_timezone' => [
                'description' => 'Timezone used to display dates and times in the admin interface. Backend timestamps and scheduler logic remain unchanged.',
            ],

            'znuny_global_queue_exclusion_regexes' => [
                'add_action_label' => 'Add regex pattern',
                'helper_text' => 'Enter regex patterns without delimiters or modifiers. Matching is case-insensitive and UTF-8 aware by default, and is checked against queue Name and FullName. Blank rows are ignored. Invalid regex patterns are ignored and logged.',
                'columns' => [
                    'regex_pattern' => 'Regex pattern',
                ],
                'placeholders' => [
                    'regex_pattern' => '^Postmaster::',
                ],
                'examples' => "Examples:\n^Postmaster:: hides queues starting with Postmaster::\n^Test hides queues starting with Test\nArchive hides queues containing Archive\n^(Postmaster|Junk):: hides queues starting with Postmaster:: or Junk::",
            ],
            'znuny_queue_from_host_regex' => [
                'label' => 'Queue detection regex from Zabbix host',
                'description' => 'Extracts the primary queue/customer prefix from the Zabbix host name. It must contain the named capture group (?<queue>...). Default takes the first word of the host name. Example: "ExampleCompany swiss test01" → "ExampleCompany".',
            ],
            'znuny_customer_user_from_queue_template' => [
                'label' => 'CustomerUser template from Queue',
                'description' => 'Generates the default Znuny CustomerUser login from the primary prefix extracted from the Zabbix host name. Use <queue> as placeholder. Default: <queue>Clients. Example: primary prefix "ExampleCompany" → "ExampleCompanyClients". This does not use Queue host prefix mappings.',
            ],
            'problem_highlighting_preview' => [
                'label' => 'Highlight Preview',
                'sample' => 'ExampleCompany server01[main]',
            ],
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
            'test_zabbix_api' => [
                'label' => 'Test Zabbix API connection',
                'description' => 'Tests current Zabbix form values without saving settings.',
            ],
            'live_preview' => [
                'label' => 'Live Preview',
            ],
            'test_znuny_api' => [
                'label' => 'Test Znuny API Connection',
                'description' => 'Tests current Znuny form values without saving settings.',
            ],
            'save_queue_mappings' => [
                'label' => 'Save queue mappings',
            ],
            'scan_missing_queue_mappings' => [
                'label' => 'Scan current problems for missing queue mappings',
            ],
            'send_test_email' => [
                'label' => 'Send Test Email',
            ],
            'clear_settings_cache' => [
                'label' => 'Clear Settings Cache',
                'modal_heading' => 'Clear Settings Cache?',
                'modal_description' => 'This clears the cached application settings. Saved settings remain unchanged and will be loaded again when needed.',
                'modal_submit_action_label' => 'Clear Settings Cache',
            ],

            'clear_ticket_article_cache' => [
                'label' => 'Clear Ticket Article Cache',
                'modal_heading' => 'Clear Ticket Article Cache?',
                'modal_description' => 'This invalidates cached Znuny ticket articles used by linked-ticket views. The next article request may contact Znuny again.',
                'modal_submit_action_label' => 'Clear Article Cache',
            ],
        ],
        'queue_mappings' => [
            'heading' => 'Queue host prefix mappings',
            'helper_text' => 'Maps primary Zabbix host prefixes to existing Znuny queues. Used only when the primary queue candidate is not found in Znuny.',
            'columns' => [
                'host_prefix' => 'Host prefix',
                'queue_name' => 'Queue name',
                'note' => 'Note',
            ],
            'fields' => [
                'host_prefix' => [
                    'helper_text' => 'Example: TestCompany',
                ],
                'note' => [
                    'placeholder' => 'Detected from current Zabbix problems',
                    'generated_value' => 'Detected from current Zabbix problems',
                ],
            ],
            'actions' => [
                'save_mappings' => [
                    'label' => 'Save queue mappings',
                ],
                'scan_missing' => [
                    'label' => 'Scan current problems for missing queue mappings',
                ],
            ],
            'notifications' => [
                'saved_successfully' => 'Queue mappings saved successfully.',
                'scan_complete' => [
                    'title' => 'Scan Complete',
                    'body' => "Scanned :scanned problems (:unique_prefixes unique prefixes).\nAdded :added draft mappings.\nSkipped :skipped_existing_queue existing queues.\nSkipped :skipped_existing_mapping existing mappings.\nFailed API checks: :failed_api.",
                ],
            ],
            'errors' => [
                'only_admins' => 'Only admins can modify settings.',
            ],
        ],
        'notifications' => [
            'settings_saved' => [
                'title' => 'Settings saved successfully',
            ],
            'test_email_failed' => [
                'title' => 'Test Email Failed',
                'errors_heading' => 'Errors:',
            ],
            'test_email_sent' => [
                'title' => 'Test Email Sent',
                'body' => 'Check the configured admin recipients for the test email.',
            ],
            'znuny_connection_failed' => [
                'title' => 'Znuny API Connection Failed',
                'errors_heading' => 'Errors:',
            ],
            'znuny_connection_successful' => [
                'title' => 'Znuny API Connection Successful',
                'checks_heading' => 'Checks:',
                'counts_heading' => 'Counts:',
                'warnings_heading' => 'Warnings:',
                'errors_heading' => 'Errors:',
            ],
            'znuny_connection_partial' => [
                'title' => 'Znuny API Connection Partial Success',
            ],
            'zabbix_connection_failed' => [
                'title' => 'Zabbix API Connection Failed',
                'errors_heading' => 'Errors:',
            ],
            'zabbix_connection_successful' => [
                'title' => 'Zabbix API Connection Successful',
                'body' => 'Connected successfully. API Version: :version',
            ],
            'cache_clearing_failed' => [
                'title' => 'Cache clearing failed',
                'body_settings' => 'The Settings cache could not be cleared. Review the application logs for details.',
                'body_agent' => 'The Znuny Agent cache could not be cleared. Review the application logs for details.',
                'body_queue' => 'The Znuny Queue cache could not be cleared. Review the application logs for details.',
                'body_lookup' => 'The Znuny Lookup cache could not be cleared. Review the application logs for details.',
                'body_article' => 'The Ticket Article cache could not be cleared. Review the application logs for details.',
            ],
            'cache_clearing_successful' => [
                'title_settings' => 'Settings cache cleared',
                'body_settings' => 'Cached application settings were cleared successfully.',
                'title_agent' => 'Znuny agent cache cleared',
                'body_agent' => 'Cached Znuny agent data was cleared successfully.',
                'title_queue' => 'Znuny queue cache cleared',
                'body_queue' => 'Cached Znuny queue data was cleared successfully.',
                'title_lookup' => 'Znuny lookup cache cleared',
                'body_lookup' => 'Cached reusable Znuny lookup data was cleared successfully.',
                'title_article' => 'Ticket article cache cleared',
                'body_article' => 'Cached Znuny ticket articles were cleared successfully.',
            ],
        ],
    ],
    'metadata' => [
        'pagination_per_page_base' => [
            'label' => 'Base Rows per Page',
            'description' => 'Base number of rows used by paginated tables. Available page-size choices are generated as half of this value rounded up to the nearest multiple of 5, the base value, double the value, and triple the value. For example, 100 produces 50, 100, 200, and 300.',
        ],
        'cleanup_enabled' => [
            'label' => 'Automatic Local Data Cleanup',
            'description' => 'Enable scheduled cleanup of old local integration records. Disabling this option preserves all retention settings but prevents automatic deletion. This does not delete active Zabbix problems or Znuny tickets.',
        ],
        'cleanup_batch_size' => [
            'label' => 'Records per Cleanup Batch',
            'description' => 'Maximum number of records removed from each cleanup category during one cleanup pass. Lower values reduce database load; higher values clear accumulated old data faster.',
        ],
        'app_display_timezone' => [
            'label' => 'Display Time Zone',
            'description' => 'Time zone used only for dates and times shown in the administration interface. Stored timestamps, background processing, and scheduler timing are not changed.',
        ],
        'ui_locale' => [
            'label' => 'Ui Locale',
            'description' => 'Default interface language used for unauthenticated pages and users without a personal override.',
        ],
        'retention_resolved_days' => [
            'label' => 'Resolved Problem History (days)',
            'description' => 'Number of days to keep local history for Zabbix problems after they become resolved. This does not delete problems, events, or history from Zabbix.',
        ],
        'retention_closed_tickets_days' => [
            'label' => 'Closed Ticket Link History (days)',
            'description' => 'Number of days to keep local integration records and links for closed tickets. This does not delete tickets, articles, or history from Znuny.',
        ],
        'retention_action_logs_days' => [
            'label' => 'Action Log Retention (days)',
            'description' => 'Number of days to keep local application action-log records used for operational history, auditing, and troubleshooting.',
        ],
        'retention_failed_jobs_days' => [
            'label' => 'Failed Job Retention (days)',
            'description' => 'Number of days to keep failed background-job records for diagnostics and troubleshooting.',
        ],
        'scheduled_task_logs_retention_days' => [
            'label' => 'Scheduled Task Run Log Retention (days)',
            'description' => 'Number of days to keep execution logs for scheduled Znuny task runs. Scheduled task definitions and pending scheduled work are not deleted by this retention setting.',
        ],
        'scheduled_tasks_missed_run_max_age_days' => [
            'label' => 'Missed Run Catch-up Window (days)',
            'description' => 'Maximum age of a missed scheduled run that may still be executed by the catch-up process.',
        ],
        'scheduled_tasks_enabled' => [
            'label' => 'Scheduler Enabled',
            'description' => 'Global switch for scheduled Znuny task processing.',
        ],
        'scheduled_tasks_max_processed_per_run' => [
            'label' => 'Maximum Tasks per Run',
            'description' => 'Maximum number of scheduled tasks processed sequentially during one command run.',
        ],
        'scheduled_tasks_command_runtime_seconds' => [
            'label' => 'Command Runtime Limit (seconds)',
            'description' => 'Maximum time the scheduler processing command may run before it stops accepting more work.',
        ],
        'scheduled_tasks_pause_minutes' => [
            'label' => 'Pause After Transient Error (minutes)',
            'description' => 'How long scheduler processing pauses after a transient connection or service error.',
        ],
        'scheduled_tasks_paused_until' => [
            'label' => 'Scheduled Tasks Paused Until',
            'description' => 'Timestamp until which the scheduler is paused',
        ],
        'scheduled_tasks_pause_reason' => [
            'label' => 'Scheduled Tasks Pause Reason',
            'description' => 'Reason for the current pause',
        ],
        'scheduled_tasks_disabled_reason' => [
            'label' => 'Scheduled Tasks Disabled Reason',
            'description' => 'Reason for the scheduler being globally disabled automatically',
        ],
        'scheduled_tasks_auto_disable_on_failures' => [
            'label' => 'Auto-disable After Repeated Failures',
            'description' => 'Disable scheduler processing automatically after the configured number of consecutive failures.',
        ],
        'scheduled_tasks_failure_threshold' => [
            'label' => 'Consecutive Failure Threshold',
            'description' => 'Number of consecutive failures that triggers automatic scheduler disablement.',
        ],
        'mail_notifications_enabled' => [
            'label' => 'Mail Notifications Enabled',
            'description' => 'Enable or disable outgoing mail notifications',
        ],
        'mail_transport' => [
            'label' => 'Mail Transport',
            'description' => 'Select the mail transport method.',
            'options' => [
                'sendmail' => 'Server Sendmail',
                'smtp' => 'External SMTP Server',
            ],
        ],
        'mail_from_address' => [
            'label' => 'Mail From Address',
            'description' => 'Global FROM address for outgoing mails',
        ],
        'mail_from_name' => [
            'label' => 'Mail From Name',
            'description' => 'Global FROM name for outgoing mails',
        ],
        'mail_admin_recipients' => [
            'label' => 'Mail Admin Recipients',
            'description' => 'Comma-separated list of admin email addresses to receive system alerts',
        ],
        'mail_sendmail_path' => [
            'label' => 'Mail Sendmail Path',
            'description' => 'Path to the sendmail binary',
        ],
        'mail_smtp_host' => [
            'label' => 'Mail Smtp Host',
            'description' => 'SMTP host address',
        ],
        'mail_smtp_port' => [
            'label' => 'Mail Smtp Port',
            'description' => 'SMTP port',
        ],
        'mail_smtp_encryption' => [
            'label' => 'Mail Smtp Encryption',
            'description' => 'SMTP encryption (none, tls, ssl)',
        ],
        'mail_smtp_username' => [
            'label' => 'Mail Smtp Username',
            'description' => 'SMTP username',
        ],
        'mail_smtp_password' => [
            'label' => 'Mail Smtp Password',
            'description' => 'SMTP password',
        ],
        'mail_smtp_timeout_seconds' => [
            'label' => 'Mail Smtp Timeout Seconds',
            'description' => 'SMTP timeout in seconds',
        ],

        'zabbix_api_url' => [
            'label' => 'Zabbix Api Url',
            'description' => 'Zabbix API endpoint URL',
        ],
        'zabbix_api_token' => [
            'label' => 'Zabbix Api Token',
            'description' => 'Zabbix API token',
        ],
        'zabbix_api_timeout' => [
            'label' => 'Zabbix Api Timeout',
            'description' => 'Zabbix API request timeout in seconds',
        ],
        'zabbix_api_verify_ssl' => [
            'label' => 'Zabbix Api Verify Ssl',
            'description' => 'Verify Zabbix API SSL certificate',
        ],
        'zabbix_poll_interval_minutes' => [
            'label' => 'Zabbix Poll Interval Minutes',
            'description' => 'Zabbix polling interval in minutes',
        ],
        'zabbix_problem_cache_ttl_minutes' => [
            'label' => 'Zabbix Problem Cache Ttl Minutes',
            'description' => 'Redis TTL for cached Zabbix problems in minutes',
        ],
        'zabbix_problem_limit' => [
            'label' => 'Zabbix Problem Limit',
            'description' => 'Maximum number of Zabbix problems to fetch per poll',
        ],
        'zabbix_exclude_suppressed_problems' => [
            'label' => 'Zabbix Exclude Suppressed Problems',
            'description' => 'Exclude suppressed Zabbix problems from polling results',
        ],
        'zabbix_problem_url_template' => [
            'label' => 'Zabbix Problem Url Template',
            'description' => 'Used to generate direct links to Zabbix problems in the ticket creation modal and related views. The exact supported placeholder token is {trigger_id}. Example: https://zabbix.example.com/tr_events.php?triggerid={trigger_id} The placeholder is replaced at runtime.',
        ],
        'zabbix_problem_sync_audit_enabled' => [
            'label' => 'Zabbix Problem Sync Audit Enabled',
            'description' => 'Write summary audit records for scheduled Zabbix problem polling.',
        ],
        'zabbix_attention_highlighting_enabled' => [
            'label' => 'Zabbix Attention Highlighting Enabled',
            'description' => 'Enable highlighting of Zabbix problems matching Attention Filters.',
        ],
        'zabbix_attention_highlight_text_color' => [
            'label' => 'Zabbix Attention Highlight Text Color',
            'description' => 'Text color for highlighted problems.',
            'options' => [
                'custom_hex' => 'Custom HEX',
                'aquamarine' => 'Aquamarine',
                'white' => 'White',
                'gray' => 'Gray',
                'red' => 'Red',
                'orange' => 'Orange',
                'amber' => 'Amber',
                'yellow' => 'Yellow',
                'lime' => 'Lime',
                'green' => 'Green',
                'emerald' => 'Emerald',
                'cyan' => 'Cyan',
                'sky' => 'Sky',
                'blue' => 'Blue',
                'violet' => 'Violet',
                'pink' => 'Pink',
            ],
        ],
        'zabbix_attention_highlight_text_custom_hex' => [
            'label' => 'Zabbix Attention Highlight Text Custom Hex',
            'description' => 'Custom HEX text color.',
        ],
        'zabbix_attention_highlight_underline_style' => [
            'label' => 'Zabbix Attention Highlight Underline Style',
            'description' => 'Underline style for highlighted problems.',
            'options' => [
                'disabled' => 'Disabled',
                'solid' => 'Solid',
                'dashed' => 'Dashed',
                'dotted' => 'Dotted',
                'double' => 'Double',
                'wavy' => 'Wavy',
            ],
        ],
        'zabbix_attention_highlight_underline_color' => [
            'label' => 'Zabbix Attention Highlight Underline Color',
            'description' => 'Underline color for highlighted problems.',
            'options' => [
                'custom_hex' => 'Custom HEX',
                'aquamarine' => 'Aquamarine',
                'white' => 'White',
                'gray' => 'Gray',
                'red' => 'Red',
                'orange' => 'Orange',
                'amber' => 'Amber',
                'yellow' => 'Yellow',
                'lime' => 'Lime',
                'green' => 'Green',
                'emerald' => 'Emerald',
                'cyan' => 'Cyan',
                'sky' => 'Sky',
                'blue' => 'Blue',
                'violet' => 'Violet',
                'pink' => 'Pink',
            ],
        ],
        'zabbix_attention_highlight_underline_custom_hex' => [
            'label' => 'Zabbix Attention Highlight Underline Custom Hex',
            'description' => 'Custom HEX underline color.',
        ],
        'zabbix_attention_highlight_underline_thickness' => [
            'label' => 'Zabbix Attention Highlight Underline Thickness',
            'description' => 'Underline thickness for highlighted problems.',
            'options' => [
                '1px' => '1px',
                '2px' => '2px',
                '3px' => '3px',
            ],
        ],
        'znuny_api_url' => [
            'label' => 'Znuny Api Url',
            'description' => 'Znuny GenericTicketConnectorREST base URL',
        ],
        'znuny_web_url' => [
            'label' => 'Znuny Web Url',
            'description' => 'Znuny agent web interface URL',
        ],
        'znuny_ticket_url_template' => [
            'label' => 'Znuny Ticket Url Template',
            'description' => 'Used to generate direct links to Znuny tickets in the UI. The exact supported placeholder token is {ticket_id}. Example: https://znuny.example.com/index.pl?Action=AgentTicketZoom;TicketID={ticket_id} The placeholder is replaced at runtime.',
        ],
        'znuny_username' => [
            'label' => 'Znuny Username',
            'description' => 'Znuny integration agent login',
        ],
        'znuny_password' => [
            'label' => 'Znuny Password',
            'description' => 'Znuny integration agent password',
        ],
        'znuny_api_timeout' => [
            'label' => 'Znuny Api Timeout',
            'description' => 'Znuny API request timeout in seconds',
        ],
        'znuny_api_verify_ssl' => [
            'label' => 'Znuny Api Verify Ssl',
            'description' => 'Verify Znuny API SSL certificate',
        ],
        'znuny_global_queue_exclusion_regexes' => [
            'label' => 'Znuny Global Queue Exclusion Regexes',
            'description' => 'JSON array of regex patterns. Queues matching any pattern will be excluded from selection dropdowns globally.',
        ],
        'znuny_queue_from_host_regex' => [
            'label' => 'Znuny Queue From Host Regex',
            'description' => 'Extracts the primary queue/customer prefix from the Zabbix host name.',
        ],
        'znuny_customer_user_from_queue_template' => [
            'label' => 'Znuny Customer User From Queue Template',
            'description' => 'Generates the default Znuny CustomerUser login from the detected Queue.',
        ],
        'znuny_queue_host_mappings' => [
            'label' => 'Znuny Queue Host Mappings',
            'description' => 'Manual queue host mappings.',
        ],
        'znuny_manual_ticket_footer' => [
            'label' => 'Znuny Manual Ticket Footer',
            'description' => 'Optional text appended to manually created Znuny tickets. Leave empty to disable.',
        ],
        'znuny_agent_exclude_logins' => [
            'label' => 'Znuny Agent Exclude Logins',
            'description' => 'Znuny agent logins to exclude',
        ],
        'linked_ticket_manual_close_default_reason' => [
            'label' => 'Linked Ticket Manual Close Default Reason',
            'description' => 'Default reason for closing linked tickets manually.',
        ],
        'manual_ticket_reopen_note_template' => [
            'label' => 'Manual Ticket Reopen Note Template',
            'description' => 'Template for manual ticket reopen notes.',
        ],
        'znuny_ticket_default_priority' => [
            'label' => 'Znuny Ticket Default Priority',
            'description' => 'Default Znuny ticket priority used by Create Ticket and Current Problems ticket creation.',
        ],
        'znuny_ticket_default_state' => [
            'label' => 'Znuny Ticket Default State',
            'description' => 'Default Znuny ticket state used by Create Ticket and Current Problems ticket creation.',
        ],
        'znuny_ticket_default_lock' => [
            'label' => 'Znuny Ticket Default Lock',
            'description' => 'Default Znuny ticket lock mode used by Create Ticket and Current Problems ticket creation.',
            'options' => [
                'lock' => 'Lock',
                'unlock' => 'Unlock',
            ],
        ],
        'default_close_delay_hours' => [
            'label' => 'Default Close Delay Hours',
            'description' => 'Hours before auto-closing tickets',
        ],
        'default_reopen_window_hours' => [
            'label' => 'Default Reopen Window Hours',
            'description' => 'Hours window to reopen tickets',
        ],
        'manual_ticket_auto_close_enabled' => [
            'label' => 'Manual Ticket Auto Close Enabled',
            'description' => 'Automatically close manually created linked tickets after the Zabbix problem stays resolved long enough.',
        ],
        'manual_ticket_auto_close_schedule_mode' => [
            'label' => 'Manual Ticket Auto Close Schedule Mode',
            'description' => 'disabled: scheduler will not auto-close manual tickets; dry_run: scheduler logs what would be closed without changing Znuny; execute: scheduler closes eligible manual tickets using the verified /TicketClose workflow.',
            'options' => [
                'disabled' => 'Disabled',
                'dry_run' => 'Dry Run',
                'execute' => 'Execute',
            ],
        ],
        'manual_ticket_flap_threshold' => [
            'label' => 'Manual Ticket Flap Threshold',
            'description' => 'Number of repeated active/resolved cycles before a linked problem is considered flapping. 0 disables flapping detection.',
        ],
        'manual_ticket_extra_flapping_delay_hours' => [
            'label' => 'Manual Ticket Extra Flapping Delay Hours',
            'description' => 'Additional close delay added after flapping is detected for a linked manual ticket.',
        ],
        'znuny_ticket_article_cache_ttl_minutes' => [
            'label' => 'Ticket Article Cache Lifetime (minutes)',
            'description' => 'How long Znuny ticket articles fetched for linked tickets may be cached. Set to 0 to bypass persistent ticket article caching.',
        ],
        'znuny_prewarm_queues_interval_minutes' => [
            'label' => 'Queues Prewarm Interval (minutes)',
            'description' => 'Interval in minutes for queues cache prewarm.',
        ],
        'znuny_prewarm_agents_interval_minutes' => [
            'label' => 'Agents Prewarm Interval (minutes)',
            'description' => 'Interval in minutes for agents cache prewarm.',
        ],
        'znuny_prewarm_customer_users_interval_minutes' => [
            'label' => 'Customer Users Prewarm Interval (minutes)',
            'description' => 'Interval in minutes for customer users cache prewarm.',
        ],
        'znuny_prewarm_lookups_interval_minutes' => [
            'label' => 'Lookups Prewarm Interval (minutes)',
            'description' => 'Interval in minutes for lookups cache prewarm.',
        ],

        'znuny_ticket_snapshot_cache_ttl_minutes' => [
            'label' => 'Linked Ticket Snapshot Cache Lifetime (minutes)',
            'description' => 'Configured lifetime for cached linked-ticket snapshot data. A snapshot may include locally stored Znuny ticket details such as state, owner, queue, priority, and synchronization metadata. This setting does not control Ticket Workspace caching and does not delete local ticket links or data in Znuny.',
        ],
        'znuny_linked_ticket_sync_interval_minutes' => [
            'label' => 'Znuny Linked Ticket Sync Interval Minutes',
            'description' => 'How often linked Znuny tickets should be synchronized by the scheduler.',
        ],
        'znuny_linked_ticket_sync_batch_size' => [
            'label' => 'Znuny Linked Ticket Sync Batch Size',
            'description' => 'Maximum linked tickets to synchronize per run. 0 means all eligible tickets.',
        ],
        'znuny_detailed_sync_audit_enabled' => [
            'label' => 'Znuny Detailed Sync Audit Enabled',
            'description' => 'When enabled, detailed ticket sync events are written to the audit log. Keep disabled unless debugging.',
        ],
        'znuny_ticket_workspace_enabled' => [
            'label' => 'Znuny Ticket Workspace Enabled',
            'description' => 'Enable Redis-backed Ticket Workspace.',
        ],
        'znuny_ticket_cache_refresh_interval_minutes' => [
            'label' => 'Znuny Ticket Cache Refresh Interval Minutes',
            'description' => 'Interval for the Ticket Workspace cache warmer in minutes.',
        ],
        'znuny_ticket_cache_max_pages_per_run' => [
            'label' => 'Znuny Ticket Cache Max Pages Per Run',
            'description' => 'Safety limit for paginated ZnunyTicketSearch cache warming.',
        ],
        'znuny_ticket_cache_ttl_minutes' => [
            'label' => 'Znuny Ticket Cache Ttl Minutes',
            'description' => 'Default TTL for cached active Znuny tickets in minutes.',
        ],
        'znuny_ticket_cache_default_limit' => [
            'label' => 'Znuny Ticket Cache Default Limit',
            'description' => 'Default page size for Znuny ticket cache warming/search.',
        ],
        'znuny_ticket_workspace_default_per_page' => [
            'label' => 'Znuny Ticket Workspace Default Per Page',
            'description' => 'Default per page value for Ticket Workspace UI.',
        ],
        'znuny_ticket_workspace_active_state_type_ids' => [
            'label' => 'Znuny Ticket Workspace Active State Type Ids',
            'description' => 'JSON array of active operational state type IDs.',
            'options' => [
                'new' => 'New',
                'open' => 'Open',
                'pending_reminder' => 'Pending reminder',
                'pending_auto' => 'Pending auto',
                'closed' => 'Closed',
                'merged' => 'Merged',
            ],
        ],
        'znuny_ticket_workspace_sync_audit_enabled' => [
            'label' => 'Znuny Ticket Workspace Sync Audit Enabled',
            'description' => 'Write summary audit records for scheduled Ticket Workspace cache warming.',
        ],
        'znuny_closed_ticket_window_days' => [
            'label' => 'Znuny Closed Ticket Window Days',
            'description' => 'Number of recent days to retain in the closed ticket cache.',
        ],
        'znuny_closed_ticket_small_sync_interval_minutes' => [
            'label' => 'Znuny Closed Ticket Small Sync Interval Minutes',
            'description' => 'Interval for small closed ticket sync in minutes.',
        ],
        'znuny_closed_ticket_sync_audit_auto_enabled' => [
            'label' => 'Znuny Closed Ticket Sync Audit Auto Enabled',
            'description' => 'Write summary audit records for automatic closed ticket syncs.',
        ],
        'owner_suggestion_similarity_threshold' => [
            'label' => 'Owner Suggestion Similarity Threshold',
            'description' => 'Minimum similarity percentage used when grouping similar problem names for owner suggestions.',
        ],
        'owner_suggestion_statistics_retention_days' => [
            'label' => 'Owner Suggestion Statistics Retention Days',
            'description' => 'Observations older than this remain stored but receive the old-statistics weight coefficient during aggregation.',
        ],
        'owner_suggestion_old_weight_coefficient' => [
            'label' => 'Owner Suggestion Old Weight Coefficient',
            'description' => 'Coefficient applied to observations older than the statistics retention window.',
        ],
        'owner_suggestion_observation_cleanup_days' => [
            'label' => 'Owner Suggestion Observation Cleanup Days',
            'description' => 'Raw owner suggestion observations older than this are deleted during statistics rebuild.',
        ],
        'owner_suggestion_rebuild_interval_minutes' => [
            'label' => 'Owner Suggestion Rebuild Interval Minutes',
            'description' => 'Minimum interval in minutes between automatic Owner Suggestion statistics rebuilds.',
        ],
    ],
];
