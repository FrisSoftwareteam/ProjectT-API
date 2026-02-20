<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dividend_payments', function (Blueprint $table) {
            $table->string('dividend_payment_no', 64)->nullable()->after('id');
            $table->unique('dividend_payment_no', 'uq_dividend_payment_no');
        });
    }

    public function down(): void
    {
        Schema::table('dividend_payments', function (Blueprint $table) {
            $table->dropUnique('uq_dividend_payment_no');
            $table->dropColumn('dividend_payment_no');
        });
    }
};
