<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estate_case_representatives', function (Blueprint $table) {
            if (Schema::hasColumn('estate_case_representatives', 'full_name')) {
                $table->string('full_name', 255)->nullable()->change();
            }

            if (Schema::hasColumn('estate_case_representatives', 'id_type')) {
                $table->string('id_type', 50)->nullable()->change();
            }

            if (Schema::hasColumn('estate_case_representatives', 'id_value')) {
                $table->string('id_value', 100)->nullable()->change();
            }

            if (Schema::hasColumn('estate_case_representatives', 'email')) {
                $table->string('email', 255)->nullable()->change();
            }

            if (Schema::hasColumn('estate_case_representatives', 'phone')) {
                $table->string('phone', 32)->nullable()->change();
            }

            if (Schema::hasColumn('estate_case_representatives', 'address')) {
                $table->string('address', 255)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('estate_case_representatives', function (Blueprint $table) {
            if (Schema::hasColumn('estate_case_representatives', 'full_name')) {
                $table->string('full_name', 255)->nullable(false)->change();
            }

            if (Schema::hasColumn('estate_case_representatives', 'id_type')) {
                $table->string('id_type', 50)->nullable()->change();
            }

            if (Schema::hasColumn('estate_case_representatives', 'id_value')) {
                $table->string('id_value', 100)->nullable()->change();
            }

            if (Schema::hasColumn('estate_case_representatives', 'email')) {
                $table->string('email', 255)->nullable()->change();
            }

            if (Schema::hasColumn('estate_case_representatives', 'phone')) {
                $table->string('phone', 32)->nullable()->change();
            }

            if (Schema::hasColumn('estate_case_representatives', 'address')) {
                $table->string('address', 255)->nullable()->change();
            }
        });
    }
};
