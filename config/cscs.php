<?php

return [
    'max_upload_kb' => (int) env('CSCS_MAX_UPLOAD_KB', 20480),
    'allowed_extensions' => ['txt', 'csv'],
    'retention_days' => (int) env('CSCS_RETENTION_DAYS', 2555),
    'max_page_size' => (int) env('CSCS_MAX_PAGE_SIZE', 100),
    'queue' => env('CSCS_QUEUE', 'cscs'),
    'import_job_timeout' => (int) env('CSCS_IMPORT_JOB_TIMEOUT', 3600),
    'file_detection_sample_lines' => (int) env('CSCS_FILE_DETECTION_SAMPLE_LINES', 25),
    'additional_approval_risk_flags' => ['NEW_ACCOUNT'],
];
