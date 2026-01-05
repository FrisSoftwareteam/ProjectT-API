<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('dividend_declaration_share_classes', function (Blueprint $table) {
            // Added updated_at column to support withTimestamps() in the model
            $table->timestamp('updated_at')
                  ->useCurrent()
                  ->after('created_at')
                  ->comment('Tracks when the pivot record was last updated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dividend_declaration_share_classes', function (Blueprint $table) {
            $table->dropColumn('updated_at');
        });
    }
};