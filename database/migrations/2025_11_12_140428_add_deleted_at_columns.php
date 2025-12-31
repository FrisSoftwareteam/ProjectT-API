<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('share_classes', function (Blueprint $table) {
            if (!Schema::hasColumn('share_classes', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('registers', function (Blueprint $table) {
            if (!Schema::hasColumn('registers', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('share_classes', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('registers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};