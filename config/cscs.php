<?php

return [
    'require_preview_confirmation' => (bool) env('CSCS_REQUIRE_PREVIEW_CONFIRMATION', true),
    // Zero disables an age limit; unchanged holdings remain valid by default.
    'holdings_max_age_hours' => (int) env('CSCS_HOLDINGS_MAX_AGE_HOURS', 0),
    'trade_date_min' => env('CSCS_TRADE_DATE_MIN'),
    'trade_date_max' => env('CSCS_TRADE_DATE_MAX'),
    'validate_holder_names' => (bool) env('CSCS_VALIDATE_HOLDER_NAMES', false),
    'max_upload_kb' => (int) env('CSCS_MAX_UPLOAD_KB', 20480),
    'allowed_extensions' => ['txt', 'csv'],
    'retention_days' => (int) env('CSCS_RETENTION_DAYS', 2555),
    'max_page_size' => (int) env('CSCS_MAX_PAGE_SIZE', 200),
    'queue' => env('CSCS_QUEUE', 'cscs'),
    'import_job_timeout' => (int) env('CSCS_IMPORT_JOB_TIMEOUT', 3600),
    'file_detection_sample_lines' => (int) env('CSCS_FILE_DETECTION_SAMPLE_LINES', 25),
    'additional_approval_risk_flags' => ['NEW_ACCOUNT'],
];
