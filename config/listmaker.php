<?php

return [

    'retention_days' => (int) env('DATA_RETENTION_DAYS', 30),

    'captcha_enabled' => (bool) env('CAPTCHA_ENABLED', false),

    'contact_to_address' => env('CONTACT_TO_ADDRESS'),

    // Public text shown on /contact and /privacy as the way to ask for deletion when the form is unavailable
    // (e.g. a public social account or channel). Set on the server only; never a private address in the repo.
    'operator_contact' => env('OPERATOR_CONTACT'),

    // Comma-separated IPs/CIDRs or "*"; empty trusts no proxy (AppServiceProvider::trustProxiesFrom).
    'trusted_proxies' => env('TRUSTED_PROXIES'),

];
