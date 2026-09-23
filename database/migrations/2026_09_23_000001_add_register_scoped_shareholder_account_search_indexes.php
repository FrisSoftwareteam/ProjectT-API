<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shareholder_register_accounts', function (Blueprint $table) {
            $table->index(['register_id', 'chn'], 'idx_sra_register_chn');
            $table->index(['register_id', 'cscs_account_no'], 'idx_sra_register_cscs');
            $table->index(['register_id', 'shareholder_no'], 'idx_sra_register_shareholder_no');
            $table->index(['register_id', 'shareholder_id'], 'idx_sra_register_shareholder');
        });
    }

    public function down(): void
    {
        Schema::table('shareholder_register_accounts', function (Blueprint $table) {
            $table->dropIndex('idx_sra_register_chn');
            $table->dropIndex('idx_sra_register_cscs');
            $table->dropIndex('idx_sra_register_shareholder_no');
            $table->dropIndex('idx_sra_register_shareholder');
        });
    }
};
