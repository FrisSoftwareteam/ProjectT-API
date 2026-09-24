<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RemediateFrisStructureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('issuer_code')->unique();
            $table->string('name');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('instrument_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
        });
        Schema::create('registers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('register_code');
            $table->string('name');
            $table->boolean('is_default')->default(true);
            $table->string('instrument_type')->nullable();
            $table->unsignedBigInteger('instrument_type_id')->nullable();
            $table->string('capital_behaviour_type')->nullable();
            $table->string('unit_precision_type')->nullable();
            $table->unsignedTinyInteger('decimal_precision')->nullable();
            $table->timestamps();
        });
        Schema::create('share_classes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('register_id');
            $table->string('class_code');
            $table->string('name')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });
        Schema::create('shareholder_register_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('register_id');
        });
        Schema::create('share_positions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sra_id');
            $table->unsignedBigInteger('share_class_id');
        });
        Schema::create('fris_migration_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('register_code');
            $table->string('company_name');
            $table->string('status')->default('PUBLISHED');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('fris_migration_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('sra_id')->nullable();
        });
        Schema::create('dividend_declarations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('register_id');
            $table->string('period_label');
            $table->timestamps();
        });
        Schema::create('cscs_security_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('security_code')->unique();
            $table->unsignedBigInteger('register_id');
            $table->unsignedBigInteger('share_class_id');
            $table->boolean('is_active');
            $table->timestamps();
        });

        DB::table('instrument_types')->insert([
            ['id' => 1, 'code' => 'ordinary_share'],
            ['id' => 2, 'code' => 'bond'],
            ['id' => 3, 'code' => 'mutual_fund'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ([
            'cscs_security_mappings',
            'dividend_declarations',
            'fris_migration_profiles',
            'fris_migration_batches',
            'share_positions',
            'shareholder_register_accounts',
            'share_classes',
            'registers',
            'instrument_types',
            'companies',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_it_groups_related_registers_and_corrects_preference_metadata_without_rekeying_holdings(): void
    {
        $now = now();
        DB::table('companies')->insert([
            ['id' => 1, 'issuer_code' => 'FRIS-318', 'name' => 'CR SERVICES (CREDIT BUREAU) PLC (ORDINARY)', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'issuer_code' => 'FRIS-319', 'name' => 'CR SERVICES (CREDIT BUREAU) PLC (PREFERENCE) CLASS B', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('registers')->insert([
            ['id' => 10, 'company_id' => 1, 'register_code' => '318', 'name' => 'CR SERVICES (CREDIT BUREAU) PLC (ORDINARY)', 'is_default' => true, 'instrument_type' => 'equity', 'instrument_type_id' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 20, 'company_id' => 2, 'register_code' => '319', 'name' => 'CR SERVICES (CREDIT BUREAU) PLC (PREFERENCE) CLASS B', 'is_default' => true, 'instrument_type' => 'equity', 'instrument_type_id' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('share_classes')->insert([
            ['id' => 100, 'register_id' => 10, 'class_code' => 'ORD', 'name' => 'Ordinary', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 200, 'register_id' => 20, 'class_code' => 'ORD', 'name' => 'Ordinary', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('shareholder_register_accounts')->insert([
            ['id' => 1000, 'register_id' => 10],
            ['id' => 2000, 'register_id' => 20],
        ]);
        DB::table('share_positions')->insert([
            ['id' => 10000, 'sra_id' => 1000, 'share_class_id' => 100],
            ['id' => 20000, 'sra_id' => 2000, 'share_class_id' => 200],
        ]);
        DB::table('fris_migration_batches')->insert([
            ['id' => 25, 'register_code' => 318, 'company_name' => 'CR SERVICES (CREDIT BUREAU) PLC (ORDINARY)', 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 26, 'register_code' => 319, 'company_name' => 'CR SERVICES (CREDIT BUREAU) PLC (PREFERENCE) CLASS B', 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('fris_migration_profiles')->insert([
            ['batch_id' => 25, 'sra_id' => 1000],
            ['batch_id' => 26, 'sra_id' => 2000],
        ]);

        $this->artisan('fris:remediate-structure', ['--apply' => true])->assertSuccessful();

        $companyId = DB::table('companies')->where('issuer_code', 'FRIS-GRP-CR-SERVICES')->value('id');
        $this->assertNotNull($companyId);
        $this->assertSame([$companyId, $companyId], DB::table('registers')->orderBy('id')->pluck('company_id')->all());
        $this->assertDatabaseHas('share_classes', ['id' => 100, 'class_code' => 'ORD']);
        $this->assertDatabaseHas('share_classes', ['id' => 200, 'class_code' => 'PREF-B']);
        $this->assertDatabaseHas('share_positions', ['id' => 10000, 'share_class_id' => 100]);
        $this->assertDatabaseHas('share_positions', ['id' => 20000, 'share_class_id' => 200]);
        $this->assertDatabaseHas('companies', ['id' => 2, 'status' => 'closed']);
    }
}
