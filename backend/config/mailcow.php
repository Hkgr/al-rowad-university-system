<?php

return [
    'base_url' => env('MAILCOW_BASE_URL', 'https://mail.alrowaduni.edu.sy'),
    'api_key' => env('MAILCOW_API_KEY', ''),
    'student_domain' => env('MAILCOW_STUDENT_DOMAIN', 'alrowaduni.edu.sy'),
    // Mailcow quota input units are MiB (1,048,576 bytes), not decimal MB.
    'student_quota_mb' => (int) env('MAILCOW_STUDENT_QUOTA_MB', 50),
    // Explicit deployment gates. Phase 1 reads remain independent.
    'provisioning_enabled' => (bool) env('MAILCOW_PROVISIONING_ENABLED', false),
    'contract_verified' => (bool) env('MAILCOW_CONTRACT_VERIFIED', false),
    'write_api_key' => env('MAILCOW_WRITE_API_KEY', ''),
    'password_length' => (int) env('MAILCOW_INITIAL_PASSWORD_LENGTH', 24),
    'webmail_url' => 'https://mail.alrowaduni.edu.sy/SOGo/',
    'account_url' => 'https://mail.alrowaduni.edu.sy/',
];
