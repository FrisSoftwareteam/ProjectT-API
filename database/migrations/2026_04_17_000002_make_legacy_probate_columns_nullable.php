<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('probate_cases', function (Blueprint $table) {
            if (Schema::hasColumn('probate_cases', 'executor_name')) {
                $table->string('executor_name', 255)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('probate_cases', function (Blueprint $table) {
            if (Schema::hasColumn('probate_cases', 'executor_name')) {
                $table->string('executor_name', 255)->nullable(false)->change();
            }
        });
    }
};
