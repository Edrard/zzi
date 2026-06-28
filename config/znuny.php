<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue Cache Time-To-Live
    |--------------------------------------------------------------------------
    |
    | The number of minutes that the Znuny queue list should be cached.
    | A short TTL (like 15) is recommended to keep options fresh while
    | avoiding excessive live API calls during ticket creation.
    |
    */

    'queue_cache_ttl_minutes' => (int) env('ZNUNY_QUEUE_CACHE_TTL_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Closed Ticket Cache Status Panel
    |--------------------------------------------------------------------------
    |
    | Whether the "Recent Closed Ticket Cache Status" panel is visible on
    | the ticket workspace. Usually only enabled for debugging purposes.
    |
    */

    'closed_ticket_status_panel_enabled' => env('ZNUNY_CLOSED_TICKET_STATUS_PANEL_ENABLED', false),

];
