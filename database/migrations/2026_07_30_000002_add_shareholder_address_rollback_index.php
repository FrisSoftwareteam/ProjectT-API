<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('shareholder_addresses')
            && Schema::hasColumn('shareholder_addresses', 'shareholder_id')
            && ! Schema::hasIndex('shareholder_addresses', ['shareholder_id'])
        ) {
            Schema::table('shareholder_addresses', function (Blueprint $table) {
                $table->index('shareholder_id', 'idx_shareholder_addresses_shareholder');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('shareholder_addresses') && Schema::hasIndex('shareholder_addresses', 'idx_shareholder_addresses_shareholder')) {
            Schema::table('shareholder_addresses', function (Blueprint $table) {
                $table->dropIndex('idx_shareholder_addresses_shareholder');
            });
        }
    }
};
