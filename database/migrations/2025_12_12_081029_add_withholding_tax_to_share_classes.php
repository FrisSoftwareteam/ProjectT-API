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
        Schema::table('share_classes', function (Blueprint $table) {
            // Added withholding_tax_rate column after par_value
            // Using decimal(5,2) to support rates like 7.5%, 10%, 99.99%
            // This stores the percentage value (e.g., 7.5 for 7.5%, 10 for 10%)
            $table->decimal('withholding_tax_rate', 5, 2)
                  ->default(10.00)
                  ->after('par_value')
                  ->comment('Withholding tax rate as percentage (e.g., 7.5 for 7.5%, 10 for 10%)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('share_classes', function (Blueprint $table) {
            $table->dropColumn('withholding_tax_rate');
        });
    }
};