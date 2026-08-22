<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Znuny Inline Image Cache Store
    |--------------------------------------------------------------------------
    |
    | This specifies the cache store used for Znuny inline images.
    | It safely falls back to the default environment cache store until
    | the specific ZNUNY_INLINE_IMAGE_CACHE_STORE environment variable is set.
    |
    */

    'inline_image_cache_store' => env('ZNUNY_INLINE_IMAGE_CACHE_STORE', env('CACHE_STORE', 'redis')),

    'inline_image_warmer_batch_size' => max(1, min(1000, (int) env('ZNUNY_INLINE_IMAGE_WARMER_BATCH_SIZE', 50))),

    'inline_image_warmer_hot_percentage' => max(1, min(100, (int) env('ZNUNY_INLINE_IMAGE_WARMER_HOT_PERCENTAGE', 10))),

];
