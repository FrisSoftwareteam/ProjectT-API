<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShareholderWithRegisterAndSharesApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable();
            $table->string('register_code')->unique();
            $table->string('name')->nullable();
            $table->string('capital_behaviour_type')->default('variable');
            $table->decimal('paid_up_capital', 28, 6)->nullable();
            $table->decimal('total_units_outstanding', 28, 6)->nullable();
            $table->decimal('remaining_outstanding_units', 28, 6)->nullable();
            $table->string('unit_precision_type')->default('decimal');
            $table->unsignedTinyInteger('decimal_precision')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('share_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('register_id');
            $table->string('class_code');
            $table->string('name')->nullable();
            $table->string('currency')->default('NGN');
            $table->decimal('par_value', 18, 6)->default(0);
            $table->decimal('withholding_tax_rate', 8, 4)->nullable();
            $table->boolean('is_caution_class')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('shareholders', function (Blueprint $table) {
            $table->id();
            $table->string('account_no')->unique();
            $table->string('holder_type');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('full_name');
            $table->string('email')->unique();
            $table->string('phone')->unique();
            $table->date('date_of_birth')->nullable();
            $table->string('sex')->nullable();
            $table->string('rc_number')->nullable();
            $table->string('nin')->nullable();
            $table->string('bvn')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('next_of_kin_name')->nullable();
            $table->string('next_of_kin_phone')->nullable();
            $table->string('next_of_kin_relationship')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('shareholder_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shareholder_id');
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            $table->boolean('is_primary')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();
        });

        Schema::create('shareholder_bank_mandates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shareholder_id');
            $table->string('bank_name');
            $table->string('account_name');
            $table->string('account_number');
            $table->string('bvn')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shareholder_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shareholder_id');
            $table->string('id_type');
            $table->string('id_value');
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('verified_status')->default('pending');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('file_ref')->nullable();
            $table->timestamps();
        });

        Schema::create('shareholder_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable();
            $table->string('default_holder_type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('shareholder_register_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shareholder_id');
            $table->foreignId('register_id');
            $table->foreignId('shareholder_category_id')->nullable();
            $table->string('shareholder_no')->nullable();
            $table->string('chn')->nullable();
            $table->string('cscs_account_no')->nullable();
            $table->string('residency_status')->default('resident');
            $table->string('kyc_level')->default('basic');
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('share_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sra_id');
            $table->foreignId('share_class_id');
            $table->decimal('quantity', 28, 6)->default(0);
            $table->string('holding_mode')->default('demat');
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('share_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sra_id');
            $table->foreignId('share_class_id');
            $table->string('lot_ref')->nullable();
            $table->string('source_type');
            $table->decimal('quantity', 28, 6);
            $table->timestamp('acquired_at')->nullable();
            $table->string('status')->default('open');
            $table->timestamps();
        });

        Schema::create('share_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sra_id');
            $table->foreignId('share_class_id');
            $table->string('tx_type');
            $table->decimal('quantity', 28, 6);
            $table->string('tx_ref')->nullable();
            $table->timestamp('tx_date')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('shareholder_cautions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shareholder_id');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('shareholder_cautions');
        Schema::dropIfExists('share_transactions');
        Schema::dropIfExists('share_lots');
        Schema::dropIfExists('share_positions');
        Schema::dropIfExists('shareholder_register_accounts');
        Schema::dropIfExists('shareholder_categories');
        Schema::dropIfExists('shareholder_identities');
        Schema::dropIfExists('shareholder_bank_mandates');
        Schema::dropIfExists('shareholder_addresses');
        Schema::dropIfExists('shareholders');
        Schema::dropIfExists('share_classes');
        Schema::dropIfExists('registers');
        Schema::dropIfExists('companies');

        parent::tearDown();
    }

    public function test_shareholder_can_be_created_with_register_account_and_initial_shares(): void
    {
        $registerId = $this->createRegister('ACCESS');
        $shareClassId = $this->createShareClass($registerId, 'ORD');

        $this->withoutMiddleware()
            ->postJson('/api/shareholders/with-register-and-shares', $this->payload($registerId, $shareClassId))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.shareholder.email', 'ada@example.com')
            ->assertJsonPath('data.register_account.register_id', $registerId)
            ->assertJsonPath('data.position.share_class_id', $shareClassId)
            ->assertJsonPath('data.position.quantity', '125.500000')
            ->assertJsonPath('data.transaction.tx_type', 'allot')
            ->assertJsonPath('meta.initial_shares_allocated', true)
            ->assertJsonPath('meta.unit_precision.decimal_places', 2);

        $this->assertDatabaseHas('shareholder_register_accounts', [
            'register_id' => $registerId,
            'shareholder_no' => 'SRA-NEW-001',
            'chn' => 'CHN-NEW-001',
        ]);
        $this->assertDatabaseHas('share_positions', [
            'share_class_id' => $shareClassId,
            'quantity' => '125.5',
        ]);
        $this->assertDatabaseHas('share_lots', [
            'share_class_id' => $shareClassId,
            'lot_ref' => 'LOT-001',
        ]);
        $this->assertDatabaseHas('share_transactions', [
            'share_class_id' => $shareClassId,
            'tx_type' => 'allot',
            'tx_ref' => 'LOT-001',
        ]);
    }

    public function test_share_class_must_belong_to_register(): void
    {
        $registerId = $this->createRegister('ACCESS');
        $otherRegisterId = $this->createRegister('ZENITH');
        $shareClassId = $this->createShareClass($otherRegisterId, 'ORD');

        $this->withoutMiddleware()
            ->postJson('/api/shareholders/with-register-and-shares', $this->payload($registerId, $shareClassId))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('share_class_id');

        $this->assertDatabaseCount('shareholders', 0);
    }

    private function payload(int $registerId, int $shareClassId): array
    {
        return [
            'shareholder' => [
                'holder_type' => 'individual',
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.com',
                'phone' => '08000000000',
                'status' => 'active',
            ],
            'addresses' => [
                [
                    'address_line1' => '1 Main Street',
                    'country' => 'Nigeria',
                    'is_primary' => true,
                ],
            ],
            'register_account' => [
                'register_id' => $registerId,
                'shareholder_no' => 'SRA-NEW-001',
                'chn' => 'CHN-NEW-001',
                'cscs_account_no' => 'CSCS-NEW-001',
                'residency_status' => 'resident',
                'kyc_level' => 'basic',
                'status' => 'active',
            ],
            'share_class_id' => $shareClassId,
            'initial_shares' => [
                'quantity' => '125.50',
                'source_type' => 'allotment',
                'lot_ref' => 'LOT-001',
                'acquired_at' => '2026-09-11',
                'holding_mode' => 'demat',
            ],
        ];
    }

    private function createRegister(string $code): int
    {
        return (int) DB::table('registers')->insertGetId([
            'register_code' => $code,
            'name' => "{$code} Register",
            'capital_behaviour_type' => 'variable',
            'unit_precision_type' => 'decimal',
            'decimal_precision' => 2,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createShareClass(int $registerId, string $code): int
    {
        return (int) DB::table('share_classes')->insertGetId([
            'register_id' => $registerId,
            'class_code' => $code,
            'name' => "{$code} Shares",
            'currency' => 'NGN',
            'par_value' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
