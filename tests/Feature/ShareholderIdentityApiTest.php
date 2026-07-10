<?php

namespace Tests\Feature;

use App\Models\Shareholder;
use App\Models\ShareholderIdentity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShareholderIdentityApiTest extends TestCase
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
        Schema::dropIfExists('shareholders');

        parent::tearDown();
    }

    public function test_identity_can_be_created_without_shareholder_id_in_payload(): void
    {
        $shareholder = $this->createShareholder('one');

        $this->withoutMiddleware()
            ->postJson("/api/shareholders/{$shareholder->id}/identities", $this->payload())
            ->assertOk()
            ->assertJsonPath('shareholder_id', $shareholder->id);

        $this->assertDatabaseHas('shareholder_identities', [
            'shareholder_id' => $shareholder->id,
            'id_value' => '12345678901',
        ]);
    }

    public function test_identity_update_uses_identity_id_and_preserves_ownership(): void
    {
        $shareholder = $this->createShareholder('one');
        $identity = $this->createIdentity($shareholder, 'OLD-VALUE');

        $this->withoutMiddleware()
            ->putJson(
                "/api/shareholders/{$shareholder->id}/identities/{$identity->id}",
                $this->payload(['id_value' => 'NEW-VALUE'])
            )
            ->assertOk()
            ->assertJsonPath('id_value', 'NEW-VALUE')
            ->assertJsonPath('shareholder_id', $shareholder->id);
    }

    public function test_identity_cannot_be_updated_through_another_shareholder_url(): void
    {
        $owner = $this->createShareholder('owner');
        $other = $this->createShareholder('other');
        $identity = $this->createIdentity($owner, 'OWNER-VALUE');

        $this->withoutMiddleware()
            ->putJson(
                "/api/shareholders/{$other->id}/identities/{$identity->id}",
                $this->payload(['id_value' => 'CHANGED'])
            )
            ->assertNotFound();

        $this->assertDatabaseHas('shareholder_identities', [
            'id' => $identity->id,
            'shareholder_id' => $owner->id,
            'id_value' => 'OWNER-VALUE',
        ]);
    }

    public function test_legacy_payload_shareholder_id_must_match_url(): void
    {
        $shareholder = $this->createShareholder('one');
        $other = $this->createShareholder('other');

        $this->withoutMiddleware()
            ->postJson(
                "/api/shareholders/{$shareholder->id}/identities",
                $this->payload(['shareholder_id' => $other->id])
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shareholder_id');
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
            'verified_status' => 'pending',
        ], $overrides);
    }
}
