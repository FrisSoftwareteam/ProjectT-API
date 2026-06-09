<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the `name` column to share_classes if it does not already exist.
     * Safe to run on any environment — idempotent.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('share_classes', 'name')) {
            Schema::table('share_classes', function (Blueprint $table) {
                $table->string('name', 100)
                      ->nullable()
                      ->after('class_code')
                      ->comment('Human-readable name for the share class (e.g. Ordinary Shares)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('share_classes', 'name')) {
            Schema::table('share_classes', function (Blueprint $table) {
                $table->dropColumn('name');
            });
        }
    }
};