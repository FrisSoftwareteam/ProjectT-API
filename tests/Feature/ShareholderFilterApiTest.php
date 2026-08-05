<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShareholderFilterApiTest extends TestCase
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
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('registers', function (Blueprint $table) {
            $table->id();
            $table->string('register_code')->unique();
            $table->timestamps();
        });

        Schema::create('share_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('register_id');
            $table->string('class_code');
            $table->timestamps();
        });

        Schema::create('shareholder_categories', function (Blueprint $table) {
            $table->id();
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
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('share_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sra_id');
            $table->foreignId('share_class_id');
            $table->decimal('quantity', 28, 6)->default(0);
            $table->string('holding_mode')->default('demat');
            $table->timestamps();
        });

        Schema::create('shareholder_cautions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shareholder_id');
            $table->timestamp('removed_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('shareholder_cautions');
        Schema::dropIfExists('share_positions');
        Schema::dropIfExists('shareholder_register_accounts');
        Schema::dropIfExists('shareholder_categories');
        Schema::dropIfExists('share_classes');
        Schema::dropIfExists('registers');
        Schema::dropIfExists('shareholders');

        parent::tearDown();
    }

    public function test_shareholders_can_be_filtered_by_register(): void
    {
        [$firstRegister, $secondRegister] = $this->createRegisters();
        $first = $this->createShareholder('first');
        $second = $this->createShareholder('second');
        $this->createRegisterAccount($first, $firstRegister);
        $this->createRegisterAccount($second, $secondRegister);

        $this->withoutMiddleware()
            ->getJson("/api/shareholders?register_id={$firstRegister}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $first)
            ->assertJsonPath('data.0.total_holdings', '0.000000');
    }

    public function test_shareholders_can_be_filtered_by_share_class(): void
    {
        [$firstRegister, $secondRegister] = $this->createRegisters();
        $firstClass = $this->createShareClass($firstRegister, 'ORD');
        $secondClass = $this->createShareClass($secondRegister, 'PREF');
        $first = $this->createShareholder('first');
        $second = $this->createShareholder('second');
        $firstAccount = $this->createRegisterAccount($first, $firstRegister);
        $secondAccount = $this->createRegisterAccount($second, $secondRegister);
        $this->createPosition($firstAccount, $firstClass);
        $this->createPosition($secondAccount, $secondClass);

        $this->withoutMiddleware()
            ->getJson("/api/shareholders?share_class_id={$firstClass}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $first);
    }

    public function test_combined_filters_must_match_the_same_register_account(): void
    {
        [$firstRegister, $secondRegister] = $this->createRegisters();
        $secondClass = $this->createShareClass($secondRegister, 'PREF');
        $shareholder = $this->createShareholder('cross-register');
        $this->createRegisterAccount($shareholder, $firstRegister);
        $secondAccount = $this->createRegisterAccount($shareholder, $secondRegister);
        $this->createPosition($secondAccount, $secondClass);

        $this->withoutMiddleware()
            ->getJson("/api/shareholders?register_id={$firstRegister}&share_class_id={$secondClass}")
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_total_holdings_respects_register_and_share_class_filters(): void
    {
        [$firstRegister, $secondRegister] = $this->createRegisters();
        $ordinaryClass = $this->createShareClass($firstRegister, 'ORD');
        $preferenceClass = $this->createShareClass($firstRegister, 'PREF');
        $otherRegisterClass = $this->createShareClass($secondRegister, 'OTHER');
        $shareholder = $this->createShareholder('holdings-total');
        $firstAccount = $this->createRegisterAccount($shareholder, $firstRegister);
        $secondAccount = $this->createRegisterAccount($shareholder, $secondRegister);
        $this->createPosition($firstAccount, $ordinaryClass, 125);
        $this->createPosition($firstAccount, $preferenceClass, 75);
        $this->createPosition($secondAccount, $otherRegisterClass, 500);

        $this->withoutMiddleware()
            ->getJson("/api/shareholders?register_id={$firstRegister}&share_class_id={$ordinaryClass}")
            ->assertOk()
            ->assertJsonPath('data.0.total_holdings', '125.000000');

        $this->withoutMiddleware()
            ->getJson("/api/shareholders?register_id={$firstRegister}")
            ->assertOk()
            ->assertJsonPath('data.0.total_holdings', '200.000000');

        $this->withoutMiddleware()
            ->getJson('/api/shareholders')
            ->assertOk()
            ->assertJsonPath('data.0.total_holdings', '700.000000');
    }

    public function test_filter_ids_are_validated(): void
    {
        $this->withoutMiddleware()
            ->getJson('/api/shareholders?register_id=999&share_class_id=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['register_id', 'share_class_id']);
    }

    private function createRegisters(): array
    {
        return [
            DB::table('registers')->insertGetId(['register_code' => 'REG-1']),
            DB::table('registers')->insertGetId(['register_code' => 'REG-2']),
        ];
    }

    private function createShareholder(string $suffix): int
    {
        return DB::table('shareholders')->insertGetId([
            'account_no' => "ACCOUNT-{$suffix}",
            'holder_type' => 'individual',
            'first_name' => ucfirst($suffix),
            'full_name' => "Shareholder {$suffix}",
            'email' => "{$suffix}@example.com",
            'phone' => "0800-{$suffix}",
            'status' => 'active',
        ]);
    }

    private function createShareClass(int $registerId, string $code): int
    {
        return DB::table('share_classes')->insertGetId([
            'register_id' => $registerId,
            'class_code' => $code,
        ]);
    }

    private function createRegisterAccount(int $shareholderId, int $registerId): int
    {
        return DB::table('shareholder_register_accounts')->insertGetId([
            'shareholder_id' => $shareholderId,
            'register_id' => $registerId,
            'status' => 'active',
        ]);
    }

    private function createPosition(int $accountId, int $shareClassId, int|float $quantity = 100): void
    {
        DB::table('share_positions')->insert([
            'sra_id' => $accountId,
            'share_class_id' => $shareClassId,
            'quantity' => $quantity,
            'holding_mode' => 'demat',
        ]);
    }
}
