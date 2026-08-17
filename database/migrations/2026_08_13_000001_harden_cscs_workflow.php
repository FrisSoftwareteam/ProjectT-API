<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cscs_batch_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('cscs_upload_batches')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('snapshot_hash', 64);
            $table->json('payload');
            $table->json('reconciliation');
            $table->json('risk_flags')->nullable();
            $table->json('source_files');
            $table->foreignId('submitted_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['batch_id', 'revision'], 'uk_cscs_snapshot_revision');
            $table->index('snapshot_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cscs_batch_snapshots');
    }
};
