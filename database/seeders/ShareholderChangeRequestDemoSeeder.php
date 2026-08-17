<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\Shareholder;
use App\Models\ShareholderAddress;
use App\Models\ShareholderChangeApproval;
use App\Models\ShareholderChangeRequest;
use Illuminate\Database\Seeder;

/**
 * Demo data for the Pending Shareholder Updates feature, mirroring the
 * UI designer's Figma prototype 1:1 (same names, account numbers, and
 * request IDs as REQ-2024-001..005) so the frontend can test against
 * data that matches what they're already looking at in the mock.
 *
 * Not called from DatabaseSeeder — run explicitly:
 *   php artisan db:seed --class=ShareholderChangeRequestDemoSeeder
 *
 * Idempotent: safe to re-run, uses firstOrCreate/updateOrCreate throughout.
 */
class ShareholderChangeRequestDemoSeeder extends Seeder
{
    public function run(): void
    {
        $adaeze = $this->admin('adaeze.okafor@firstregistrarsnigeria.com', 'Adaeze', 'Okafor');
        $chidi = $this->admin('chidi.benson@firstregistrarsnigeria.com', 'Chidi', 'Benson');
        $reviewer = $this->admin('emmanuel.effiong@firstregistrarsnigeria.com', 'Emmanuel', 'Effiong');

        // Makers: can submit and view (Shareholder Management — no .approve).
        foreach ([$adaeze, $chidi] as $maker) {
            if (! $maker->hasAnyRole(['Shareholder Management', 'Admin', 'Super Admin'])) {
                $maker->assignRole('Shareholder Management');
            }
        }

        // Checker: can view and approve/reject.
        if (! $reviewer->hasAnyRole(['Admin', 'Super Admin'])) {
            $reviewer->assignRole('Admin');
        }

        $amadu = $this->shareholder('22233223338', 'Amadu', 'Pinock', 'amadu.p@yahoo.com', '+234 803 111 2233');
        $this->address($amadu, [
            'address_line1' => '12, Marina Road',
            'city' => 'Lagos Island',
            'state' => 'Lagos',
            'country' => 'Nigeria',
        ]);

        $emmanuelShareholder = $this->shareholder('99887766551', 'Emmanuel', 'Effiong', 'emmanuel.effiong.shareholder@example.com', '+234 998 877 6655');
        $olawale = $this->shareholder('11223344556', 'Olawale', 'Babajide', 'olawale.babajide@example.com', '+234 112 233 4455');
        $chioma = $this->shareholder('55443322110', 'Chioma', 'Nwachukwu', 'chioma.nwachukwu@example.com', '+234 554 433 2211');
        $this->address($chioma, [
            'address_line1' => '15 Broad Street',
            'city' => 'Marina',
            'state' => 'Lagos',
            'country' => 'Nigeria',
        ]);
        $abubakar = $this->shareholder('77665544332', 'Abubakar', 'Ibrahim', 'abubakar.ibrahim@example.com', '+234 776 655 4433');

        // REQ-2024-001 — Address Change — PENDING
        $this->changeRequest('REQ-2024-001', $amadu, 'address_change', 'submitted', $adaeze,
            '2024-07-10 14:30:00',
            payloadOld: [
                'phone' => '+234 803 111 2233',
                'address' => [
                    'address_line1' => '12, Marina Road',
                    'city' => 'Lagos Island',
                    'state' => 'Lagos',
                    'country' => 'Nigeria',
                ],
            ],
            payloadNew: [
                'phone' => '+234 807 723 4211',
                'address' => [
                    'address_line1' => '2 Aminu Kano Crescent',
                    'city' => 'Ikoyi',
                    'state' => 'Lagos State',
                    'country' => 'Nigeria',
                ],
            ],
            reason: 'Shareholder relocated and called in to update records.'
        );

        // REQ-2024-002 — Bank Details — PENDING
        // NOTE: bank mandate changes are NOT yet wired into approve() (deferred
        // scope, see release notes) — this row is for list/search/detail QA
        // only. Approving it will mark the request "applied" but will not
        // touch the shareholder_bank_mandates table.
        $this->changeRequest('REQ-2024-002', $emmanuelShareholder, 'bank_mandate', 'submitted', $chidi,
            '2024-07-09 11:15:00',
            payloadOld: ['bank_name' => 'GTBank', 'account_number' => '0123456789'],
            payloadNew: ['bank_name' => 'Access Bank', 'account_number' => '0099887766'],
            reason: 'Shareholder switched e-dividend bank.'
        );

        // REQ-2024-003 — Contact Info (phone) — APPROVED
        $req3 = $this->changeRequest('REQ-2024-003', $olawale, 'phone_change', 'applied', $adaeze,
            '2024-07-08 16:45:00',
            payloadOld: ['phone' => '+234 112 233 4455'],
            payloadNew: ['phone' => '+234 802 000 1111'],
            reason: 'Old line disconnected.'
        );
        $this->decide($req3, $reviewer, 'approved', '2024-07-08 17:05:00', 'Verified via callback to new number.');
        $olawale->update(['phone' => '+234 802 000 1111']);

        // REQ-2024-004 — Address Change — REJECTED
        $req4 = $this->changeRequest('REQ-2024-004', $chioma, 'address_change', 'rejected', $chidi,
            '2024-07-08 09:20:00',
            payloadOld: [
                'address' => [
                    'address_line1' => '15 Broad Street',
                    'city' => 'Marina',
                    'state' => 'Lagos',
                    'country' => 'Nigeria',
                ],
            ],
            payloadNew: [
                'address' => [
                    'address_line1' => '44 Awolowo Road',
                    'city' => 'Ikoyi',
                    'state' => 'Lagos',
                    'country' => 'Nigeria',
                ],
            ],
            reason: 'Shareholder requested address update by email.'
        );
        $this->decide($req4, $reviewer, 'rejected', '2024-07-08 10:00:00', 'Address does not match the submitted utility bill — please resubmit with valid proof of address.');

        // REQ-2024-005 — Contact Info (email) — PENDING
        $this->changeRequest('REQ-2024-005', $abubakar, 'email_change', 'submitted', $adaeze,
            '2024-07-07 15:10:00',
            payloadOld: ['email' => 'abubakar.ibrahim@example.com'],
            payloadNew: ['email' => 'a.ibrahim.new@example.com'],
            reason: 'Shareholder lost access to old email address.'
        );

        $this->command?->info('✓ Pending Shareholder Updates demo data seeded (REQ-2024-001..005)');
    }

    private function admin(string $email, string $first, string $last): AdminUser
    {
        return AdminUser::query()->firstOrCreate(
            ['email' => $email],
            ['first_name' => $first, 'last_name' => $last, 'is_active' => true]
        );
    }

    private function shareholder(string $accountNo, string $first, string $last, string $email, string $phone): Shareholder
    {
        return Shareholder::query()->firstOrCreate(
            ['account_no' => $accountNo],
            [
                'holder_type' => 'individual',
                'first_name' => $first,
                'last_name' => $last,
                'full_name' => "{$first} {$last}",
                'email' => $email,
                'phone' => $phone,
                'status' => 'active',
            ]
        );
    }

    private function address(Shareholder $shareholder, array $attributes): ShareholderAddress
    {
        return ShareholderAddress::query()->firstOrCreate(
            ['shareholder_id' => $shareholder->id, 'is_primary' => true],
            $attributes
        );
    }

    private function changeRequest(
        string $controlNo,
        Shareholder $shareholder,
        string $requestType,
        string $status,
        AdminUser $submitter,
        string $submittedAt,
        array $payloadOld,
        array $payloadNew,
        string $reason,
    ): ShareholderChangeRequest {
        return ShareholderChangeRequest::query()->updateOrCreate(
            ['control_no' => $controlNo],
            [
                'shareholder_id' => $shareholder->id,
                'request_type' => $requestType,
                'payload_old' => $payloadOld,
                'payload_new' => $payloadNew,
                'reason' => $reason,
                'status' => $status,
                'submitted_by' => $submitter->id,
                'submitted_at' => $submittedAt,
            ]
        );
    }

    private function decide(ShareholderChangeRequest $changeRequest, AdminUser $decider, string $decision, string $decidedAt, string $remarks): void
    {
        ShareholderChangeApproval::query()->updateOrCreate(
            ['change_request_id' => $changeRequest->id, 'level_no' => 1],
            [
                'decision' => $decision,
                'decided_by' => $decider->id,
                'decided_at' => $decidedAt,
                'remarks' => $remarks,
            ]
        );
    }
}
