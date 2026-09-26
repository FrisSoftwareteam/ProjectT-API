<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Shareholder;
use App\Models\ShareholderIdentity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Identity changes no longer write to shareholder_identities directly (PT-184):
 * every create/update now submits a pending ShareholderChangeRequest instead.
 * The approve/reject/apply mechanics are covered end-to-end in
 * ShareholderUpdateApprovalApiTest; this file only covers submission.
 */
class ShareholderIdentityApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

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

        Schema::create('shareholder_change_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shareholder_id');
            $table->string('request_type');
            $table->json('payload_old');
            $table->json('payload_new');
            $table->string('reason')->nullable();
            $table->string('status')->default('submitted');
            $table->string('control_no', 40);
            $table->unsignedBigInteger('submitted_by');
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('shareholder_change_requests');
        Schema::dropIfExists('shareholder_identities');
        Schema::dropIfExists('shareholders');
        Schema::dropIfExists('admin_users');

        parent::tearDown();
    }

    public function test_identity_create_submits_a_pending_change_request(): void
    {
        $actor = $this->createAdmin('maker@example.com');
        $shareholder = $this->createShareholder('one');

        $this->withoutMiddleware()
            ->actingAs($actor, 'sanctum')
            ->postJson("/api/shareholders/{$shareholder->id}/identities", $this->payload())
            ->assertStatus(202)
            ->assertJsonPath('data.request_type', 'identity_change')
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.payload_new.id_value', '12345678901')
            ->assertJsonPath('data.payload_new.identity_id', null);

        $this->assertDatabaseMissing('shareholder_identities', [
            'shareholder_id' => $shareholder->id,
        ]);
    }

    public function test_identity_update_submits_a_pending_change_request_referencing_the_identity(): void
    {
        $actor = $this->createAdmin('maker@example.com');
        $shareholder = $this->createShareholder('one');
        $identity = $this->createIdentity($shareholder, 'OLD-VALUE');

        $this->withoutMiddleware()
            ->actingAs($actor, 'sanctum')
            ->putJson(
                "/api/shareholders/{$shareholder->id}/identities/{$identity->id}",
                $this->payload(['id_value' => '22222222222'])
            )
            ->assertStatus(202)
            ->assertJsonPath('data.payload_new.identity_id', $identity->id)
            ->assertJsonPath('data.payload_new.id_value', '22222222222')
            ->assertJsonPath('data.payload_old.id_value', 'OLD-VALUE');

        $this->assertDatabaseHas('shareholder_identities', [
            'id' => $identity->id,
            'id_value' => 'OLD-VALUE',
        ]);
    }

    public function test_identity_cannot_be_updated_through_another_shareholder_url(): void
    {
        $owner = $this->createShareholder('owner');
        $other = $this->createShareholder('other');
        $identity = $this->createIdentity($owner, '11111111111');

        $this->withoutMiddleware()
            ->putJson(
                "/api/shareholders/{$other->id}/identities/{$identity->id}",
                $this->payload(['id_value' => '33333333333'])
            )
            ->assertNotFound();

        $this->assertDatabaseHas('shareholder_identities', [
            'id' => $identity->id,
            'shareholder_id' => $owner->id,
            'id_value' => '11111111111',
        ]);
    }

    public static function validIdentificationProvider(): array
    {
        return [
            'nin' => ['nin', '12345678901'],
            'drivers_license' => ['drivers_license', 'ABC123456789'],
            'passport' => ['passport', 'A12345678'],
            'cac_cert with prefix' => ['cac_cert', 'RC123456'],
            'cac_cert without prefix' => ['cac_cert', '12345678'],
            'bvn unrestricted' => ['bvn', 'anything-goes'],
        ];
    }

    #[DataProvider('validIdentificationProvider')]
    public function test_identity_accepts_correctly_formatted_id_value(string $idType, string $idValue): void
    {
        $actor = $this->createAdmin('maker-'.$idType.'@example.com');
        $shareholder = $this->createShareholder('valid-'.$idType);

        $this->withoutMiddleware()
            ->actingAs($actor, 'sanctum')
            ->postJson(
                "/api/shareholders/{$shareholder->id}/identities",
                $this->payload(['id_type' => $idType, 'id_value' => $idValue])
            )
            ->assertStatus(202)
            ->assertJsonPath('data.payload_new.id_value', $idValue);
    }

    public static function invalidIdentificationProvider(): array
    {
        return [
            'nin too short' => ['nin', '1234567890'],
            'nin with letters' => ['nin', '1234567890A'],
            'drivers_license too short' => ['drivers_license', 'ABC12345678'],
            'drivers_license with symbol' => ['drivers_license', 'ABC12345678!'],
            'passport missing letter' => ['passport', '123456789'],
            'passport too many digits' => ['passport', 'A123456789'],
            'cac_cert too short' => ['cac_cert', '1234'],
            'cac_cert too long' => ['cac_cert', '123456789'],
            'cac_cert invalid prefix' => ['cac_cert', 'XX123456'],
        ];
    }

    #[DataProvider('invalidIdentificationProvider')]
    public function test_identity_rejects_incorrectly_formatted_id_value(string $idType, string $idValue): void
    {
        $actor = $this->createAdmin('maker-invalid@example.com');
        $shareholder = $this->createShareholder('invalid-'.$idType.'-'.strlen($idValue));

        $this->withoutMiddleware()
            ->actingAs($actor, 'sanctum')
            ->postJson(
                "/api/shareholders/{$shareholder->id}/identities",
                $this->payload(['id_type' => $idType, 'id_value' => $idValue])
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id_value');
    }

    private function createAdmin(string $email): AdminUser
    {
        return AdminUser::query()->create([
            'email' => $email,
            'first_name' => 'Test',
            'last_name' => 'User',
            'is_active' => true,
        ]);
    }

    private function createShareholder(string $suffix): Shareholder
    {
        return Shareholder::query()->create([
            'account_no' => "ACCOUNT-{$suffix}",
            'holder_type' => 'individual',
            'full_name' => "Shareholder {$suffix}",
            'email' => "{$suffix}@example.com",
            'phone' => "0800{$suffix}",
            'status' => 'active',
        ]);
    }

    private function createIdentity(Shareholder $shareholder, string $value): ShareholderIdentity
    {
        return ShareholderIdentity::query()->create([
            'shareholder_id' => $shareholder->id,
            'id_type' => 'nin',
            'id_value' => $value,
            'verified_status' => 'pending',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'id_type' => 'nin',
            'id_value' => '12345678901',
        ], $overrides);
    }
}
