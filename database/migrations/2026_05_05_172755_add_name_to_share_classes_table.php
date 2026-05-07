<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('share_classes', function (Blueprint $table) {
            $table->string('name', 100)
                  ->nullable()
                  ->after('class_code')
                  ->comment('Human-readable name for the share class (e.g. Ordinary Shares)');
        });
    }

    public function down(): void
    {
        Schema::table('share_classes', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};