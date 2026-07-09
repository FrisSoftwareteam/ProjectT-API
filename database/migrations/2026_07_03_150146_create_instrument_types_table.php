<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instrument_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 50)->unique();
            $table->string('category', 50);
            $table->enum('precision_rule', ['decimal_only', 'whole_number_only', 'configurable']);
            $table->text('description')->nullable();
            $table->boolean('is_seeded')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed the three required built-in types
        DB::table('instrument_types')->insert([
            [
                'name'           => 'Ordinary Shares',
                'code'           => 'ordinary_share',
                'category'       => 'equity',
                'precision_rule' => 'decimal_only',
                'description'    => 'Ordinary equity shares. Supports decimal units with configurable precision (2-4 decimal places).',
                'is_seeded'      => true,
                'is_active'      => true,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [
                'name'           => 'Bond',
                'code'           => 'bond',
                'category'       => 'debt',
                'precision_rule' => 'whole_number_only',
                'description'    => 'Debt instrument. Units and holdings are whole numbers only; decimals are not permitted.',
                'is_seeded'      => true,
                'is_active'      => true,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [
                'name'           => 'Mutual Fund',
                'code'           => 'mutual_fund',
                'category'       => 'fund',
                'precision_rule' => 'configurable',
                'description'    => 'Mutual fund units. Each register independently selects whole number or decimal precision.',
                'is_seeded'      => true,
                'is_active'      => true,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('instrument_types');
    }
};