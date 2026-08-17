<?php

return [
    'chunk_size' => (int) env('COMPANY_DATA_RELEASE_CHUNK_SIZE', 1000),
    'maximum_uncompressed_record_bytes' => (int) env('COMPANY_DATA_RELEASE_MAX_BYTES', 2147483648),
];
