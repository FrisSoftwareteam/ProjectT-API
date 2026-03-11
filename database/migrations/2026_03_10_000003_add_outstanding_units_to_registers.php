<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registers', function (Blueprint $table) {
            if (! Schema::hasColumn('registers', 'total_units_outstanding')) {
                $table->decimal('total_units_outstanding', 28, 6)->default(0)->after('paid_up_capital');
            }

            if (! Schema::hasColumn('registers', 'remaining_outstanding_units')) {
                $table->decimal('remaining_outstanding_units', 28, 6)->default(0)->after('total_units_outstanding');
            }
        });
    }

    public function down(): void
    {
        Schema::table('registers', function (Blueprint $table) {
            if (Schema::hasColumn('registers', 'remaining_outstanding_units')) {
                $table->dropColumn('remaining_outstanding_units');
            }

            if (Schema::hasColumn('registers', 'total_units_outstanding')) {
                $table->dropColumn('total_units_outstanding');
            }
        });
    }
};

