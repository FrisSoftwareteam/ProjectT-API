<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sra_guardians', function (Blueprint $table) {
            if (! Schema::hasColumn('sra_guardians', 'guardian_shareholder_id')) {
                $table->foreignId('guardian_shareholder_id')->nullable()->after('sra_id')->constrained('shareholders')->cascadeOnUpdate()->restrictOnDelete();
            }

            if (! Schema::hasColumn('sra_guardians', 'verified_status')) {
                $table->enum('verified_status', ['pending','verified','rejected'])->default('pending')->after('document_ref');
            }

            if (! Schema::hasColumn('sra_guardians', 'verified_by')) {
                $table->foreignId('verified_by')->nullable()->after('verified_status')->constrained('admin_users')->cascadeOnUpdate()->restrictOnDelete();
            }

            if (! Schema::hasColumn('sra_guardians', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('verified_by');
            }

            if (! Schema::hasColumn('sra_guardians', 'permissions')) {
                $table->json('permissions')->nullable()->after('verified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sra_guardians', function (Blueprint $table) {
            if (Schema::hasColumn('sra_guardians', 'permissions')) {
                $table->dropColumn('permissions');
            }

            if (Schema::hasColumn('sra_guardians', 'verified_at')) {
                $table->dropColumn('verified_at');
            }

            if (Schema::hasColumn('sra_guardians', 'verified_by')) {
                $table->dropForeign(['verified_by']);
                $table->dropColumn('verified_by');
            }

            if (Schema::hasColumn('sra_guardians', 'verified_status')) {
                $table->dropColumn('verified_status');
            }

            if (Schema::hasColumn('sra_guardians', 'guardian_shareholder_id')) {
                $table->dropForeign(['guardian_shareholder_id']);
                $table->dropColumn('guardian_shareholder_id');
            }
        });
    }
};
