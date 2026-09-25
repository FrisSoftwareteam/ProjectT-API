<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\Shareholder;
use App\Models\ShareholderChangeRequest;
use App\Notifications\ShareholderChangeRequestNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ShareholderChangeRequestNotificationService
{
    public function submitted(ShareholderChangeRequest $changeRequest, int $actorId): void
    {
        $this->sendSafely(
            fn () => $this->approvers($changeRequest),
            $changeRequest,
            $actorId,
            'SHAREHOLDER_CHANGE_APPROVAL_REQUIRED',
            'Shareholder update pending approval',
            "A {$this->typeLabel($changeRequest)} update for {$this->shareholderLabel($changeRequest)} ({$changeRequest->control_no}) is awaiting your approval."
        );
    }

    public function decided(ShareholderChangeRequest $changeRequest, int $actorId, string $decision): void
    {
        $this->sendSafely(
            fn () => $this->submitterOnly($changeRequest),
            $changeRequest,
            $actorId,
            'SHAREHOLDER_CHANGE_'.strtoupper($decision),
            'Shareholder update '.$decision,
            "Your {$this->typeLabel($changeRequest)} update for {$this->shareholderLabel($changeRequest)} ({$changeRequest->control_no}) was {$decision}."
        );
    }

    private function approvers(ShareholderChangeRequest $changeRequest): Collection
    {
        $permission = $changeRequest->request_type === 'bank_mandate'
            ? 'shareholder_change_requests.approve_mandate'
            : 'shareholder_change_requests.approve';

        return AdminUser::query()->where('is_active', true)->permission($permission)->get();
    }

    private function submitterOnly(ShareholderChangeRequest $changeRequest): Collection
    {
        return AdminUser::query()
            ->where('is_active', true)
            ->where('id', $changeRequest->submitted_by)
            ->get();
    }

    private function sendSafely(
        callable $resolveRecipients,
        ShareholderChangeRequest $changeRequest,
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

            Notification::send($recipients, new ShareholderChangeRequestNotification([
                'event' => $event,
                'title' => $title,
                'message' => $message,
                'entity_type' => 'shareholder_change_request',
                'entity_id' => $changeRequest->id,
                'reference' => $changeRequest->control_no,
                'action_url' => "/admin/shareholder-change-requests/{$changeRequest->id}",
            ]));
        } catch (\Throwable $exception) {
            Log::error('Unable to dispatch shareholder change request notification', [
                'change_request_id' => $changeRequest->id,
                'event' => $event,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function typeLabel(ShareholderChangeRequest $changeRequest): string
    {
        return str_replace('_', ' ', $changeRequest->request_type);
    }

    private function shareholderLabel(ShareholderChangeRequest $changeRequest): string
    {
        // Deliberately a scalar lookup, not $changeRequest->shareholder: touching that
        // relation caches it on the (shared) change-request instance, which would then
        // leak a full Shareholder object into whatever JSON response returns it.
        $fullName = Shareholder::where('id', $changeRequest->shareholder_id)->value('full_name');

        return $fullName ?? "shareholder #{$changeRequest->shareholder_id}";
    }
}
