<?php

namespace App\Services;

use App\Models\Register;
use App\Models\ShareClass;
use App\Models\ShareholderCaution;
use App\Models\ShareholderCautionLog;
use App\Models\ShareholderRegisterAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CautionService
{
    public const CAUTION_CLASS_CODE        = 'CAUTION';
    public const CAUTION_CLASS_DESCRIPTION = 'System-managed caution share class';

    public function apply(ShareholderRegisterAccount $sra, array $data, int $actorId): array
    {
        $existing = ShareholderCaution::where('sra_id', $sra->id)
            ->whereNull('removed_at')
            ->first();

        if ($existing) {
            return [
                'success' => false,
                'message' => 'This shareholder already has an active caution on this register account. '
                           . 'Remove the existing caution (ID: ' . $existing->id . ') before applying a new one.',
                'caution' => null,
            ];
        }

        try {
            $caution = DB::transaction(function () use ($sra, $data, $actorId) {

                $cautionShareClass = $this->ensureCautionShareClass($sra->register_id);

                $caution = ShareholderCaution::create([
                    'shareholder_id'       => $sra->shareholder_id,
                    'sra_id'               => $sra->id,
                    'caution_share_class_id' => $cautionShareClass->id,
                    'scope'                => $data['scope'],
                    'company_id'           => $data['scope'] === 'global' ? null : $sra->register->company_id,
                    'caution_type'         => $data['caution_type'],
                    'instruction_source'   => $data['instruction_source'],
                    'reason'               => $data['reason'],
                    'effective_date'       => $data['effective_date'],
                    'supporting_document_path' => $data['supporting_document_path'] ?? null,
                    'created_by'           => $actorId,
                ]);

                ShareholderCautionLog::create([
                    'caution_id'             => $caution->id,
                    'shareholder_id'         => $sra->shareholder_id,
                    'sra_id'                 => $sra->id,
                    'action'                 => 'applied',
                    'caution_type'           => $caution->caution_type,
                    'instruction_source'     => $caution->instruction_source,
                    'reason'                 => $caution->reason,
                    'scope'                  => $caution->scope,
                    'company_id'             => $caution->company_id,
                    'caution_share_class_id' => $cautionShareClass->id,
                    'actor_id'               => $actorId,
                ]);

                return $caution;
            });

            $caution->load(['shareholder', 'sra.register.company', 'cautionShareClass', 'createdBy']);

            Log::info('Caution applied', [
                'caution_id'     => $caution->id,
                'shareholder_id' => $sra->shareholder_id,
                'sra_id'         => $sra->id,
                'register_id'    => $sra->register_id,
                'scope'          => $caution->scope,
                'caution_type'   => $caution->caution_type,
                'applied_by'     => $actorId,
            ]);

            return [
                'success' => true,
                'message' => 'Caution applied successfully.',
                'caution' => $caution,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to apply caution', [
                'sra_id' => $sra->id,
                'error'  => $e->getMessage(),
                'trace'  => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'An error occurred while applying the caution.',
                'caution' => null,
            ];
        }
    }

    public function remove(ShareholderCaution $caution, string $removalReason, int $actorId): array
    {
        if (!is_null($caution->removed_at)) {
            return [
                'success' => false,
                'message' => 'This caution has already been removed.',
            ];
        }

        try {
            DB::transaction(function () use ($caution, $removalReason, $actorId) {
                $caution->update([
                    'removed_at'     => now(),
                    'removal_reason' => $removalReason,
                    'removed_by'     => $actorId,
                ]);

                ShareholderCautionLog::create([
                    'caution_id'             => $caution->id,
                    'shareholder_id'         => $caution->shareholder_id,
                    'sra_id'                 => $caution->sra_id,
                    'action'                 => 'removed',
                    'caution_type'           => $caution->caution_type,
                    'instruction_source'     => $caution->instruction_source,
                    'reason'                 => $removalReason,
                    'scope'                  => $caution->scope,
                    'company_id'             => $caution->company_id,
                    'caution_share_class_id' => $caution->caution_share_class_id,
                    'actor_id'               => $actorId,
                ]);
            });

            Log::info('Caution removed', [
                'caution_id'     => $caution->id,
                'shareholder_id' => $caution->shareholder_id,
                'sra_id'         => $caution->sra_id,
                'removed_by'     => $actorId,
            ]);

            return [
                'success' => true,
                'message' => 'Caution removed successfully.',
            ];

        } catch (\Exception $e) {
            Log::error('Failed to remove caution', [
                'caution_id' => $caution->id,
                'error'      => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'An error occurred while removing the caution.',
            ];
        }
    }

    public function ensureCautionShareClass(int $registerId): ShareClass
    {
        $existing = ShareClass::where('register_id', $registerId)
            ->where('is_caution_class', true)
            ->withTrashed()  // include soft-deleted — restore if needed
            ->first();

        if ($existing) {
            // Restore if it was soft-deleted
            if ($existing->trashed()) {
                $existing->restore();
                Log::info('Caution share class restored', [
                    'register_id'    => $registerId,
                    'share_class_id' => $existing->id,
                ]);
            }
            return $existing;
        }

        $cautionClass = ShareClass::create([
            'register_id'      => $registerId,
            'class_code'       => self::CAUTION_CLASS_CODE,
            'currency'         => 'NGN',
            'par_value'        => 0,
            'description'      => self::CAUTION_CLASS_DESCRIPTION,
            'withholding_tax_rate' => 0,
            'is_caution_class' => true,
        ]);

        Log::info('Caution share class created', [
            'register_id'    => $registerId,
            'share_class_id' => $cautionClass->id,
        ]);

        return $cautionClass;
    }

    public function isCautioned(int $sraId): bool
    {
        return ShareholderCaution::where('sra_id', $sraId)
            ->whereNull('removed_at')
            ->exists();
    }

    public function getActiveCaution(int $sraId): ?ShareholderCaution
    {
        return ShareholderCaution::where('sra_id', $sraId)
            ->whereNull('removed_at')
            ->with(['cautionShareClass', 'createdBy'])
            ->first();
    }

    public function getShareholderCautionSummary(int $shareholderId): array
    {
        $cautions = ShareholderCaution::where('shareholder_id', $shareholderId)
            ->whereNull('removed_at')
            ->with(['sra.register.company', 'cautionShareClass', 'createdBy'])
            ->get();

        return [
            'is_cautioned'  => $cautions->isNotEmpty(),
            'active_count'  => $cautions->count(),
            'cautions'      => $cautions,
        ];
    }
}