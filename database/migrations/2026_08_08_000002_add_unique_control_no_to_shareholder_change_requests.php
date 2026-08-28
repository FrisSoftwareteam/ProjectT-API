<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // shareholder_change_requests has never been written to (dormant table),
        // so this unique index is safe to add regardless of existing data.
        Schema::table('shareholder_change_requests', function (Blueprint $table) {
            $table->unique('control_no', 'uk_shareholder_change_requests_control_no');
        });
    }

    public function down(): void
    {
        Schema::table('shareholder_change_requests', function (Blueprint $table) {
            $table->dropUnique('uk_shareholder_change_requests_control_no');
        });
    }
};
