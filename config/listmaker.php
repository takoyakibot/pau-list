<?php

return [

    'retention_days' => (int) env('DATA_RETENTION_DAYS', 30),

    'captcha_enabled' => (bool) env('CAPTCHA_ENABLED', false),

    'contact_to_address' => env('CONTACT_TO_ADDRESS'),

];
