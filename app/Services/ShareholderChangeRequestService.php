<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\Shareholder;
use App\Models\ShareholderChangeApproval;
use App\Models\ShareholderChangeRequest;
use App\Models\ShareholderIdentity;
use App\Models\ShareholderMandate;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Central maker-checker engine for shareholder record updates. Every write
 * path (personal info, bank mandate, identification, profile picture)
 * submits a pending ShareholderChangeRequest here instead of writing
 * directly, and approve()/reject() are the only code paths allowed to
 * apply a change to the live records.
 */
class ShareholderChangeRequestService
{
    private const PROFILE_REQUEST_TYPES = ['name_change', 'email_change', 'phone_change', 'address_change', 'profile_update'];

    public function __construct(
        protected ShareholderChangeRequestReferenceService $referenceService,
        protected ShareholderChangeRequestNotificationService $notificationService
    ) {}

    public function submitProfileUpdate(
        Shareholder $shareholder,
        array $proposedFields,
        ?array $proposedAddress,
        ?string $reason,
        int $submittedBy
    ): ShareholderChangeRequest {
        $this->guardNoPendingRequest($shareholder->id, self::PROFILE_REQUEST_TYPES);

        $payloadOld = collect($proposedFields)->keys()->mapWithKeys(fn ($field) => [$field => $shareholder->{$field}])->toArray();
        $payloadNew = $proposedFields;

        if ($proposedAddress !== null) {
            $primaryAddress = $shareholder->addresses()->where('is_primary', true)->first();

            $payloadOld['address'] = collect($proposedAddress)
                ->keys()
                ->mapWithKeys(fn ($field) => [$field => $primaryAddress?->{$field}])
                ->toArray();
            $payloadNew['address'] = $proposedAddress;
        }

        $requestType = $this->inferProfileRequestType($proposedFields, $proposedAddress !== null);

        return $this->submit($shareholder, $requestType, $payloadOld, $payloadNew, $reason, $submittedBy);
    }

    public function submitMandateChange(
        Shareholder $shareholder,
        ?ShareholderMandate $existingMandate,
        array $proposedFields,
        ?string $reason,
        int $submittedBy
    ): ShareholderChangeRequest {
        $this->guardNoPendingRequest($shareholder->id, ['bank_mandate']);

        $payloadOld = collect($proposedFields)
            ->keys()
            ->mapWithKeys(fn ($field) => [$field => $existingMandate?->{$field}])
            ->toArray();
        $payloadOld['mandate_id'] = $existingMandate?->id;

        $payloadNew = $proposedFields;
        $payloadNew['mandate_id'] = $existingMandate?->id;

        return $this->submit($shareholder, 'bank_mandate', $payloadOld, $payloadNew, $reason, $submittedBy);
    }

    public function submitIdentityChange(
        Shareholder $shareholder,
        ?ShareholderIdentity $existingIdentity,
        array $proposedFields,
        ?string $reason,
        int $submittedBy
    ): ShareholderChangeRequest {
        $this->guardNoPendingRequest($shareholder->id, ['identity_change']);

        $payloadOld = collect($proposedFields)
            ->keys()
            ->mapWithKeys(fn ($field) => [$field => $existingIdentity?->{$field}])
            ->toArray();
        $payloadOld['identity_id'] = $existingIdentity?->id;

        $payloadNew = $proposedFields;
        $payloadNew['identity_id'] = $existingIdentity?->id;

        return $this->submit($shareholder, 'identity_change', $payloadOld, $payloadNew, $reason, $submittedBy);
    }

    public function submitProfilePictureChange(
        Shareholder $shareholder,
        string $pendingPictureUrl,
        ?string $reason,
        int $submittedBy
    ): ShareholderChangeRequest {
        $this->guardNoPendingRequest($shareholder->id, ['profile_picture_change']);

        return $this->submit(
            $shareholder,
            'profile_picture_change',
            ['profile_picture' => $shareholder->profile_picture],
            ['profile_picture' => $pendingPictureUrl],
            $reason,
            $submittedBy
        );
    }

    /**
     * @return array<string, mixed> the affected record(s), e.g. ['shareholder' => ...] or ['shareholder' => ..., 'mandate' => ...]
     */
    public function approve(ShareholderChangeRequest $changeRequest, AdminUser $approver, ?string $remarks): array
    {
        if (! in_array($changeRequest->status, ['submitted', 'info_requested'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only pending (submitted or awaiting more info) updates can be approved.'],
            ]);
        }

        $addressPayload = $changeRequest->payload_new['address'] ?? null;
        if ($addressPayload !== null && ! $changeRequest->shareholder->hasActiveAddress()) {
            throw ValidationException::withMessages([
                'status' => ['Shareholder has no primary address on file to update. Add one via the addresses endpoint before approving this request.'],
            ]);
        }

        $result = DB::transaction(function () use ($changeRequest, $approver, $remarks) {
            $result = $this->applyChange($changeRequest);

            ShareholderChangeApproval::create([
                'change_request_id' => $changeRequest->id,
                'level_no' => 1,
                'decision' => 'approved',
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'remarks' => $remarks,
            ]);

            $changeRequest->update(['status' => 'applied']);

            return $result;
        });

        $this->notificationService->decided($changeRequest->fresh(), $approver->id, 'approved');

        return $result;
    }

    public function reject(ShareholderChangeRequest $changeRequest, AdminUser $approver, string $remarks): void
    {
        if (! in_array($changeRequest->status, ['submitted', 'info_requested'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only pending (submitted or awaiting more info) updates can be rejected.'],
            ]);
        }

        DB::transaction(function () use ($changeRequest, $approver, $remarks) {
            ShareholderChangeApproval::create([
                'change_request_id' => $changeRequest->id,
                'level_no' => 1,
                'decision' => 'rejected',
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'remarks' => $remarks,
            ]);

            $changeRequest->update(['status' => 'rejected']);

            if ($changeRequest->request_type === 'profile_picture_change') {
                $pendingPath = $this->storagePathFromUrl($changeRequest->payload_new['profile_picture'] ?? null);
                if ($pendingPath !== null) {
                    Storage::disk('public')->delete($pendingPath);
                }
            }
        });

        $this->notificationService->decided($changeRequest->fresh(), $approver->id, 'rejected');
    }

    public function requestMoreInfo(
        ShareholderChangeRequest $changeRequest,
        AdminUser $actor,
        string $type,
        ?string $note
    ): ShareholderChangeRequest {
        if ($changeRequest->status !== 'submitted') {
            throw ValidationException::withMessages([
                'status' => ['Only a pending (submitted) update can have more information requested.'],
            ]);
        }

        $changeRequest->update([
            'status' => 'info_requested',
            'info_requested_type' => $type,
            'info_requested_note' => $note,
            'info_requested_by' => $actor->id,
            'info_requested_at' => now(),
        ]);

        $this->notificationService->infoRequested($changeRequest->fresh(), $actor->id);

        return $changeRequest->fresh();
    }

    protected function applyChange(ShareholderChangeRequest $changeRequest): array
    {
        $shareholder = Shareholder::findOrFail($changeRequest->shareholder_id);

        return match ($changeRequest->request_type) {
            'bank_mandate' => $this->applyMandateChange($shareholder, $changeRequest),
            'identity_change' => $this->applyIdentityChange($shareholder, $changeRequest),
            'profile_picture_change' => $this->applyProfilePictureChange($shareholder, $changeRequest),
            default => $this->applyProfileChange($shareholder, $changeRequest),
        };
    }

    private function applyMandateChange(Shareholder $shareholder, ShareholderChangeRequest $changeRequest): array
    {
        $payload = Arr::except($changeRequest->payload_new, ['mandate_id']);
        $mandateId = $changeRequest->payload_new['mandate_id'] ?? null;

        if ($mandateId) {
            $mandate = ShareholderMandate::where('id', $mandateId)->where('shareholder_id', $shareholder->id)->firstOrFail();
            $mandate->update($payload);
        } else {
            $mandate = ShareholderMandate::create($payload + [
                'shareholder_id' => $shareholder->id,
                'status' => 'active',
            ]);
        }

        return ['shareholder' => $shareholder, 'mandate' => $mandate];
    }

    private function applyIdentityChange(Shareholder $shareholder, ShareholderChangeRequest $changeRequest): array
    {
        $payload = Arr::except($changeRequest->payload_new, ['identity_id']);
        $identityId = $changeRequest->payload_new['identity_id'] ?? null;

        // The proposed value is a materially different document, so any prior
        // verification decision no longer applies and must be re-done.
        $payload['verified_status'] = 'pending';
        $payload['verified_by'] = null;
        $payload['verified_at'] = null;

        if ($identityId) {
            $identity = ShareholderIdentity::where('id', $identityId)->where('shareholder_id', $shareholder->id)->firstOrFail();
            $identity->update($payload);
        } else {
            $identity = ShareholderIdentity::create($payload + ['shareholder_id' => $shareholder->id]);
        }

        return ['shareholder' => $shareholder, 'identity' => $identity];
    }

    private function applyProfilePictureChange(Shareholder $shareholder, ShareholderChangeRequest $changeRequest): array
    {
        $newUrl = $changeRequest->payload_new['profile_picture'];
        $previousPath = $this->storagePathFromUrl($changeRequest->payload_old['profile_picture'] ?? null);
        $newPath = $this->storagePathFromUrl($newUrl);

        $shareholder->update(['profile_picture' => $newUrl]);

        if ($previousPath !== null && $previousPath !== $newPath) {
            Storage::disk('public')->delete($previousPath);
        }

        return ['shareholder' => $shareholder->fresh()];
    }

    private function applyProfileChange(Shareholder $shareholder, ShareholderChangeRequest $changeRequest): array
    {
        $addressPayload = $changeRequest->payload_new['address'] ?? null;
        $flatPayload = Arr::except($changeRequest->payload_new, ['address']);

        if (! empty($flatPayload)) {
            $shareholder->update($flatPayload);
        }

        if ($addressPayload !== null) {
            $shareholder->addresses()->where('is_primary', true)->first()->update($addressPayload);
        }

        return ['shareholder' => $shareholder->fresh()->load('activeCautions', 'addresses')];
    }

    protected function submit(
        Shareholder $shareholder,
        string $requestType,
        array $payloadOld,
        array $payloadNew,
        ?string $reason,
        int $submittedBy
    ): ShareholderChangeRequest {
        $changeRequest = ShareholderChangeRequest::create([
            'shareholder_id' => $shareholder->id,
            'request_type' => $requestType,
            'payload_old' => $payloadOld,
            'payload_new' => $payloadNew,
            'reason' => $reason,
            'status' => 'submitted',
            'control_no' => $this->referenceService->generate(),
            'submitted_by' => $submittedBy,
        ]);

        $this->notificationService->submitted($changeRequest, $submittedBy);

        return $changeRequest;
    }

    /**
     * A shareholder may only have one pending request per category at a
     * time, so a second maker can't submit a conflicting proposal while
     * the first is still awaiting a decision.
     */
    protected function guardNoPendingRequest(int $shareholderId, array $requestTypes): void
    {
        $exists = ShareholderChangeRequest::where('shareholder_id', $shareholderId)
            ->whereIn('request_type', $requestTypes)
            ->whereIn('status', ['submitted', 'info_requested'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'status' => ['A pending update of this type already exists for this shareholder. Resolve it before submitting another.'],
            ]);
        }
    }

    private function inferProfileRequestType(array $flatFields, bool $hasAddress): string
    {
        if ($hasAddress) {
            return empty($flatFields) ? 'address_change' : 'profile_update';
        }

        $keys = array_keys($flatFields);

        if ($keys === ['email']) {
            return 'email_change';
        }

        if ($keys === ['phone']) {
            return 'phone_change';
        }

        $nameFields = ['first_name', 'last_name', 'middle_name'];
        if (! empty($keys) && empty(array_diff($keys, $nameFields))) {
            return 'name_change';
        }

        return 'profile_update';
    }

    /**
     * Resolve a public storage URL (as stored on shareholders.profile_picture
     * or in a change-request payload) back to the relative disk path Storage
     * needs for delete(). Returns null for anything that isn't a
     * profile-picture path we manage.
     */
    private function storagePathFromUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = ltrim($path, '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return str_starts_with($path, 'profile-pictures/') ? $path : null;
    }
}
