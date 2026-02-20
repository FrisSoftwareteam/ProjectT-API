<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shareholders', function (Blueprint $table) {
            $table->enum('sex', ['male', 'female', 'other'])->nullable()->after('date_of_birth');
            $table->string('next_of_kin_name', 255)->nullable()->after('tax_id');
            $table->string('next_of_kin_phone', 32)->nullable()->after('next_of_kin_name');
            $table->string('next_of_kin_relationship', 100)->nullable()->after('next_of_kin_phone');
        });
    }

    public function down(): void
    {
        Schema::table('shareholders', function (Blueprint $table) {
            $table->dropColumn(['sex', 'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship']);
        });
    }
};
