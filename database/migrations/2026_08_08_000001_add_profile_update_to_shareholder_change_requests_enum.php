<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE shareholder_change_requests
            MODIFY COLUMN request_type
            ENUM('email_change','phone_change','address_change','bank_mandate','name_change','status_change','cscs_update','chn_update','profile_update') NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE shareholder_change_requests
            MODIFY COLUMN request_type
            ENUM('email_change','phone_change','address_change','bank_mandate','name_change','status_change','cscs_update','chn_update') NOT NULL
        ");
    }
};
