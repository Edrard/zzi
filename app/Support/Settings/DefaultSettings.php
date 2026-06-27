<?php

namespace App\Support\Settings;

class DefaultSettings
{
    public static function all(): array
    {
        return [
            // General
            ['key' => 'pagination_per_page_base', 'value' => '100', 'type' => 'integer', 'description' => 'Base page size used across paginated tables. Available page sizes are generated as N/2 rounded up to the nearest multiple of 5, N, 2N, and 3N.'],
            ['key' => 'cleanup_enabled', 'value' => 'true', 'type' => 'boolean', 'description' => 'Enable automated cleanup'],
            ['key' => 'cleanup_batch_size', 'value' => '1000', 'type' => 'integer', 'description' => 'Records to delete per cleanup batch'],
            ['key' => 'app_display_timezone', 'value' => 'UTC', 'type' => 'string', 'description' => 'Timezone used to display dates and times in the admin interface.'],

            // Retention
            ['key' => 'retention_resolved_days', 'value' => '90', 'type' => 'integer', 'description' => 'Days to keep resolved problems'],
            ['key' => 'retention_closed_tickets_days', 'value' => '180', 'type' => 'integer', 'description' => 'Days to keep closed tickets'],
            ['key' => 'retention_action_logs_days', 'value' => '365', 'type' => 'integer', 'description' => 'Days to keep action logs'],
            ['key' => 'retention_failed_jobs_days', 'value' => '30', 'type' => 'integer', 'description' => 'Days to keep failed jobs'],
            ['key' => 'retention_statistics_days', 'value' => '730', 'type' => 'integer', 'description' => 'Days to keep daily statistics'],

            // Zabbix
            ['key' => 'zabbix_api_url', 'value' => '', 'type' => 'string', 'description' => 'Zabbix API endpoint URL'],
            ['key' => 'zabbix_api_token', 'value' => '', 'type' => 'string', 'description' => 'Zabbix API token'],
            ['key' => 'zabbix_api_timeout', 'value' => '15', 'type' => 'integer', 'description' => 'Zabbix API request timeout in seconds'],
            ['key' => 'zabbix_api_verify_ssl', 'value' => 'true', 'type' => 'boolean', 'description' => 'Verify Zabbix API SSL certificate'],
            ['key' => 'zabbix_poll_interval_minutes', 'value' => '1', 'type' => 'integer', 'description' => 'Zabbix polling interval in minutes'],
            ['key' => 'zabbix_problem_cache_ttl_minutes', 'value' => '3', 'type' => 'integer', 'description' => 'Redis TTL for cached Zabbix problems in minutes'],
            ['key' => 'zabbix_problem_limit', 'value' => '100', 'type' => 'integer', 'description' => 'Maximum number of Zabbix problems to fetch per poll'],
            ['key' => 'zabbix_exclude_suppressed_problems', 'value' => 'true', 'type' => 'boolean', 'description' => 'Exclude suppressed Zabbix problems from polling results'],
            ['key' => 'zabbix_problem_url_template', 'value' => '', 'type' => 'string', 'description' => 'Zabbix Problem URL Template'],
            ['key' => 'zabbix_problem_sync_audit_enabled', 'value' => 'false', 'type' => 'boolean', 'description' => 'Write summary audit records for scheduled Zabbix problem polling.'],

            // Znuny Endpoints & Connection
            ['key' => 'znuny_api_url', 'value' => '', 'type' => 'string', 'description' => 'Znuny GenericTicketConnectorREST base URL'],
            ['key' => 'znuny_web_url', 'value' => '', 'type' => 'string', 'description' => 'Znuny agent web interface URL'],
            ['key' => 'znuny_ticket_url_template', 'value' => '', 'type' => 'string', 'description' => 'Znuny agent ticket URL template'],
            ['key' => 'znuny_username', 'value' => '', 'type' => 'string', 'description' => 'Znuny integration agent login'],
            ['key' => 'znuny_password', 'value' => '', 'type' => 'string', 'description' => 'Znuny integration agent password'],
            ['key' => 'znuny_api_timeout', 'value' => '15', 'type' => 'integer', 'description' => 'Znuny API request timeout in seconds'],
            ['key' => 'znuny_api_verify_ssl', 'value' => 'true', 'type' => 'boolean', 'description' => 'Verify Znuny API SSL certificate'],

            // Znuny Defaults & Automation
            ['key' => 'znuny_queue_from_host_regex', 'value' => '^(?<queue>[A-Za-z0-9]+)', 'type' => 'string', 'description' => 'Extracts the primary queue/customer prefix from the Zabbix host name.'],
            ['key' => 'znuny_customer_user_from_queue_template', 'value' => '<queue>Clients', 'type' => 'string', 'description' => 'Generates the default Znuny CustomerUser login from the detected Queue.'],
            ['key' => 'znuny_queue_host_mappings', 'value' => '[]', 'type' => 'json', 'description' => 'Manual queue host mappings.'],
            ['key' => 'znuny_manual_ticket_footer', 'value' => 'Created manually by Zabbix Znuny Integration.', 'type' => 'string', 'description' => 'Optional text appended to manually created Znuny tickets. Leave empty to disable.'],
            ['key' => 'znuny_default_agent_id', 'value' => '', 'type' => 'string', 'description' => 'Znuny default agent ID'],
            ['key' => 'znuny_default_agent_login', 'value' => '', 'type' => 'string', 'description' => 'Znuny default agent login'],
            ['key' => 'znuny_default_agent_name', 'value' => '', 'type' => 'string', 'description' => 'Znuny default agent name'],
            ['key' => 'znuny_agent_exclude_logins', 'value' => '', 'type' => 'string', 'description' => 'Znuny agent logins to exclude'],
            ['key' => 'linked_ticket_manual_close_default_reason', 'value' => 'Manual close from Linked Tickets UI.', 'type' => 'string', 'description' => 'Default reason for closing linked tickets manually.'],
            ['key' => 'manual_ticket_reopen_note_template', 'value' => 'Reopening this ticket because the linked Zabbix problem became active again within the configured reopen window.', 'type' => 'string', 'description' => 'Template for manual ticket reopen notes.'],

            // Automation / Workflow
            ['key' => 'default_close_delay_hours', 'value' => '4', 'type' => 'integer', 'description' => 'Hours before auto-closing tickets'],
            ['key' => 'default_reopen_window_hours', 'value' => '24', 'type' => 'integer', 'description' => 'Hours window to reopen tickets'],
            ['key' => 'manual_ticket_auto_close_enabled', 'value' => 'true', 'type' => 'boolean', 'description' => 'Automatically close manually created linked tickets after the Zabbix problem stays resolved long enough.'],
            ['key' => 'manual_ticket_auto_close_schedule_mode', 'value' => 'disabled', 'type' => 'string', 'description' => 'Scheduler mode for manual ticket auto-closing (disabled, dry_run, execute).'],
            ['key' => 'manual_ticket_flap_threshold', 'value' => '3', 'type' => 'integer', 'description' => 'Number of repeated active/resolved cycles before a linked problem is considered flapping. 0 disables flapping detection.'],
            ['key' => 'manual_ticket_extra_flapping_delay_hours', 'value' => '4', 'type' => 'integer', 'description' => 'Additional close delay added after flapping is detected for a linked manual ticket.'],

            // Caching
            ['key' => 'znuny_queue_cache_ttl_minutes', 'value' => '15', 'type' => 'integer', 'description' => 'How long Znuny queue lists are cached. 0 disables this cache.'],
            ['key' => 'znuny_agent_cache_ttl_minutes', 'value' => '15', 'type' => 'integer', 'description' => 'How long Znuny agent lists are cached. 0 disables this cache.'],
            ['key' => 'znuny_ticket_snapshot_cache_ttl_minutes', 'value' => '5', 'type' => 'integer', 'description' => 'How long linked ticket snapshots may be cached. 0 disables this cache.'],

            // Sync
            ['key' => 'znuny_linked_ticket_sync_interval_minutes', 'value' => '5', 'type' => 'integer', 'description' => 'How often linked Znuny tickets should be synchronized by the scheduler.'],
            ['key' => 'znuny_linked_ticket_sync_batch_size', 'value' => '50', 'type' => 'integer', 'description' => 'Maximum linked tickets to synchronize per run. 0 means all eligible tickets.'],
            ['key' => 'znuny_detailed_sync_audit_enabled', 'value' => 'false', 'type' => 'boolean', 'description' => 'When enabled, detailed ticket sync events are written to the audit log. Keep disabled unless debugging.'],

            // Ticket Workspace
            ['key' => 'znuny_ticket_workspace_enabled', 'value' => 'true', 'type' => 'boolean', 'description' => 'Enable Redis-backed Ticket Workspace.'],
            ['key' => 'znuny_ticket_cache_refresh_interval_minutes', 'value' => '5', 'type' => 'integer', 'description' => 'Interval for the Ticket Workspace cache warmer in minutes.'],
            ['key' => 'znuny_ticket_cache_max_pages_per_run', 'value' => '3', 'type' => 'integer', 'description' => 'Safety limit for paginated ZnunyTicketSearch cache warming.'],
            ['key' => 'znuny_ticket_cache_ttl_minutes', 'value' => '15', 'type' => 'integer', 'description' => 'Default TTL for cached active Znuny tickets in minutes.'],
            ['key' => 'znuny_ticket_cache_closed_ttl_minutes', 'value' => '1440', 'type' => 'integer', 'description' => 'TTL for recently closed Znuny tickets in minutes.'],
            ['key' => 'znuny_ticket_cache_default_limit', 'value' => '50', 'type' => 'integer', 'description' => 'Default page size for Znuny ticket cache warming/search.'],
            ['key' => 'znuny_ticket_workspace_default_per_page', 'value' => '50', 'type' => 'integer', 'description' => 'Default per page value for Ticket Workspace UI.'],
            ['key' => 'znuny_ticket_workspace_active_state_type_ids', 'value' => '["new","open","pending_reminder","pending_auto"]', 'type' => 'json', 'description' => 'JSON array of active operational state type IDs.'],
            ['key' => 'znuny_ticket_workspace_sync_audit_enabled', 'value' => 'false', 'type' => 'boolean', 'description' => 'Write summary audit records for scheduled Ticket Workspace cache warming.'],
        ];
    }
}
