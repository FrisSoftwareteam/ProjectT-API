<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\DividendApprovalAction;
use App\Models\DividendApprovalDelegation;
use App\Models\DividendDeclaration;
use App\Models\DividendEntitlement;
use App\Models\DividendEntitlementRun;
use App\Models\DividendPayment;
use App\Models\DividendWorkflowEvent;
use App\Models\Register;
use App\Models\ShareClass;
use App\Models\ShareholderMandate;
use App\Models\ShareholderRegisterAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DividendSeeder extends Seeder
{
    public function run(): void
    {
        if (!$this->hasRequiredTables()) {
            $this->command?->warn('Skipping DividendSeeder: required dividend tables are missing.');
            return;
        }

        $actorIds = AdminUser::query()->pluck('id')->values();
        if ($actorIds->isEmpty()) {
            $this->command?->warn('Skipping DividendSeeder: no admin users found.');
            return;
        }

        $actorId = (int) $actorIds->first();
        $secondActorId = (int) ($actorIds->get(1) ?? $actorId);

        $register = Register::query()->first();
        if (!$register) {
            $this->command?->warn('Skipping DividendSeeder: no register found.');
            return;
        }

        $shareClasses = ShareClass::query()->where('register_id', $register->id)->get();
        if ($shareClasses->isEmpty()) {
            $shareClasses->push(ShareClass::query()->create([
                'register_id' => $register->id,
                'class_code' => 'ORD',
                'currency' => 'NGN',
                'par_value' => 0.50,
                'description' => 'Ordinary Shares',
                'withholding_tax_rate' => 10.00,
            ]));
        }

        $mainDeclaration = $this->seedMainDeclaration(
            registerId: (int) $register->id,
            companyId: (int) $register->company_id,
            actorId: $actorId,
            secondActorId: $secondActorId
        );

        $this->seedDeclarationShareClasses((int) $mainDeclaration->id, $shareClasses->pluck('id')->take(2)->all());

        $run = $this->seedEntitlementRun((int) $mainDeclaration->id, $actorId);

        $entitlements = $this->seedEntitlements(
            declarationId: (int) $mainDeclaration->id,
            runId: (int) $run->id,
            registerId: (int) $register->id,
            shareClasses: $shareClasses,
            actorId: $actorId
        );

        $this->seedPayments($entitlements, $actorId);
        $this->seedApprovalActions((int) $mainDeclaration->id, $actorId, $secondActorId);
        $this->seedDelegations((int) $mainDeclaration->id, $actorId, $secondActorId);
        $this->seedWorkflowEvents((int) $mainDeclaration->id, $actorId);
        $this->seedDraftSupplementary(
            registerId: (int) $register->id,
            companyId: (int) $register->company_id,
            actorId: $actorId,
            supplementaryOfId: (int) $mainDeclaration->id
        );

        $this->command?->info('DividendSeeder completed.');
    }

    private function hasRequiredTables(): bool
    {
        return Schema::hasTable('dividend_declarations')
            && Schema::hasTable('dividend_declaration_share_classes')
            && Schema::hasTable('dividend_entitlement_runs')
            && Schema::hasTable('dividend_entitlements')
            && Schema::hasTable('dividend_workflow_events');
    }

    private function seedMainDeclaration(int $registerId, int $companyId, int $actorId, int $secondActorId): DividendDeclaration
    {
        $keys = $this->declarationUniqueKey($companyId, 'FY2025 Final', 'DIV-2025-FINAL-0001');

        $payload = [
            'company_id' => $companyId,
            'register_id' => $registerId,
            'period_label' => 'FY2025 Final',
            'description' => 'Seeded final dividend declaration for FY2025',
            'action_type' => 'DIVIDEND',
            'declaration_method' => 'RATE_PER_SHARE',
            'rate_per_share' => 1.250000,
            'announcement_date' => now()->subDays(20)->toDateString(),
            'record_date' => now()->subDays(10)->toDateString(),
            'payment_date' => now()->addDays(10)->toDateString(),
            'exclude_caution_accounts' => false,
            'require_active_bank_mandate' => true,
            'status' => 'APPROVED',
            'total_gross_amount' => 1850000.00,
            'total_tax_amount' => 185000.00,
            'total_net_amount' => 1665000.00,
            'rounding_residue' => 0,
            'eligible_shareholders_count' => 3,
            'created_by' => $actorId,
            'submitted_by' => $actorId,
            'verified_by' => $secondActorId,
            'approved_by' => $secondActorId,
            'submitted_at' => now()->subDays(15),
            'verified_at' => now()->subDays(14),
            'approved_at' => now()->subDays(13),
        ];

        if (Schema::hasColumn('dividend_declarations', 'dividend_declaration_no')) {
            $payload['dividend_declaration_no'] = 'DIV-2025-FINAL-0001';
        }
        if (Schema::hasColumn('dividend_declarations', 'initiator')) {
            $payload['initiator'] = 'operations';
        }
        if (Schema::hasColumn('dividend_declarations', 'current_approval_step')) {
            $payload['current_approval_step'] = 3;
        }
        if (Schema::hasColumn('dividend_declarations', 'is_frozen')) {
            $payload['is_frozen'] = true;
        }
        if (Schema::hasColumn('dividend_declarations', 'live_at')) {
            $payload['status'] = 'LIVE';
            $payload['live_at'] = now()->subDays(12);
        }

        return DividendDeclaration::query()->updateOrCreate(
            $this->onlyExistingColumns('dividend_declarations', $keys),
            $this->onlyExistingColumns('dividend_declarations', $payload)
        );
    }

    private function seedDraftSupplementary(int $registerId, int $companyId, int $actorId, int $supplementaryOfId): void
    {
        $keys = $this->declarationUniqueKey($companyId, 'FY2025 Supplementary', 'DIV-2025-SUPP-0001');

        $payload = [
            'company_id' => $companyId,
            'register_id' => $registerId,
            'period_label' => 'FY2025 Supplementary',
            'description' => 'Seeded supplementary dividend declaration',
            'action_type' => 'DIVIDEND',
            'declaration_method' => 'RATE_PER_SHARE',
            'rate_per_share' => 0.150000,
            'announcement_date' => now()->toDateString(),
            'record_date' => now()->addDays(5)->toDateString(),
            'payment_date' => now()->addDays(15)->toDateString(),
            'exclude_caution_accounts' => false,
            'require_active_bank_mandate' => true,
            'status' => 'DRAFT',
            'created_by' => $actorId,
        ];

        if (Schema::hasColumn('dividend_declarations', 'dividend_declaration_no')) {
            $payload['dividend_declaration_no'] = 'DIV-2025-SUPP-0001';
        }
        if (Schema::hasColumn('dividend_declarations', 'initiator')) {
            $payload['initiator'] = 'mutual_funds';
        }
        if (Schema::hasColumn('dividend_declarations', 'supplementary_of_declaration_id')) {
            $payload['supplementary_of_declaration_id'] = $supplementaryOfId;
        }

        DividendDeclaration::query()->updateOrCreate(
            $this->onlyExistingColumns('dividend_declarations', $keys),
            $this->onlyExistingColumns('dividend_declarations', $payload)
        );
    }

    private function seedDeclarationShareClasses(int $declarationId, array $shareClassIds): void
    {
        foreach ($shareClassIds as $shareClassId) {
            DB::table('dividend_declaration_share_classes')->updateOrInsert(
                [
                    'dividend_declaration_id' => $declarationId,
                    'share_class_id' => (int) $shareClassId,
                ],
                [
                    'created_at' => now(),
                ]
            );
        }
    }

    private function seedEntitlementRun(int $declarationId, int $actorId): DividendEntitlementRun
    {
        return DividendEntitlementRun::query()->updateOrCreate(
            [
                'dividend_declaration_id' => $declarationId,
                'run_type' => 'FROZEN',
            ],
            [
                'run_status' => 'COMPLETED',
                'computed_at' => now()->subDays(12),
                'computed_by' => $actorId,
                'total_gross_amount' => 1850000.00,
                'total_tax_amount' => 185000.00,
                'total_net_amount' => 1665000.00,
                'rounding_residue' => 0,
                'eligible_shareholders_count' => 3,
            ]
        );
    }

    private function seedEntitlements(int $declarationId, int $runId, int $registerId, $shareClasses, int $actorId)
    {
        $accounts = ShareholderRegisterAccount::query()
            ->where('register_id', $registerId)
            ->take(3)
            ->get();

        $entitlements = collect();

        foreach ($accounts as $index => $account) {
            $class = $shareClasses[$index % $shareClasses->count()];
            $eligibleShares = 100000 + ($index * 25000);
            $gross = round($eligibleShares * 1.25, 2);
            $tax = round($gross * 0.10, 2);
            $net = round($gross - $tax, 2);

            $entitlement = DividendEntitlement::query()->updateOrCreate(
                [
                    'entitlement_run_id' => $runId,
                    'register_account_id' => (int) $account->id,
                    'share_class_id' => (int) $class->id,
                ],
                [
                    'dividend_declaration_id' => $declarationId,
                    'eligible_shares' => $eligibleShares,
                    'gross_amount' => $gross,
                    'tax_amount' => $tax,
                    'net_amount' => $net,
                    'is_payable' => true,
                    'ineligibility_reason' => 'NONE',
                ]
            );

            $entitlements->push($entitlement);
        }

        // Ensure at least one ineligible entitlement exists for testing eligibility filters.
        $first = $entitlements->first();
        if ($first) {
            $first->update([
                'is_payable' => false,
                'ineligibility_reason' => 'NO_ACTIVE_BANK_MANDATE',
            ]);
        }

        return $entitlements;
    }

    private function seedPayments($entitlements, int $actorId): void
    {
        if (!Schema::hasTable('dividend_payments')) {
            return;
        }

        foreach ($entitlements as $index => $entitlement) {
            $shareholderId = $entitlement->registerAccount?->shareholder_id;
            $bankMandateId = $shareholderId
                ? ShareholderMandate::query()
                    ->where('shareholder_id', $shareholderId)
                    ->value('id')
                : null;

            $paymentNo = sprintf('DPN-2025-%04d', (int) $entitlement->id);

            $keys = ['entitlement_id' => $entitlement->id];
            $values = [
                'payout_mode' => $bankMandateId ? 'edividend' : 'warrant',
                'bank_mandate_id' => $bankMandateId,
                'status' => $index === 0 ? 'failed' : 'initiated',
                'reissue_reason' => $index === 0 ? 'Seeded failed payment for reissue testing' : null,
                'created_by' => $actorId,
                'paid_ref' => null,
                'paid_at' => null,
            ];

            if (Schema::hasColumn('dividend_payments', 'dividend_payment_no')) {
                $values['dividend_payment_no'] = $paymentNo;
            }

            DividendPayment::query()->updateOrCreate(
                $this->onlyExistingColumns('dividend_payments', $keys),
                $this->onlyExistingColumns('dividend_payments', $values)
            );
        }
    }

    private function seedApprovalActions(int $declarationId, int $actorId, int $secondActorId): void
    {
        if (!Schema::hasTable('dividend_approval_actions')) {
            return;
        }

        $actions = [
            ['step_no' => 1, 'role_code' => 'IT', 'actor_id' => $actorId, 'decision' => 'APPROVED', 'comment' => 'IT approval seeded'],
            ['step_no' => 2, 'role_code' => 'OVERSIGHT_OPS', 'actor_id' => $secondActorId, 'decision' => 'APPROVED', 'comment' => 'Business oversight approval seeded'],
            ['step_no' => 3, 'role_code' => 'ACCOUNTS', 'actor_id' => $actorId, 'decision' => 'APPROVED', 'comment' => 'Accounts approval seeded'],
            ['step_no' => 3, 'role_code' => 'AUDIT', 'actor_id' => $secondActorId, 'decision' => 'APPROVED', 'comment' => 'Audit approval seeded'],
        ];

        foreach ($actions as $action) {
            DividendApprovalAction::query()->updateOrCreate(
                [
                    'dividend_declaration_id' => $declarationId,
                    'step_no' => $action['step_no'],
                    'role_code' => $action['role_code'],
                    'decision' => $action['decision'],
                    'actor_id' => $action['actor_id'],
                ],
                [
                    'comment' => $action['comment'],
                    'acted_at' => now()->subDays(12),
                ]
            );
        }
    }

    private function seedDelegations(int $declarationId, int $actorId, int $secondActorId): void
    {
        if (!Schema::hasTable('dividend_approval_delegations')) {
            return;
        }

        DividendApprovalDelegation::query()->updateOrCreate(
            [
                'dividend_declaration_id' => $declarationId,
                'role_code' => 'AUDIT',
                'reliever_user_id' => $secondActorId,
            ],
            [
                'assigned_by' => $actorId,
                'created_at' => now()->subDays(13),
            ]
        );
    }

    private function seedWorkflowEvents(int $declarationId, int $actorId): void
    {
        $events = [
            ['CREATED', 'Declaration created'],
            ['UPDATED', 'Declaration details updated'],
            ['SUBMITTED', 'Declaration submitted for approvals'],
            ['STEP_APPROVED', 'IT step approved'],
            ['STEP_APPROVED', 'Business oversight step approved'],
            ['STEP_APPROVED', 'Accounts and Audit step approved'],
            ['APPROVED', 'Declaration fully approved'],
        ];

        if (Schema::hasColumn('dividend_declarations', 'live_at')) {
            $events[] = ['GO_LIVE', 'Declaration moved to live status'];
        }

        foreach ($events as [$eventType, $note]) {
            DividendWorkflowEvent::query()->firstOrCreate(
                [
                    'dividend_declaration_id' => $declarationId,
                    'event_type' => $eventType,
                    'note' => $note,
                ],
                [
                    'actor_id' => $actorId,
                ]
            );
        }
    }

    private function declarationUniqueKey(int $companyId, string $periodLabel, string $declarationNo): array
    {
        if (Schema::hasColumn('dividend_declarations', 'dividend_declaration_no')) {
            return ['dividend_declaration_no' => $declarationNo];
        }

        return [
            'company_id' => $companyId,
            'period_label' => $periodLabel,
        ];
    }

    private function onlyExistingColumns(string $table, array $data): array
    {
        $columns = Schema::getColumnListing($table);

        return array_filter(
            $data,
            static fn ($value, $key) => in_array($key, $columns, true),
            ARRAY_FILTER_USE_BOTH
        );
    }
}
