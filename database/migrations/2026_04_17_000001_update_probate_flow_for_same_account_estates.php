<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('probate_cases', function (Blueprint $table) {
            if (! Schema::hasColumn('probate_cases', 'original_first_name')) {
                $table->string('original_first_name', 255)->nullable()->after('estate_shareholder_id');
            }

            if (! Schema::hasColumn('probate_cases', 'original_last_name')) {
                $table->string('original_last_name', 100)->nullable()->after('original_first_name');
            }

            if (! Schema::hasColumn('probate_cases', 'original_middle_name')) {
                $table->string('original_middle_name', 100)->nullable()->after('original_last_name');
            }

            if (! Schema::hasColumn('probate_cases', 'original_full_name')) {
                $table->string('original_full_name', 255)->nullable()->after('original_middle_name');
            }
        });

        Schema::table('estate_case_representatives', function (Blueprint $table) {
            if (! Schema::hasColumn('estate_case_representatives', 'shareholder_id')) {
                $table->foreignId('shareholder_id')
                    ->nullable()
                    ->after('probate_case_id')
                    ->constrained('shareholders')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();
            }

            $table->unique(['probate_case_id', 'shareholder_id'], 'estate_case_rep_case_shareholder_unique');
        });
    }

    public function down(): void
    {
        Schema::table('estate_case_representatives', function (Blueprint $table) {
            $table->dropUnique('estate_case_rep_case_shareholder_unique');

            if (Schema::hasColumn('estate_case_representatives', 'shareholder_id')) {
                $table->dropForeign(['shareholder_id']);
                $table->dropColumn('shareholder_id');
            }
        });

        Schema::table('probate_cases', function (Blueprint $table) {
            foreach ([
                'original_full_name',
                'original_middle_name',
                'original_last_name',
                'original_first_name',
            ] as $column) {
                if (Schema::hasColumn('probate_cases', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
