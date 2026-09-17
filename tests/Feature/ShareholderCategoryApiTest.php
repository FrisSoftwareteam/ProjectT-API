<?php

namespace Tests\Feature;

use App\Models\Shareholder;
use App\Models\ShareholderCategory;
use App\Models\ShareholderRegisterAccount;
use Database\Seeders\ShareholderCategorySeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShareholderCategoryApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('shareholders', function (Blueprint $table) {
            $table->id();
            $table->string('account_no')->unique();
            $table->string('holder_type');
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

        Schema::create('shareholder_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 150);
            $table->string('default_holder_type')->nullable();
            $table->boolean('requires_joint_holders')->default(false);
            $table->boolean('requires_review')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('source_system')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('shareholder_register_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shareholder_id');
            $table->foreignId('register_id');
            $table->foreignId('shareholder_category_id')->nullable();
            $table->string('shareholder_no')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('shareholder_register_accounts');
        Schema::dropIfExists('shareholder_categories');
        Schema::dropIfExists('registers');
        Schema::dropIfExists('shareholders');

        parent::tearDown();
    }

    public function test_category_can_be_created_and_code_is_normalized(): void
    {
        $this->withoutMiddleware()
            ->postJson('/api/shareholder-categories', [
                'code' => 'v',
                'name' => 'Foreign Shareholders',
                'default_holder_type' => null,
                'requires_review' => true,
                'source_system' => 'ESTOCK',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'V')
            ->assertJsonPath('data.requires_review', true);

        $this->assertDatabaseHas('shareholder_categories', [
            'code' => 'V',
            'name' => 'Foreign Shareholders',
        ]);
    }

    public function test_estock_category_seeder_is_complete_and_idempotent(): void
    {
        $this->seed(ShareholderCategorySeeder::class);
        $this->seed(ShareholderCategorySeeder::class);

        $this->assertDatabaseCount('shareholder_categories', 18);
        $this->assertDatabaseHas('shareholder_categories', [
            'code' => 'Z',
            'name' => 'AMCON',
            'default_holder_type' => 'corporate',
        ]);
        $this->assertDatabaseHas('shareholder_categories', [
            'code' => 'V',
            'name' => 'FOREIGN SHAREHOLDERS',
            'default_holder_type' => null,
            'requires_review' => true,
        ]);
    }

    public function test_compatible_category_can_be_assigned_to_register_account(): void
    {
        $category = $this->category('A', 'Individual', 'individual');
        $sra = $this->registerAccount('individual');

        $this->withoutMiddleware()
            ->patchJson("/api/shareholder-register-accounts/{$sra->id}/category", [
                'shareholder_category_id' => $category->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.shareholder_category_id', $category->id)
            ->assertJsonPath('data.category.code', 'A');
    }

    public function test_incompatible_category_is_rejected(): void
    {
        $category = $this->category('C', 'Corporate Body', 'corporate');
        $sra = $this->registerAccount('individual');

        $this->withoutMiddleware()
            ->patchJson("/api/shareholder-register-accounts/{$sra->id}/category", [
                'shareholder_category_id' => $category->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shareholder_category_id');

        $this->assertNull($sra->fresh()->shareholder_category_id);
    }

    public function test_review_category_can_use_the_existing_holder_type(): void
    {
        $category = $this->category('V', 'Foreign Shareholders', null, true);
        $sra = $this->registerAccount('corporate');

        $this->withoutMiddleware()
            ->patchJson("/api/shareholder-register-accounts/{$sra->id}/category", [
                'shareholder_category_id' => $category->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.category.requires_review', true);
    }

    public function test_category_in_use_cannot_be_archived(): void
    {
        $category = $this->category('A', 'Individual', 'individual');
        $sra = $this->registerAccount('individual');
        $sra->update(['shareholder_category_id' => $category->id]);

        $this->withoutMiddleware()
            ->deleteJson("/api/shareholder-categories/{$category->id}")
            ->assertConflict();

        $this->assertNull($category->fresh()->deleted_at);
    }

    public function test_in_use_category_cannot_change_to_a_conflicting_default_type(): void
    {
        $category = $this->category('V', 'FOREIGN SHAREHOLDERS', null, true);
        $sra = $this->registerAccount('individual');
        $sra->update(['shareholder_category_id' => $category->id]);

        $this->withoutMiddleware()
            ->patchJson("/api/shareholder-categories/{$category->id}", [
                'default_holder_type' => 'corporate',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('default_holder_type');

        $this->assertNull($category->fresh()->default_holder_type);
    }

    private function category(
        string $code,
        string $name,
        ?string $holderType,
        bool $requiresReview = false
    ): ShareholderCategory {
        return ShareholderCategory::query()->create([
            'code' => $code,
            'name' => $name,
            'default_holder_type' => $holderType,
            'requires_review' => $requiresReview,
            'is_active' => true,
            'source_system' => 'ESTOCK',
        ]);
    }

    private function registerAccount(string $holderType): ShareholderRegisterAccount
    {
        $suffix = str()->random(8);
        $shareholder = Shareholder::query()->create([
            'account_no' => "ACC-{$suffix}",
            'holder_type' => $holderType,
            'full_name' => "Holder {$suffix}",
            'email' => "{$suffix}@example.com",
            'phone' => "080{$suffix}",
            'status' => 'active',
        ]);
        $registerId = \Illuminate\Support\Facades\DB::table('registers')->insertGetId([
            'register_code' => "REG-{$suffix}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ShareholderRegisterAccount::query()->create([
            'shareholder_id' => $shareholder->id,
            'register_id' => $registerId,
            'shareholder_no' => "SH-{$suffix}",
            'status' => 'active',
        ]);
    }
}
