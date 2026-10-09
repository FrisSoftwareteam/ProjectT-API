<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('shareholder_change_requests', 'resubmitted_from_id')) {
            return;
        }

        Schema::table('shareholder_change_requests', function (Blueprint $table) {
            $table->foreignId('resubmitted_from_id')
                ->nullable()
                ->after('reason')
                ->constrained('shareholder_change_requests')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Intentionally left blank — additive, non-destructive migration.
    }
};
