<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('sra_guardians')) {
            return;
        }

        if (! Schema::hasColumn('sra_guardians', 'guardian_shareholder_id')) {
            Schema::table('sra_guardians', function (Blueprint $table) {
                $table->foreignId('guardian_shareholder_id')->nullable()->after('sra_id')->constrained('shareholders')->cascadeOnUpdate()->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('sra_guardians', 'verified_status')) {
            Schema::table('sra_guardians', function (Blueprint $table) {
                $table->enum('verified_status', ['pending','verified','rejected'])->default('pending')->after('document_ref');
            });
        }

        if (! Schema::hasColumn('sra_guardians', 'verified_by')) {
            Schema::table('sra_guardians', function (Blueprint $table) {
                $table->foreignId('verified_by')->nullable()->after('verified_status')->constrained('admin_users')->cascadeOnUpdate()->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('sra_guardians', 'verified_at')) {
            Schema::table('sra_guardians', function (Blueprint $table) {
                $table->timestamp('verified_at')->nullable()->after('verified_by');
            });
        }

        if (! Schema::hasColumn('sra_guardians', 'permissions')) {
            Schema::table('sra_guardians', function (Blueprint $table) {
                $table->json('permissions')->nullable()->after('verified_at');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sra_guardians')) {
            return;
        }

        if (Schema::hasColumn('sra_guardians', 'permissions')) {
            Schema::table('sra_guardians', function (Blueprint $table) {
                $table->dropColumn('permissions');
            });
        }

        if (Schema::hasColumn('sra_guardians', 'verified_at')) {
            Schema::table('sra_guardians', function (Blueprint $table) {
                $table->dropColumn('verified_at');
            });
        }

        if (Schema::hasColumn('sra_guardians', 'verified_by')) {
            Schema::table('sra_guardians', function (Blueprint $table) {
                $table->dropForeign(['verified_by']);
                $table->dropColumn('verified_by');
            });
        }

        if (Schema::hasColumn('sra_guardians', 'verified_status')) {
            Schema::table('sra_guardians', function (Blueprint $table) {
                $table->dropColumn('verified_status');
            });
        }

        if (Schema::hasColumn('sra_guardians', 'guardian_shareholder_id')) {
            Schema::table('sra_guardians', function (Blueprint $table) {
                $table->dropForeign(['guardian_shareholder_id']);
                $table->dropColumn('guardian_shareholder_id');
            });
        }
    }
};
