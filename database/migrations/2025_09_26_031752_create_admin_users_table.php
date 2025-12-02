<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('microsoft_id')->unique()->nullable();
            $table->string('email')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('department')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->string('profile_picture')->nullable();
            $table->json('microsoft_data')->nullable(); // Store additional Microsoft profile data
            $table->timestamps();
            
            // Indexes for better performance
            $table->index(['email', 'is_active']);
            $table->index('microsoft_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
