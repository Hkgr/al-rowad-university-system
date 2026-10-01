<?php

return [
    'base_url' => env('MAILCOW_BASE_URL', 'https://mail.alrowaduni.edu.sy'),
    'api_key' => env('MAILCOW_API_KEY', ''),
    'student_domain' => env('MAILCOW_STUDENT_DOMAIN', 'alrowaduni.edu.sy'),
    // Mailcow quota input units are MiB (1,048,576 bytes), not decimal MB.
    'student_quota_mb' => (int) env('MAILCOW_STUDENT_QUOTA_MB', 50),
];
