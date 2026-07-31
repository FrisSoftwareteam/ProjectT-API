<?php

namespace App\Services\CompanyDataRelease;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\CompanyDataRelease;
use App\Models\CompanyDataReleaseApproval;
use App\Models\CompanyDataReleaseEvent;
use App\Models\CompanyDataReleaseRecord;
use App\Models\Register;
use App\Models\ShareClass;
use App\Models\ShareholderCategory;
use App\Services\CapitalValidationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CompanyDataReleaseService
{
    public function __construct(
        private readonly CompanyDataReleaseBundleService $bundles,
        private readonly CapitalValidationService $capitalValidation
    ) {}

    public function actor(string $email): AdminUser
    {
        $actor = AdminUser::where('email', $email)->where('is_active', true)->first();
        if (! $actor) {
            throw new RuntimeException("Active production administrator not found: {$email}");
        }

        return $actor;
    }

    public function findRelease(string|int $identifier): CompanyDataRelease
    {
        return CompanyDataRelease::query()
            ->where('id', $identifier)
            ->orWhere('public_id', $identifier)
            ->orWhere('bundle_release_id', $identifier)
            ->firstOrFail();
    }

    public function verify(string $archivePath, string $expectedArtifactSha256, int $actorId, string $comment): CompanyDataRelease
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $expectedArtifactSha256)) {
            throw new RuntimeException('The expected company release SHA-256 is invalid.');
        }
        $archivePath = str_starts_with($archivePath, '/') ? $archivePath : base_path($archivePath);
        $actualArtifactSha256 = is_file($archivePath) ? hash_file('sha256', $archivePath) : null;
        if ($actualArtifactSha256 === null || ! hash_equals($expectedArtifactSha256, $actualArtifactSha256)) {
            throw new RuntimeException('The transferred company release does not match the workstation SHA-256.');
        }

        return $this->bundles->consume($archivePath, function (array $bundle) use ($actorId, $comment): CompanyDataRelease {
            $manifest = $bundle['manifest'];
            $existing = CompanyDataRelease::where('bundle_release_id', $manifest['bundle_release_id'])->first();
            if ($existing) {
                if (! hash_equals($existing->artifact_sha256, $bundle['artifact_sha256'])) {
                    throw new RuntimeException('This bundle release ID is already registered with a different artifact checksum.');
                }

                return $existing;
            }

            [$company, $register, $shareClass] = $this->resolveTarget($manifest);
            $checks = $this->preflight($bundle, $register, $shareClass);
            if (in_array(false, $checks, true)) {
                throw new RuntimeException('Company release production preflight failed: '.json_encode($checks));
            }
            $verification = $bundle['inspection'] + ['checks' => $checks, 'result' => 'PASS'];

            return DB::transaction(function () use ($bundle, $manifest, $company, $register, $shareClass, $actorId, $comment, $verification): CompanyDataRelease {
                $release = CompanyDataRelease::create([
                    'public_id' => (string) Str::uuid(),
                    'bundle_release_id' => $manifest['bundle_release_id'],
                    'format_version' => $manifest['format_version'],
                    'artifact_filename' => basename($bundle['archive_path']),
                    'artifact_sha256' => $bundle['artifact_sha256'],
                    'artifact_size' => $bundle['artifact_size'],
                    'artifact_path' => $bundle['archive_path'],
                    'source_filename' => $manifest['source']['filename'],
                    'source_sha256' => $manifest['source']['sha256'],
                    'approved_snapshot_sha256' => $manifest['source']['approved_snapshot_sha256'],
                    'issuer_code' => $manifest['target']['issuer_code'],
                    'register_code' => $manifest['target']['register_code'],
                    'share_class_code' => $manifest['target']['share_class_code'],
                    'company_id' => $company->id,
                    'register_id' => $register->id,
                    'share_class_id' => $shareClass->id,
                    'status' => CompanyDataRelease::PENDING_APPROVAL,
                    'expected_rows' => $manifest['summary']['rows'],
                    'expected_quantity' => $manifest['summary']['quantity'],
                    'manifest' => $manifest,
                    'verification' => $verification,
                    'verified_by' => $actorId,
                    'verified_at' => now(),
                ]);
                $snapshot = $this->approvalSnapshot($release);
                $release->update(['approval_snapshot_hash' => $snapshot]);
                CompanyDataReleaseApproval::create([
                    'release_id' => $release->id,
                    'decision' => 'SUBMITTED',
                    'actor_id' => $actorId,
                    'comment' => $comment,
                    'snapshot_hash' => $snapshot,
                ]);
                $this->event($release, 'VERIFIED_AND_SUBMITTED', null, CompanyDataRelease::PENDING_APPROVAL, $actorId, $comment, $verification);

                return $release->fresh();
            });
        });
    }

    public function approve(CompanyDataRelease $release, int $actorId, string $comment): CompanyDataRelease
    {
        return DB::transaction(function () use ($release, $actorId, $comment): CompanyDataRelease {
            $release = CompanyDataRelease::lockForUpdate()->findOrFail($release->id);
            if ($release->status !== CompanyDataRelease::PENDING_APPROVAL) {
                throw new RuntimeException("Release approval is not allowed while status is {$release->status}.");
            }
            if ((int) $release->verified_by === $actorId) {
                throw new RuntimeException('The release maker cannot approve their own production bundle.');
            }
            $this->assertArtifactUnchanged($release);
            if (! hash_equals((string) $release->approval_snapshot_hash, $this->approvalSnapshot($release))) {
                throw new RuntimeException('The verified release snapshot changed before approval.');
            }
            $release->update([
                'status' => CompanyDataRelease::APPROVED,
                'approved_by' => $actorId,
                'approved_at' => now(),
            ]);
            CompanyDataReleaseApproval::create([
                'release_id' => $release->id,
                'decision' => 'APPROVED',
                'actor_id' => $actorId,
                'comment' => $comment,
                'snapshot_hash' => $release->approval_snapshot_hash,
            ]);
            $this->event($release, 'APPROVED', CompanyDataRelease::PENDING_APPROVAL, CompanyDataRelease::APPROVED, $actorId, $comment);

            return $release->fresh();
        });
    }

    public function import(CompanyDataRelease $release, int $actorId, bool $resume = false): CompanyDataRelease
    {
        $this->assertArtifactUnchanged($release);
        $release = DB::transaction(function () use ($release, $actorId, $resume): CompanyDataRelease {
            $release = CompanyDataRelease::lockForUpdate()->findOrFail($release->id);
            if ((int) $release->verified_by === $actorId) {
                throw new RuntimeException('The release maker cannot import their own production bundle.');
            }
            if ((int) $release->approved_by !== $actorId) {
                throw new RuntimeException('Only the independent checker who approved the release may import it.');
            }
            if (in_array($release->status, [CompanyDataRelease::IMPORT_FAILED, CompanyDataRelease::IMPORTING], true) && ! $resume) {
                throw new RuntimeException('An interrupted production import requires the explicit --resume option.');
            }
            $allowed = $resume
                ? [CompanyDataRelease::APPROVED, CompanyDataRelease::IMPORT_FAILED, CompanyDataRelease::IMPORTING]
                : [CompanyDataRelease::APPROVED];
            if (! in_array($release->status, $allowed, true)) {
                throw new RuntimeException("Release import is not allowed while status is {$release->status}.");
            }
            $from = $release->status;
            $release->update([
                'status' => CompanyDataRelease::IMPORTING,
                'imported_by' => $actorId,
                'import_started_at' => now(),
                'failure_reason' => null,
            ]);
            $this->event($release, $from === CompanyDataRelease::APPROVED ? 'IMPORT_STARTED' : 'IMPORT_RESUMED', $from, CompanyDataRelease::IMPORTING, $actorId);

            return $release->fresh();
        });

        try {
            $this->bundles->consume($release->artifact_path, function (array $bundle) use ($release): void {
                $this->assertBundleMatchesRelease($release, $bundle);
                $buffer = [];
                foreach ($this->bundles->records($bundle['records_path']) as $record) {
                    $buffer[] = $record;
                    if (count($buffer) >= (int) config('company_data_releases.chunk_size', 1000)) {
                        $this->importChunk($release, collect($buffer));
                        $buffer = [];
                    }
                }
                if ($buffer !== []) {
                    $this->importChunk($release, collect($buffer));
                }
            });

            $reconciliation = $this->reconcile($release);
            if ($reconciliation['result'] !== 'PASS') {
                throw new RuntimeException('Post-import production reconciliation failed.');
            }
            $this->capitalValidation->syncOutstandingUnits((int) $release->register_id);
            $this->capitalValidation->assertConstantBalanced((int) $release->register_id);
            $release->update([
                'status' => CompanyDataRelease::IMPORTED,
                'imported_rows' => $reconciliation['imported_rows'],
                'imported_quantity' => $reconciliation['imported_quantity'],
                'reconciliation' => $reconciliation,
                'imported_at' => now(),
                'failure_reason' => null,
            ]);
            $this->event($release, 'IMPORTED', CompanyDataRelease::IMPORTING, CompanyDataRelease::IMPORTED, $actorId, null, $reconciliation);

            return $release->fresh();
        } catch (Throwable $exception) {
            $importedRows = CompanyDataReleaseRecord::where('release_id', $release->id)->where('status', 'IMPORTED')->count();
            $release->update([
                'status' => CompanyDataRelease::IMPORT_FAILED,
                'imported_rows' => $importedRows,
                'failure_reason' => $exception->getMessage(),
            ]);
            $this->event($release, 'IMPORT_FAILED', CompanyDataRelease::IMPORTING, CompanyDataRelease::IMPORT_FAILED, $actorId, null, ['imported_rows' => $importedRows]);
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function reconcile(CompanyDataRelease $release): array
    {
        $records = DB::table('company_data_release_records')->where('release_id', $release->id)->where('status', 'IMPORTED');
        $importedRows = (clone $records)->count();
        $importedQuantity = FixedScaleDecimal::normalize((string) (clone $records)->sum('quantity'));
        $missingLinks = DB::table('company_data_release_records as r')
            ->leftJoin('shareholders as s', 's.id', '=', 'r.shareholder_id')
            ->leftJoin('shareholder_addresses as a', 'a.id', '=', 'r.address_id')
            ->leftJoin('shareholder_register_accounts as ra', 'ra.id', '=', 'r.sra_id')
            ->leftJoin('share_positions as p', 'p.id', '=', 'r.position_id')
            ->where('r.release_id', $release->id)
            ->where('r.status', 'IMPORTED')
            ->where(function ($query): void {
                $query->whereNull('s.id')->orWhereNull('a.id')->orWhereNull('ra.id')->orWhereNull('p.id');
            })->count();
        $unsafeContacts = DB::table('company_data_release_records as r')
            ->join('shareholders as s', 's.id', '=', 'r.shareholder_id')
            ->where('r.release_id', $release->id)
            ->where('r.status', 'IMPORTED')
            ->where(function ($query): void {
                $query->where('s.email_is_verified', true)
                    ->orWhere('s.phone_is_verified', true)
                    ->orWhere('s.contact_suppressed', false);
            })->count();
        $positionRows = DB::table('company_data_release_records as r')
            ->join('share_positions as p', 'p.id', '=', 'r.position_id')
            ->where('r.release_id', $release->id)->where('r.status', 'IMPORTED')->count();
        $positionQuantity = FixedScaleDecimal::normalize((string) DB::table('company_data_release_records as r')
            ->join('share_positions as p', 'p.id', '=', 'r.position_id')
            ->where('r.release_id', $release->id)->where('r.status', 'IMPORTED')->sum('p.quantity'));
        $checks = [
            'row_count_matches' => $importedRows === (int) $release->expected_rows,
            'ledger_quantity_matches' => FixedScaleDecimal::equals($importedQuantity, (string) $release->expected_quantity),
            'position_row_count_matches' => $positionRows === (int) $release->expected_rows,
            'position_quantity_matches' => FixedScaleDecimal::equals($positionQuantity, (string) $release->expected_quantity),
            'all_lineage_links_exist' => $missingLinks === 0,
            'all_contacts_suppressed_and_unverified' => $unsafeContacts === 0,
        ];

        return [
            'expected_rows' => (int) $release->expected_rows,
            'imported_rows' => $importedRows,
            'expected_quantity' => FixedScaleDecimal::normalize((string) $release->expected_quantity),
            'imported_quantity' => $importedQuantity,
            'position_rows' => $positionRows,
            'position_quantity' => $positionQuantity,
            'missing_lineage_links' => $missingLinks,
            'unsafe_contacts' => $unsafeContacts,
            'checks' => $checks,
            'result' => in_array(false, $checks, true) ? 'FAIL' : 'PASS',
        ];
    }

    public function rollback(CompanyDataRelease $release, int $actorId, string $comment): CompanyDataRelease
    {
        $release = DB::transaction(function () use ($release, $actorId, $comment): CompanyDataRelease {
            $release = CompanyDataRelease::lockForUpdate()->findOrFail($release->id);
            if (! in_array($release->status, [CompanyDataRelease::IMPORTED, CompanyDataRelease::ROLLBACK_BLOCKED, CompanyDataRelease::IMPORT_FAILED], true)) {
                throw new RuntimeException("Release rollback is not allowed while status is {$release->status}.");
            }
            $from = $release->status;
            $release->update([
                'status' => CompanyDataRelease::ROLLING_BACK,
                'rolled_back_by' => $actorId,
                'rollback_started_at' => now(),
                'failure_reason' => null,
            ]);
            $this->event($release, 'ROLLBACK_STARTED', $from, CompanyDataRelease::ROLLING_BACK, $actorId, $comment);

            return $release->fresh();
        });

        try {
            $this->assertNoDownstreamActivity($release);
            CompanyDataReleaseRecord::where('release_id', $release->id)->where('status', 'IMPORTED')
                ->chunkByIdDesc((int) config('company_data_releases.chunk_size', 1000), function (Collection $records): void {
                    $this->rollbackChunk($records);
                });
            $rolledBackRows = CompanyDataReleaseRecord::where('release_id', $release->id)->where('status', 'ROLLED_BACK')->count();
            $this->capitalValidation->syncOutstandingUnits((int) $release->register_id);
            $release->update([
                'status' => CompanyDataRelease::ROLLED_BACK,
                'rolled_back_rows' => $rolledBackRows,
                'rolled_back_at' => now(),
                'failure_reason' => null,
            ]);
            $this->event($release, 'ROLLED_BACK', CompanyDataRelease::ROLLING_BACK, CompanyDataRelease::ROLLED_BACK, $actorId, $comment, ['rows' => $rolledBackRows]);

            return $release->fresh();
        } catch (Throwable $exception) {
            $release->update(['status' => CompanyDataRelease::ROLLBACK_BLOCKED, 'failure_reason' => $exception->getMessage()]);
            $this->event($release, 'ROLLBACK_BLOCKED', CompanyDataRelease::ROLLING_BACK, CompanyDataRelease::ROLLBACK_BLOCKED, $actorId, $comment);
            throw $exception;
        }
    }

    /** @param array<string,mixed> $manifest @return array{0:Company,1:Register,2:ShareClass} */
    private function resolveTarget(array $manifest): array
    {
        $company = Company::where('issuer_code', $manifest['target']['issuer_code'])->where('status', 'active')->first();
        if (! $company) {
            throw new RuntimeException('Active production company does not match the bundle issuer code.');
        }
        $register = Register::where('company_id', $company->id)->where('register_code', $manifest['target']['register_code'])->where('status', 'active')->first();
        if (! $register) {
            throw new RuntimeException('Active production register does not match the bundle register code.');
        }
        $shareClass = ShareClass::where('register_id', $register->id)->where('class_code', $manifest['target']['share_class_code'])->first();
        if (! $shareClass) {
            throw new RuntimeException('Production share class does not match the bundle share-class code.');
        }

        return [$company, $register, $shareClass];
    }

    /** @param array<string,mixed> $bundle @return array<string,bool> */
    private function preflight(array $bundle, Register $register, ShareClass $shareClass): array
    {
        $manifest = $bundle['manifest'];
        $categoryCodes = array_keys($manifest['summary']['categories']);
        $categoriesExist = ShareholderCategory::whereIn('code', $categoryCodes)->where('is_active', true)->count() === count($categoryCodes);
        $collisions = ['account_no' => 0, 'email' => 0, 'phone' => 0];
        $buffer = [];
        foreach ($this->bundles->records($bundle['records_path']) as $record) {
            $buffer[] = $record;
            if (count($buffer) >= (int) config('company_data_releases.chunk_size', 1000)) {
                $this->countCollisions(collect($buffer), $collisions);
                $buffer = [];
            }
        }
        if ($buffer !== []) {
            $this->countCollisions(collect($buffer), $collisions);
        }
        $targetEmpty = DB::table('share_positions')->where('share_class_id', $shareClass->id)->doesntExist();
        $capitalMatches = $register->capital_behaviour_type !== 'constant'
            || FixedScaleDecimal::equals((string) ($register->paid_up_capital ?? 0), $manifest['summary']['quantity']);
        $noActiveRelease = CompanyDataRelease::where('share_class_id', $shareClass->id)
            ->whereIn('status', [CompanyDataRelease::PENDING_APPROVAL, CompanyDataRelease::APPROVED, CompanyDataRelease::IMPORTING, CompanyDataRelease::IMPORTED])
            ->doesntExist();

        return [
            'artifact_checksum_verified' => true,
            'record_stream_matches_manifest' => true,
            'all_categories_exist' => $categoriesExist,
            'target_identifiers_available' => array_sum($collisions) === 0,
            'target_share_class_is_empty' => $targetEmpty,
            'constant_capital_matches_expected_units' => $capitalMatches,
            'no_active_release_for_share_class' => $noActiveRelease,
        ];
    }

    /** @param array{account_no:int,email:int,phone:int} $collisions */
    private function countCollisions(Collection $records, array &$collisions): void
    {
        $collisions['account_no'] += DB::table('shareholders')->whereIn('account_no', $records->pluck('target_account_no'))->count();
        $collisions['email'] += DB::table('shareholders')->whereIn('email', $records->pluck('target_email'))->count();
        $collisions['phone'] += DB::table('shareholders')->whereIn('phone', $records->pluck('target_phone'))->count();
    }

    private function importChunk(CompanyDataRelease $release, Collection $records): void
    {
        DB::transaction(function () use ($release, $records): void {
            $existing = CompanyDataReleaseRecord::where('release_id', $release->id)
                ->whereIn('idempotency_key', $records->pluck('idempotency_key'))
                ->get()->keyBy('idempotency_key');
            foreach ($existing as $record) {
                if ($record->status !== 'IMPORTED') {
                    throw new RuntimeException('A non-imported idempotency record blocks safe resume.');
                }
            }
            $records = $records->reject(fn (array $record): bool => $existing->has($record['idempotency_key']))->values();
            if ($records->isEmpty()) {
                return;
            }
            $this->assertNoTargetCollisions($records);
            $categories = ShareholderCategory::whereIn('code', $records->pluck('category_code')->unique())
                ->where('is_active', true)->pluck('id', 'code');
            if ($categories->count() !== $records->pluck('category_code')->unique()->count()) {
                throw new RuntimeException('A required shareholder category is missing or inactive.');
            }

            $now = now();
            DB::table('shareholders')->insert($records->map(fn (array $record): array => [
                'account_no' => $record['target_account_no'],
                'holder_type' => $record['holder_type'],
                'full_name' => $record['full_name'],
                'email' => $record['target_email'],
                'email_is_verified' => false,
                'phone' => $record['target_phone'],
                'phone_is_verified' => false,
                'contact_suppressed' => true,
                'status' => $record['status'],
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
            $shareholders = DB::table('shareholders')->whereIn('account_no', $records->pluck('target_account_no'))->pluck('id', 'account_no');

            DB::table('shareholder_addresses')->insert($records->map(fn (array $record): array => [
                'shareholder_id' => $shareholders[$record['target_account_no']],
                'address_line1' => $record['address_line1'],
                'state' => $record['state'],
                'country' => $record['country'],
                'is_primary' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
            $addresses = DB::table('shareholder_addresses')->whereIn('shareholder_id', $shareholders->values())->pluck('id', 'shareholder_id');

            DB::table('shareholder_register_accounts')->insert($records->map(fn (array $record): array => [
                'shareholder_id' => $shareholders[$record['target_account_no']],
                'register_id' => $release->register_id,
                'shareholder_category_id' => $categories[$record['category_code']],
                'shareholder_no' => $record['source_account_number'],
                'kyc_level' => 'basic',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
            $sras = DB::table('shareholder_register_accounts')->where('register_id', $release->register_id)
                ->whereIn('shareholder_id', $shareholders->values())->pluck('id', 'shareholder_id');

            DB::table('share_positions')->insert($records->map(function (array $record) use ($release, $shareholders, $sras, $now): array {
                $shareholderId = $shareholders[$record['target_account_no']];

                return [
                    'sra_id' => $sras[$shareholderId],
                    'share_class_id' => $release->share_class_id,
                    'quantity' => $record['quantity'],
                    'holding_mode' => $record['holding_mode'],
                    'last_updated_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->all());
            $positions = DB::table('share_positions')->where('share_class_id', $release->share_class_id)
                ->whereIn('sra_id', $sras->values())->pluck('id', 'sra_id');

            DB::table('company_data_release_records')->insert($records->map(function (array $record) use ($release, $shareholders, $addresses, $sras, $positions, $now): array {
                $shareholderId = $shareholders[$record['target_account_no']];
                $sraId = $sras[$shareholderId];

                return [
                    'release_id' => $release->id,
                    'source_row_number' => $record['source_row_number'],
                    'idempotency_key' => $record['idempotency_key'],
                    'row_hash' => $record['payload_sha256'],
                    'source_account_number' => $record['source_account_number'],
                    'category_code' => $record['category_code'],
                    'holder_type' => $record['holder_type'],
                    'quantity' => $record['quantity'],
                    'holding_mode' => $record['holding_mode'],
                    'status' => 'IMPORTED',
                    'shareholder_id' => $shareholderId,
                    'address_id' => $addresses[$shareholderId],
                    'sra_id' => $sraId,
                    'position_id' => $positions[$sraId],
                    'imported_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->all());
        });
    }

    private function assertNoTargetCollisions(Collection $records): void
    {
        $collision = DB::table('shareholders')->whereIn('account_no', $records->pluck('target_account_no'))
            ->orWhereIn('email', $records->pluck('target_email'))
            ->orWhereIn('phone', $records->pluck('target_phone'))->exists();
        if ($collision) {
            throw new RuntimeException('A production target shareholder identifier collision was detected.');
        }
    }

    private function rollbackChunk(Collection $records): void
    {
        DB::transaction(function () use ($records): void {
            $locked = CompanyDataReleaseRecord::whereIn('id', $records->pluck('id'))->lockForUpdate()->where('status', 'IMPORTED')->get();
            DB::table('share_positions')->whereIn('id', $locked->pluck('position_id'))->delete();
            DB::table('shareholder_addresses')->whereIn('id', $locked->pluck('address_id'))->delete();
            DB::table('shareholder_register_accounts')->whereIn('id', $locked->pluck('sra_id'))->delete();
            DB::table('shareholders')->whereIn('id', $locked->pluck('shareholder_id'))->delete();
            CompanyDataReleaseRecord::whereIn('id', $locked->pluck('id'))->update([
                'status' => 'ROLLED_BACK',
                'rolled_back_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function assertNoDownstreamActivity(CompanyDataRelease $release): void
    {
        $changedImportedData = DB::table('company_data_release_records as r')
            ->join('shareholders as s', 's.id', '=', 'r.shareholder_id')
            ->join('shareholder_addresses as a', 'a.id', '=', 'r.address_id')
            ->join('shareholder_register_accounts as ra', 'ra.id', '=', 'r.sra_id')
            ->join('share_positions as p', 'p.id', '=', 'r.position_id')
            ->where('r.release_id', $release->id)
            ->where('r.status', 'IMPORTED')
            ->where(function ($query): void {
                $query->whereColumn('s.updated_at', '!=', 'r.imported_at')
                    ->orWhereColumn('a.updated_at', '!=', 'r.imported_at')
                    ->orWhereColumn('ra.updated_at', '!=', 'r.imported_at')
                    ->orWhereColumn('p.updated_at', '!=', 'r.imported_at');
            })
            ->exists();
        if ($changedImportedData) {
            throw new RuntimeException('Rollback is unsafe because imported shareholder data was changed after release import.');
        }

        $sraReferences = [
            'share_transactions' => ['sra_id'], 'share_lots' => ['sra_id'], 'sra_guardians' => ['sra_id'],
            'sra_joint_holders' => ['sra_id'], 'sra_proxies' => ['sra_id'], 'shareholder_cautions' => ['sra_id'],
            'probate_beneficiaries' => ['sra_id'], 'share_transfer_events' => ['from_sra_id', 'to_sra_id'],
            'cscs_upload_rows' => ['sra_id', 'proposed_sra_id'], 'sra_external_identifiers' => ['sra_id'],
            'dividend_entitlements' => ['register_account_id', 'sra_id'],
        ];
        $shareholderReferences = [
            'shareholder_identities' => ['shareholder_id'], 'shareholder_bank_mandates' => ['shareholder_id'],
            'probate_cases' => ['shareholder_id'], 'ipo_offer_allotments' => ['shareholder_id'],
            'probate_beneficiaries' => ['beneficiary_shareholder_id'], 'shareholder_cautions' => ['shareholder_id'],
            'shareholder_caution_logs' => ['shareholder_id'], 'share_transfer_events' => ['from_shareholder_id', 'to_shareholder_id'],
            'shareholder_merge_events' => ['primary_shareholder_id', 'duplicate_shareholder_id'],
            'sra_guardians' => ['guardian_shareholder_id'], 'estate_case_representatives' => ['representative_shareholder_id'],
        ];
        CompanyDataReleaseRecord::where('release_id', $release->id)->where('status', 'IMPORTED')->orderBy('id')
            ->chunkById(1000, function (Collection $records) use ($sraReferences, $shareholderReferences): void {
                $this->assertNoReferences($sraReferences, $records->pluck('sra_id')->filter()->values(), 'register-account');
                $this->assertNoReferences($shareholderReferences, $records->pluck('shareholder_id')->filter()->values(), 'shareholder');
            });
    }

    /** @param array<string,array<int,string>> $references */
    private function assertNoReferences(array $references, Collection $ids, string $kind): void
    {
        foreach ($references as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column) && DB::table($table)->whereIn($column, $ids)->exists()) {
                    throw new RuntimeException("Rollback is unsafe because {$table}.{$column} contains downstream {$kind} activity.");
                }
            }
        }
    }

    /** @param array<string,mixed> $bundle */
    private function assertBundleMatchesRelease(CompanyDataRelease $release, array $bundle): void
    {
        if (! hash_equals($release->artifact_sha256, $bundle['artifact_sha256'])
            || ! hash_equals($release->bundle_release_id, $bundle['manifest']['bundle_release_id'])
            || $release->manifest !== $bundle['manifest']
            || ! hash_equals((string) $release->approval_snapshot_hash, $this->approvalSnapshot($release))) {
            throw new RuntimeException('The approved production bundle changed before import.');
        }
    }

    private function assertArtifactUnchanged(CompanyDataRelease $release): void
    {
        if (! is_file($release->artifact_path)
            || filesize($release->artifact_path) !== (int) $release->artifact_size
            || ! hash_equals($release->artifact_sha256, hash_file('sha256', $release->artifact_path))) {
            throw new RuntimeException('The verified production bundle is missing or changed.');
        }
    }

    private function approvalSnapshot(CompanyDataRelease $release): string
    {
        return hash('sha256', $release->artifact_sha256.'|'.json_encode($release->manifest).'|'.json_encode($release->verification));
    }

    /** @param array<string,mixed>|null $metadata */
    private function event(CompanyDataRelease $release, string $type, ?string $from, ?string $to, ?int $actorId = null, ?string $comment = null, ?array $metadata = null): void
    {
        CompanyDataReleaseEvent::create([
            'release_id' => $release->id,
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actorId,
            'comment' => $comment,
            'metadata' => $metadata,
        ]);
    }
}
