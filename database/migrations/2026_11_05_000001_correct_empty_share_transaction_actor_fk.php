<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('share_transactions')) {
            return;
        }
        foreach (Schema::getForeignKeys('share_transactions') as $foreignKey) {
            if ($foreignKey['columns'] !== ['created_by'] || $foreignKey['foreign_table'] !== 'users') {
                continue;
            }
            if (DB::table('share_transactions')->whereNotNull('created_by')->exists()) {
                throw new RuntimeException('Historical share transaction actors require explicit review before changing users to admin_users. No actor IDs were reassigned.');
            }
            Schema::table('share_transactions', fn (Blueprint $table) => $table->dropForeign(DB::connection()->getDriverName() === 'sqlite' ? ['created_by'] : $foreignKey['name']));
            Schema::table('share_transactions', fn (Blueprint $table) => $table->foreign('created_by')->references('id')->on('admin_users')->cascadeOnUpdate()->restrictOnDelete());
        }
    }

    public function down(): void
    {
        // Do not recreate an incorrect actor constraint on rollback.
    }
};
