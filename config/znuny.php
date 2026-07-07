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

];
