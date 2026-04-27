<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shareholder_cautions', function (Blueprint $table) {
            $table->string('supporting_document_path', 500)
                  ->nullable()
                  ->after('effective_date')
                  ->comment('Path to uploaded referenced note or supporting document (JPG, JPEG, PNG)');
        });
    }

    public function down(): void
    {
        Schema::table('shareholder_cautions', function (Blueprint $table) {
            $table->dropColumn('supporting_document_path');
        });
    }
};
