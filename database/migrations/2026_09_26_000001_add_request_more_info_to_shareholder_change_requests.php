<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE shareholder_change_requests
            MODIFY COLUMN status
            ENUM('draft','submitted','verified','approved_level1','approved_level2','rejected','applied','info_requested') NOT NULL DEFAULT 'submitted'
        ");

        Schema::table('shareholder_change_requests', function (Blueprint $table) {
            $table->enum('info_requested_type', ['banker_confirmation', 'shareholder_request'])->nullable()->after('reason');
            $table->string('info_requested_note', 255)->nullable()->after('info_requested_type');
            $table->foreignId('info_requested_by')
                ->nullable()
                ->after('info_requested_note')
                ->constrained('admin_users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->timestamp('info_requested_at')->nullable()->after('info_requested_by');
        });
    }

    public function down(): void
    {
        Schema::table('shareholder_change_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('info_requested_by');
            $table->dropColumn(['info_requested_type', 'info_requested_note', 'info_requested_at']);
        });

        DB::statement("
            ALTER TABLE shareholder_change_requests
            MODIFY COLUMN status
            ENUM('draft','submitted','verified','approved_level1','approved_level2','rejected','applied') NOT NULL DEFAULT 'submitted'
        ");
    }
};
