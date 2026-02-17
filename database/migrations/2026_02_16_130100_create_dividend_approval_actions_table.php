<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dividend_approval_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dividend_declaration_id')
                ->constrained('dividend_declarations')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('step_no');
            $table->enum('role_code', ['IT', 'OVERSIGHT_OPS', 'OVERSIGHT_MF', 'ACCOUNTS', 'AUDIT']);
            $table->enum('decision', ['APPROVED', 'REJECTED', 'QUERY_RAISED']);
            $table->foreignId('actor_id')
                ->constrained('admin_users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('comment', 255)->nullable();
            $table->timestamp('acted_at')->useCurrent();
            $table->timestamps();

            $table->index(['dividend_declaration_id', 'step_no'], 'idx_div_approval_decl_step');
            $table->index(['dividend_declaration_id', 'role_code', 'decision'], 'idx_div_approval_role_decision');
            $table->index(['dividend_declaration_id', 'actor_id', 'decision'], 'idx_div_approval_actor_decision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dividend_approval_actions');
    }
};
