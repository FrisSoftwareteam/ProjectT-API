<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registers', function (Blueprint $table) {
            if (! Schema::hasColumn('registers', 'instrument_type_id')) {
                $table->foreignId('instrument_type_id')
                    ->nullable()
                    ->after('name')
                    ->constrained('instrument_types')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('registers', 'unit_precision_type')) {
                $table->enum('unit_precision_type', ['whole_number', 'decimal'])
                    ->default('decimal')
                    ->after('instrument_type_id');
            }

            if (! Schema::hasColumn('registers', 'decimal_precision')) {
                $table->unsignedTinyInteger('decimal_precision')
                    ->nullable()
                    ->after('unit_precision_type');
            }
        });

        // Backfill: link all existing 'equity' registers to the "Ordinary Shares" seed type.
        // Default their precision to decimal with 2dp (safe minimum, can be updated by admin).
        $ordinaryShareId = DB::table('instrument_types')->where('code', 'ordinary_share')->value('id');

        if ($ordinaryShareId) {
            DB::table('registers')
                ->where('instrument_type', 'equity')
                ->update([
                    'instrument_type_id'  => $ordinaryShareId,
                    'unit_precision_type' => 'decimal',
                    'decimal_precision'   => 2,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('registers', function (Blueprint $table) {
            if (Schema::hasColumn('registers', 'decimal_precision')) {
                $table->dropColumn('decimal_precision');
            }
            if (Schema::hasColumn('registers', 'unit_precision_type')) {
                $table->dropColumn('unit_precision_type');
            }
            if (Schema::hasColumn('registers', 'instrument_type_id')) {
                $table->dropForeign(['instrument_type_id']);
                $table->dropColumn('instrument_type_id');
            }
        });
    }
};