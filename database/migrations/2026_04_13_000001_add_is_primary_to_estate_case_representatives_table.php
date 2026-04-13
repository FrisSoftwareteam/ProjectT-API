<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estate_case_representatives', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false)->after('address');
        });

        DB::table('estate_case_representatives as current')
            ->join(
                DB::raw('(
                    SELECT MIN(id) AS id
                    FROM estate_case_representatives
                    GROUP BY probate_case_id
                ) as first_per_case'),
                'first_per_case.id',
                '=',
                'current.id'
            )
            ->update(['current.is_primary' => true]);
    }

    public function down(): void
    {
        Schema::table('estate_case_representatives', function (Blueprint $table) {
            $table->dropColumn('is_primary');
        });
    }
};
