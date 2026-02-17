<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dividend_approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dividend_declaration_id')
                ->constrained('dividend_declarations')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->enum('role_code', ['IT', 'OVERSIGHT_OPS', 'OVERSIGHT_MF', 'ACCOUNTS', 'AUDIT']);
            $table->foreignId('reliever_user_id')
                ->constrained('admin_users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('assigned_by')
                ->constrained('admin_users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['dividend_declaration_id', 'role_code', 'reliever_user_id'], 'uq_div_delegation_unique');
            $table->index(['dividend_declaration_id', 'role_code'], 'idx_div_delegation_decl_role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dividend_approval_delegations');
    }
};
