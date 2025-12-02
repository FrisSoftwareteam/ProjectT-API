<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_activity_logs')) {
            return;
        }

        if (!Schema::hasColumn('user_activity_logs', 'updated_at')) {
            Schema::table('user_activity_logs', function (Blueprint $table) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            });
        }

        if (!Schema::hasColumn('user_activity_logs', 'deleted_at')) {
            Schema::table('user_activity_logs', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('user_activity_logs')) {
            return;
        }

        if (Schema::hasColumn('user_activity_logs', 'deleted_at')) {
            Schema::table('user_activity_logs', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasColumn('user_activity_logs', 'updated_at')) {
            Schema::table('user_activity_logs', function (Blueprint $table) {
                $table->dropColumn('updated_at');
            });
        }
    }
};
