<?php

namespace Tests\Feature;

use App\Models\DividendDeclaration;
use App\Models\Register;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RegisterDividendDeclarationsApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('registers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('share_classes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('register_id');
            $table->string('class_code');
            $table->string('name')->nullable();
            $table->string('currency')->nullable();
            $table->decimal('par_value', 18, 6)->nullable();
            $table->decimal('withholding_tax_rate', 8, 4)->nullable();
            $table->boolean('is_caution_class')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('dividend_declarations', function (Blueprint $table) {
            $table->id();
            $table->string('dividend_declaration_no')->nullable();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('register_id');
            $table->string('period_label');
            $table->string('description')->nullable();
            $table->string('initiator')->nullable();
            $table->string('status')->default('DRAFT');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('dividend_declaration_share_classes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dividend_declaration_id');
            $table->unsignedBigInteger('share_class_id');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('dividend_declaration_share_classes');
        Schema::dropIfExists('dividend_declarations');
        Schema::dropIfExists('share_classes');
        Schema::dropIfExists('admin_users');
        Schema::dropIfExists('registers');

        parent::tearDown();
    }

    public function test_it_lists_only_dividends_created_for_the_requested_register(): void
    {
        $register = $this->createRegister('Main');
        $otherRegister = $this->createRegister('Other');
        $this->createDeclaration($register, 'DIV-001', 'DRAFT');
        $this->createDeclaration($register, 'DIV-002', 'APPROVED');
        $this->createDeclaration($otherRegister, 'DIV-003', 'DRAFT');

        $this->withoutMiddleware()
            ->getJson("/api/admin/registers/{$register->id}/dividend-declarations")
            ->assertOk()
            ->assertJsonPath('data.register.id', $register->id)
            ->assertJsonPath('data.declarations.total', 2)
            ->assertJsonMissing(['dividend_declaration_no' => 'DIV-003']);
    }

    public function test_it_filters_register_dividends_by_status_and_search(): void
    {
        $register = $this->createRegister('Main');
        $this->createDeclaration($register, 'DIV-001', 'DRAFT', 'FY 2025');
        $this->createDeclaration($register, 'DIV-002', 'APPROVED', 'FY 2026');

        $this->withoutMiddleware()
            ->getJson("/api/admin/registers/{$register->id}/dividend-declarations?status=APPROVED&search=2026")
            ->assertOk()
            ->assertJsonPath('data.declarations.total', 1)
            ->assertJsonPath('data.declarations.data.0.dividend_declaration_no', 'DIV-002');
    }

    public function test_it_returns_not_found_for_an_unknown_register(): void
    {
        $this->withoutMiddleware()
            ->getJson('/api/admin/registers/999/dividend-declarations')
            ->assertNotFound()
            ->assertJsonPath('message', 'Register not found');
    }

    private function createRegister(string $name): Register
    {
        return Register::query()->create([
            'company_id' => 1,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function createDeclaration(
        Register $register,
        string $number,
        string $status,
        string $period = 'FY 2026'
    ): DividendDeclaration {
        return DividendDeclaration::query()->create([
            'dividend_declaration_no' => $number,
            'company_id' => $register->company_id,
            'register_id' => $register->id,
            'period_label' => $period,
            'description' => "{$period} dividend",
            'initiator' => 'operations',
            'status' => $status,
        ]);
    }
}
