<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_activity_logs', function (Blueprint $table) {
            // Add updated_at if it's missing
            if (! Schema::hasColumn('user_activity_logs', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }

            // Add soft deletes
            if (! Schema::hasColumn('user_activity_logs', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_activity_logs', function (Blueprint $table) {
            if (Schema::hasColumn('user_activity_logs', 'deleted_at')) {
                $table->dropSoftDeletes();
            }

            if (Schema::hasColumn('user_activity_logs', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
        });
    }
};
