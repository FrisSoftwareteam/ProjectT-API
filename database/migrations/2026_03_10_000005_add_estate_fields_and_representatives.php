<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('probate_cases', function (Blueprint $table) {
            if (! Schema::hasColumn('probate_cases', 'case_type')) {
                $table->enum('case_type', ['probate', 'letters_of_administration'])
                    ->default('probate')
                    ->after('shareholder_id');
            }

            if (! Schema::hasColumn('probate_cases', 'grant_date')) {
                $table->date('grant_date')->nullable()->after('court_ref');
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

        Schema::create('estate_case_representatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('probate_case_id')->constrained('probate_cases')->cascadeOnUpdate()->cascadeOnDelete();
            $table->enum('representative_type', ['executor', 'administrator']);
            $table->string('full_name', 255);
            $table->string('id_type', 50)->nullable();
            $table->string('id_value', 100)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('address', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estate_case_representatives');

        Schema::table('probate_cases', function (Blueprint $table) {
            if (Schema::hasColumn('probate_cases', 'estate_shareholder_id')) {
                $table->dropForeign(['estate_shareholder_id']);
                $table->dropColumn('estate_shareholder_id');
            }
            if (Schema::hasColumn('probate_cases', 'case_status')) {
                $table->dropColumn('case_status');
            }
            if (Schema::hasColumn('probate_cases', 'grant_date')) {
                $table->dropColumn('grant_date');
            }
            if (Schema::hasColumn('probate_cases', 'case_type')) {
                $table->dropColumn('case_type');
            }
        });
    }
};

