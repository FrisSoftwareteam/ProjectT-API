<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShareholderStoreWithDetailsIdentityFormatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

        Schema::create('shareholder_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('shareholder_register_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shareholder_id');
            $table->unsignedBigInteger('register_id')->nullable();
            $table->unsignedBigInteger('shareholder_category_id')->nullable();
            $table->timestamps();
        });

        Schema::create('shareholder_cautions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shareholder_id');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('share_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sra_id');
            $table->unsignedBigInteger('share_class_id')->nullable();
            $table->decimal('quantity', 28, 6)->default(0);
            $table->timestamps();
        });

        Schema::create('share_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sra_id');
            $table->unsignedBigInteger('share_class_id')->nullable();
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
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('shareholder_identities');
        Schema::dropIfExists('share_lots');
        Schema::dropIfExists('share_positions');
        Schema::dropIfExists('shareholder_cautions');
        Schema::dropIfExists('shareholder_register_accounts');
        Schema::dropIfExists('shareholder_categories');
        Schema::dropIfExists('shareholder_bank_mandates');
        Schema::dropIfExists('shareholder_addresses');
        Schema::dropIfExists('shareholders');

        parent::tearDown();
    }

    public function test_store_with_details_rejects_a_malformed_identity_value(): void
    {
        $this->withoutMiddleware()
            ->postJson('/api/shareholders/with-details', $this->payload([
                'identities' => [
                    ['id_type' => 'nin', 'id_value' => 'not-a-nin', 'verified_status' => 'pending'],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('identities.0.id_value');
    }

    public function test_store_with_details_accepts_a_correctly_formatted_identity_value(): void
    {
        $this->withoutMiddleware()
            ->postJson('/api/shareholders/with-details', $this->payload([
                'identities' => [
                    ['id_type' => 'nin', 'id_value' => '12345678901', 'verified_status' => 'pending'],
                ],
            ]))
            ->assertCreated();

        $this->assertDatabaseHas('shareholder_identities', [
            'id_type' => 'nin',
            'id_value' => '12345678901',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
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
                    'is_primary' => true,
                ],
            ],
        ], $overrides);
    }
}
