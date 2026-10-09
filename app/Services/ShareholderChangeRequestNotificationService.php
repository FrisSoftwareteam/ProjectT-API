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
            "{$changeRequest->control_no} for {$this->shareholderLabel($changeRequest)} needs your review."
        );
    }

    /**
     * Approved → notifies both the initiator and the other approvers (so it
     * leaves their queue too); rejected/returned → notifies the initiator
     * only, with the approver's remarks folded into the message.
     */
    public function decided(ShareholderChangeRequest $changeRequest, int $actorId, string $decision, ?string $remarks = null): void
    {
        $controlNo = $changeRequest->control_no;
        $shareholderLabel = $this->shareholderLabel($changeRequest);
        $event = 'SHAREHOLDER_CHANGE_'.strtoupper($decision);

        if ($decision === 'approved') {
            $this->sendSafely(
                fn () => $this->submitterOnly($changeRequest)->merge($this->approvers($changeRequest))->unique('id')->values(),
                $changeRequest,
                $actorId,
                $event,
                'Shareholder update approved',
                "{$controlNo} for {$shareholderLabel} was approved by {$this->actorName($actorId)}."
            );

            return;
        }

        $message = "{$controlNo} for {$shareholderLabel} was returned".($remarks ? ": {$remarks}" : '').'.';

        $this->sendSafely(
            fn () => $this->submitterOnly($changeRequest),
            $changeRequest,
            $actorId,
            $event,
            'Shareholder update returned',
            $message
        );
    }

    public function infoRequested(ShareholderChangeRequest $changeRequest, int $actorId): void
    {
        $note = $changeRequest->info_requested_note;
        $message = "More information needed on {$changeRequest->control_no}".($note ? ": {$note}" : '').'.';

        $this->sendSafely(
            fn () => $this->submitterOnly($changeRequest),
            $changeRequest,
            $actorId,
            'SHAREHOLDER_CHANGE_INFO_REQUESTED',
            'More information requested',
            $message
        );
    }

    /**
     * Fired instead of submitted() when a submission carries a
     * resubmitted_from_id: approvers need to know a corrected version of a
     * previously returned request has come back in.
     */
    public function resubmitted(ShareholderChangeRequest $changeRequest, int $actorId): void
    {
        $oldControlNo = $changeRequest->resubmitted_from_id
            ? ShareholderChangeRequest::where('id', $changeRequest->resubmitted_from_id)->value('control_no')
            : null;

        $message = "{$changeRequest->control_no} for {$this->shareholderLabel($changeRequest)} was corrected and resubmitted"
            .($oldControlNo ? " (was {$oldControlNo})" : '').'.';

        $this->sendSafely(
            fn () => $this->approvers($changeRequest),
            $changeRequest,
            $actorId,
            'SHAREHOLDER_CHANGE_RESUBMITTED',
            'Shareholder update resubmitted',
            $message
        );
    }

    private function approvers(ShareholderChangeRequest $changeRequest): Collection
    {
        $permission = match ($changeRequest->request_type) {
            'bank_mandate' => 'shareholder_change_requests.approve_mandate',
            'chn_update' => 'shareholder_change_requests.approve_chn',
            default => 'shareholder_change_requests.approve',
        };

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

    private function shareholderLabel(ShareholderChangeRequest $changeRequest): string
    {
        // Deliberately a scalar lookup, not $changeRequest->shareholder: touching that
        // relation caches it on the (shared) change-request instance, which would then
        // leak a full Shareholder object into whatever JSON response returns it.
        $fullName = Shareholder::where('id', $changeRequest->shareholder_id)->value('full_name');

        return $fullName ?? "shareholder #{$changeRequest->shareholder_id}";
    }

    private function actorName(int $actorId): string
    {
        $user = AdminUser::find($actorId);
        $name = $user ? trim("{$user->first_name} {$user->last_name}") : '';

        return $name !== '' ? $name : 'an approver';
    }
}
