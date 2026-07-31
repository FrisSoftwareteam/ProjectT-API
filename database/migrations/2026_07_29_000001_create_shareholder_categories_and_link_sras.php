<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shareholder_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 150);
            $table->enum('default_holder_type', ['individual', 'corporate'])->nullable();
            $table->boolean('requires_joint_holders')->default(false);
            $table->boolean('requires_review')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('source_system', 50)->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'default_holder_type'], 'idx_shcat_active_type');
        });

        Schema::table('shareholder_register_accounts', function (Blueprint $table) {
            $table->foreignId('shareholder_category_id')
                ->nullable()
                ->after('register_id')
                ->constrained('shareholder_categories')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shareholder_register_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shareholder_category_id');
        });

        Schema::dropIfExists('shareholder_categories');
    }
};
