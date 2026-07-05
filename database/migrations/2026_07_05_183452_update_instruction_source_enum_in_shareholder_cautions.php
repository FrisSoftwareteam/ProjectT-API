<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE shareholder_cautions 
            MODIFY COLUMN instruction_source 
            ENUM('sec','court','exchange','bank','internal','other') NOT NULL
        ");

        DB::statement("
            ALTER TABLE shareholder_caution_logs 
            MODIFY COLUMN instruction_source 
            ENUM('sec','court','exchange','bank','internal','other') NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE shareholder_cautions 
            MODIFY COLUMN instruction_source 
            ENUM('sec','court','exchange','bank','internal') NOT NULL
        ");

        DB::statement("
            ALTER TABLE shareholder_caution_logs 
            MODIFY COLUMN instruction_source 
            ENUM('sec','court','exchange','bank','internal') NOT NULL
        ");
    }
};