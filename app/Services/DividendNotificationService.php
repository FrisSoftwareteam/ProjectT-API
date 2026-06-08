<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\DividendApprovalAction;
use App\Models\DividendApprovalDelegation;
use App\Models\DividendDeclaration;
use App\Notifications\DividendWorkflowNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class DividendNotificationService
{
    private const ROLE_NAMES = [
        'IT' => ['Head of IT', 'IT Approval', 'IT'],
        'OVERSIGHT_OPS' => ['Operations Approval Role', 'Operations'],
        'OVERSIGHT_MF' => ['Mutual Funds Approval Role', 'Mutual Funds'],
        'ACCOUNTS' => ['Accounts'],
        'AUDIT' => ['Audit', 'Internal Audit'],
    ];

    public function submitted(DividendDeclaration $declaration, int $actorId): void
    {
        $this->sendSafely(
            fn () => $this->approversForCurrentStep($declaration),
            $declaration,
            $actorId,
            'DIVIDEND_APPROVAL_REQUIRED',
            'Dividend approval required',
            "Dividend declaration {$this->reference($declaration)} is awaiting your approval."
        );
    }

    public function approvalRecorded(DividendDeclaration $declaration, int $actorId): void
    {
        if ($declaration->isApproved()) {
            $this->sendSafely(
                fn () => $this->owners($declaration),
                $declaration,
                $actorId,
                'DIVIDEND_APPROVED',
                'Dividend declaration approved',
                "Dividend declaration {$this->reference($declaration)} has completed all approval steps."
            );

            return;
        }

        $this->sendSafely(
            fn () => $this->approversForCurrentStep($declaration),
            $declaration,
            $actorId,
            'DIVIDEND_APPROVAL_REQUIRED',
            'Dividend approval required',
            "Dividend declaration {$this->reference($declaration)} is awaiting your approval."
        );
    }

    public function queryRaised(DividendDeclaration $declaration, int $actorId, string $comment): void
    {
        $this->sendSafely(
            fn () => $this->owners($declaration),
            $declaration,
            $actorId,
            'DIVIDEND_QUERY_RAISED',
            'Query raised on dividend declaration',
            "A query was raised on dividend declaration {$this->reference($declaration)}: {$comment}"
        );
    }

    public function queryResponded(DividendDeclaration $declaration, int $actorId): void
    {
        $this->sendSafely(
            fn () => $this->approversForCurrentStep($declaration),
            $declaration,
            $actorId,
            'DIVIDEND_QUERY_RESPONDED',
            'Dividend query response received',
            "A response was submitted for dividend declaration {$this->reference($declaration)}."
        );
    }

    public function rejected(DividendDeclaration $declaration, int $actorId, string $reason): void
    {
        $this->sendSafely(
            fn () => $this->owners($declaration),
            $declaration,
            $actorId,
            'DIVIDEND_REJECTED',
            'Dividend declaration rejected',
            "Dividend declaration {$this->reference($declaration)} was rejected: {$reason}"
        );
    }

    public function wentLive(DividendDeclaration $declaration, int $actorId): void
    {
        $this->sendSafely(
            fn () => $this->owners($declaration),
            $declaration,
            $actorId,
            'DIVIDEND_LIVE',
            'Dividend declaration is live',
            "Dividend declaration {$this->reference($declaration)} is now live."
        );
    }

    private function approversForCurrentStep(DividendDeclaration $declaration): Collection
    {
        $roleCodes = $this->requiredRolesForStep($declaration, (int) $declaration->current_approval_step);
        $approvedRoleCodes = DividendApprovalAction::query()
            ->where('dividend_declaration_id', $declaration->id)
            ->where('decision', 'APPROVED')
            ->pluck('role_code')
            ->all();
        $roleCodes = array_values(array_diff($roleCodes, $approvedRoleCodes));

        $roleNames = collect($roleCodes)
            ->flatMap(fn (string $roleCode) => self::ROLE_NAMES[$roleCode] ?? [])
            ->unique()
            ->values()
            ->all();

        $roleUsers = empty($roleNames)
            ? collect()
            : AdminUser::query()->where('is_active', true)->role($roleNames)->get();

        $delegatedUserIds = DividendApprovalDelegation::query()
            ->where('dividend_declaration_id', $declaration->id)
            ->whereIn('role_code', $roleCodes)
            ->pluck('reliever_user_id');

        $delegatedUsers = AdminUser::query()
            ->where('is_active', true)
            ->whereIn('id', $delegatedUserIds)
            ->get();

        return $roleUsers->concat($delegatedUsers)->unique('id')->values();
    }

    private function owners(DividendDeclaration $declaration): Collection
    {
        return AdminUser::query()
            ->where('is_active', true)
            ->whereIn('id', array_filter([$declaration->created_by, $declaration->submitted_by]))
            ->get();
    }

    private function requiredRolesForStep(DividendDeclaration $declaration, int $step): array
    {
        return match ($step) {
            1 => ['IT'],
            2 => [$declaration->initiator === 'mutual_funds' ? 'OVERSIGHT_MF' : 'OVERSIGHT_OPS'],
            3 => ['ACCOUNTS', 'AUDIT'],
            default => [],
        };
    }

    private function sendSafely(
        callable $resolveRecipients,
        DividendDeclaration $declaration,
        int $actorId,
        string $event,
        string $title,
        string $message
    ): void {
        try {
            $recipients = $resolveRecipients()
                ->reject(fn (AdminUser $user) => $user->id === $actorId)
                ->values();

            if ($recipients->isEmpty()) {
                return;
            }

            Notification::send($recipients, new DividendWorkflowNotification([
                'event' => $event,
                'title' => $title,
                'message' => $message,
                'entity_type' => 'dividend_declaration',
                'entity_id' => $declaration->id,
                'reference' => $this->reference($declaration),
                'action_url' => "/admin/dividend-declarations/{$declaration->id}",
            ]));
        } catch (\Throwable $exception) {
            Log::error('Unable to dispatch dividend notification', [
                'declaration_id' => $declaration->id,
                'event' => $event,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function reference(DividendDeclaration $declaration): string
    {
        return $declaration->dividend_declaration_no ?: "#{$declaration->id}";
    }
}
