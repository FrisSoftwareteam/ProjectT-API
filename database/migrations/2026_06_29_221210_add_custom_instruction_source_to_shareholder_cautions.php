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
    Schema::table('shareholder_cautions', function (Blueprint $table) {
        $table->string('custom_instruction_source')->nullable()->after('instruction_source');
    });

    Schema::table('shareholder_caution_logs', function (Blueprint $table) {
        $table->string('custom_instruction_source')->nullable()->after('instruction_source');
    });
}

public function down(): void
{
    Schema::table('shareholder_cautions', function (Blueprint $table) {
        $table->dropColumn('custom_instruction_source');
    });

    Schema::table('shareholder_caution_logs', function (Blueprint $table) {
        $table->dropColumn('custom_instruction_source');
    });
}
};
