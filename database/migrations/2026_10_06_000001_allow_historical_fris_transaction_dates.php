<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fris_migration_units', function (Blueprint $table) {
            $table->dateTime('issue_date')->nullable()->change();
        });

        Schema::table('share_lots', function (Blueprint $table) {
            $table->dateTime('acquired_at')->change();
        });

        Schema::table('share_transactions', function (Blueprint $table) {
            $table->dateTime('tx_date')->change();
        });
    }

    public function down(): void
    {
        Schema::table('fris_migration_units', function (Blueprint $table) {
            $table->timestamp('issue_date')->nullable()->change();
        });

        Schema::table('share_lots', function (Blueprint $table) {
            $table->timestamp('acquired_at')->change();
        });

        Schema::table('share_transactions', function (Blueprint $table) {
            $table->timestamp('tx_date')->change();
        });
    }
};
