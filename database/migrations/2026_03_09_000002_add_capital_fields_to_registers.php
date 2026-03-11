<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registers', function (Blueprint $table) {
            if (! Schema::hasColumn('registers', 'instrument_type')) {
                $table->string('instrument_type', 100)->default('equity')->after('name');
            }

            if (! Schema::hasColumn('registers', 'capital_behaviour_type')) {
                $table->enum('capital_behaviour_type', ['constant', 'open_ended', 'amortising'])
                    ->default('constant')
                    ->after('instrument_type');
            }

            if (! Schema::hasColumn('registers', 'paid_up_capital')) {
                $table->decimal('paid_up_capital', 28, 6)->nullable()->after('capital_behaviour_type');
            }

            if (! Schema::hasColumn('registers', 'narration')) {
                $table->text('narration')->nullable()->after('paid_up_capital');
            }
        });
    }

    public function down(): void
    {
        Schema::table('registers', function (Blueprint $table) {
            if (Schema::hasColumn('registers', 'narration')) {
                $table->dropColumn('narration');
            }
            if (Schema::hasColumn('registers', 'paid_up_capital')) {
                $table->dropColumn('paid_up_capital');
            }
            if (Schema::hasColumn('registers', 'capital_behaviour_type')) {
                $table->dropColumn('capital_behaviour_type');
            }
            if (Schema::hasColumn('registers', 'instrument_type')) {
                $table->dropColumn('instrument_type');
            }
        });
    }
};

