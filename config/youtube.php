<?php

return [

    'api_key' => env('YOUTUBE_API_KEY'),

    'quota' => [
        'write_limit' => (int) env('QUOTA_WRITE_LIMIT', 5000),
        'read_budget' => (int) env('QUOTA_READ_BUDGET', 2000),
        'google_daily' => (int) env('QUOTA_GOOGLE_DAILY', 10000),
    ],

    'channel' => [
        'fetch_max_videos' => (int) env('CHANNEL_FETCH_MAX_VIDEOS', 1000),
        'cache_reuse_minutes' => (int) env('CHANNEL_CACHE_REUSE_MINUTES', 360),
        'sync_known_run' => (int) env('CHANNEL_SYNC_KNOWN_RUN', 3),
    ],

];
