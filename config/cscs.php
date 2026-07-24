<?php

return [
    'max_upload_kb' => (int) env('CSCS_MAX_UPLOAD_KB', 20480),
    'allowed_extensions' => ['txt', 'csv'],
    'retention_days' => (int) env('CSCS_RETENTION_DAYS', 2555),
    'max_page_size' => (int) env('CSCS_MAX_PAGE_SIZE', 100),
];
