<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('probate_cases', function (Blueprint $table) {
            if (! Schema::hasColumn('probate_cases', 'created_at')) {
                $table->timestamp('created_at')->nullable()->after('closed_at');
            }

            if (! Schema::hasColumn('probate_cases', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('probate_cases', function (Blueprint $table) {
            if (Schema::hasColumn('probate_cases', 'updated_at')) {
                $table->dropColumn('updated_at');
            }

            if (Schema::hasColumn('probate_cases', 'created_at')) {
                $table->dropColumn('created_at');
            }
        });
    }
};
