<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estate_case_representatives', function (Blueprint $table) {
            foreach (['full_name', 'id_type', 'id_value', 'email', 'phone', 'address'] as $column) {
                if (Schema::hasColumn('estate_case_representatives', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('probate_cases', function (Blueprint $table) {
            if (Schema::hasColumn('probate_cases', 'estate_shareholder_id')) {
                $table->dropForeign(['estate_shareholder_id']);
                $table->dropColumn('estate_shareholder_id');
            }

            foreach (['executor_name', 'case_status'] as $column) {
                if (Schema::hasColumn('probate_cases', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('probate_cases', function (Blueprint $table) {
            if (! Schema::hasColumn('probate_cases', 'executor_name')) {
                $table->string('executor_name', 255)->nullable()->after('grant_date');
            }

            if (! Schema::hasColumn('probate_cases', 'case_status')) {
                $table->enum('case_status', ['draft', 'submitted', 'approved'])
                    ->default('draft')
                    ->after('document_ref');
            }

            if (! Schema::hasColumn('probate_cases', 'estate_shareholder_id')) {
                $table->foreignId('estate_shareholder_id')
                    ->nullable()
                    ->after('case_status')
                    ->constrained('shareholders')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            }
        });

        Schema::table('estate_case_representatives', function (Blueprint $table) {
            if (! Schema::hasColumn('estate_case_representatives', 'full_name')) {
                $table->string('full_name', 255)->nullable()->after('representative_type');
            }

            if (! Schema::hasColumn('estate_case_representatives', 'id_type')) {
                $table->string('id_type', 50)->nullable()->after('full_name');
            }

            if (! Schema::hasColumn('estate_case_representatives', 'id_value')) {
                $table->string('id_value', 100)->nullable()->after('id_type');
            }

            if (! Schema::hasColumn('estate_case_representatives', 'email')) {
                $table->string('email', 255)->nullable()->after('id_value');
            }

            if (! Schema::hasColumn('estate_case_representatives', 'phone')) {
                $table->string('phone', 32)->nullable()->after('email');
            }

            if (! Schema::hasColumn('estate_case_representatives', 'address')) {
                $table->string('address', 255)->nullable()->after('phone');
            }
        });
    }
};
