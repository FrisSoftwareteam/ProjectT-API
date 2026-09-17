<?php

return [
    'chunk_size' => (int) env('LEGACY_MIGRATION_CHUNK_SIZE', 1000),
    'queue' => env('LEGACY_MIGRATION_QUEUE', 'legacy-migrations'),

    'packages' => [
        'estock_fidelity_87_v1' => [
            'name' => 'Estock Fidelity register 87',
            'version' => '1.0.0',
            'source_path' => base_path('docs/ESTOCK JSON ONLY/FIDELITY REGISTER.json'),
            'source_filename' => 'FIDELITY REGISTER.json',
            'source_sha256' => '90ebc0546e5d763447f64cbbc2ddf23d5763d743bc37880b5f7ea2e0c2708885',
            'source_register_code' => '87',
            'expected_rows' => 402770,
            'expected_quantity' => '28962585692.000000',
            'holding_mode' => 'paper',
            'contact_policy' => 'unique_deterministic_unverified_placeholders',
            'status' => 'active',
            'category_holder_types' => [
                'A' => 'individual',
                'I' => 'individual',
                'C' => 'corporate',
                'Z' => 'corporate',
            ],
            'foreign_temp_types' => [
                'A' => 'individual',
                'C' => 'corporate',
            ],
        ],
    ],
];
