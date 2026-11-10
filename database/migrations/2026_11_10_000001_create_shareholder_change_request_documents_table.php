<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shareholder_change_request_documents')) {
            return;
        }

        Schema::create('shareholder_change_request_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shareholder_change_request_id')
                ->constrained('shareholder_change_requests')
                ->cascadeOnDelete();
            $table->enum('category', ['name', 'bank', 'address']);
            $table->string('document_type', 150);
            $table->string('file_name', 255);
            $table->string('file_path', 255);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->foreignId('uploaded_by')->constrained('admin_users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['shareholder_change_request_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shareholder_change_request_documents');
    }
};
