<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EstateDistributionRequest;
use App\Http\Requests\EstateCaseRepresentativeRequest;
use App\Http\Requests\ProbateBeneficiaryRequest;
use App\Http\Requests\ProbateCaseRequest;
use App\Models\EstateCaseRepresentative;
use App\Models\ProbateBeneficiary;
use App\Models\ProbateCase;
use App\Models\ShareClass;
use App\Models\SharePosition;
use App\Models\ShareTransaction;
use App\Models\ShareTransferEvent;
use App\Models\Shareholder;
use App\Models\ShareholderRegisterAccount;
use App\Services\CapitalValidationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProbateCaseController extends Controller
{
    public function __construct(
        private readonly CapitalValidationService $capitalValidationService
    ) {
    }

    public function index(Request $request)
    {
        $data = ProbateCase::with([
            'shareholder',
            'beneficiaries.beneficiaryShareholder',
            'representatives.shareholder',
        ])->paginate($request->query('per_page', 15));

        return response()->json($data);
    }

    public function store(ProbateCaseRequest $request)
    {
        $payload = $this->validatedPayloadWithDocument($request);

        $case = DB::transaction(function () use ($payload, $request) {
            $payload['status'] = $payload['status'] ?? 'pending';

            $case = ProbateCase::create($payload);
            $this->syncShareholderEstateState($case, $request->user()?->id);
            $this->logActivity($request->user()?->id, 'probate_case_created', [
                'probate_case_id' => $case->id,
                'shareholder_id' => $case->shareholder_id,
                'case_type' => $case->case_type,
            ]);

            return $case;
        });

        return response()->json($case->fresh($this->caseRelations()), 201);
    }

    public function show(ProbateCase $probateCase)
    {
        $probateCase->load($this->caseRelations());

        return response()->json($probateCase);
    }

    public function update(ProbateCaseRequest $request, ProbateCase $probateCase)
    {
        $payload = $this->validatedPayloadWithDocument($request, $probateCase);

        DB::transaction(function () use ($payload, $probateCase, $request) {
            $probateCase->update($payload);
            $this->syncShareholderEstateState($probateCase->fresh(), $request->user()?->id);
            $this->logActivity($request->user()?->id, 'probate_case_updated', [
                'probate_case_id' => $probateCase->id,
                'shareholder_id' => $probateCase->shareholder_id,
                'case_type' => $probateCase->fresh()->case_type,
            ]);
        });

        return response()->json($probateCase->fresh($this->caseRelations()));
    }

    public function destroy(ProbateCase $probateCase)
    {
        if ($probateCase->document_ref) {
            Storage::disk('local')->delete($probateCase->document_ref);
        }

        $probateCase->delete();

        return response()->noContent();
    }

    public function addBeneficiary(ProbateBeneficiaryRequest $request, ProbateCase $probateCase)
    {
        $payload = $request->validated();
        $payload['probate_case_id'] = $probateCase->id;
        $beneficiary = ProbateBeneficiary::create($payload);

        $this->logActivity($request->user()?->id, 'probate_beneficiary_added', [
            'probate_case_id' => $probateCase->id,
            'beneficiary_id' => $beneficiary->id,
            'beneficiary_shareholder_id' => $beneficiary->beneficiary_shareholder_id,
            'share_class_id' => $beneficiary->share_class_id,
            'quantity' => $beneficiary->quantity,
        ]);

        return response()->json($beneficiary->load('beneficiaryShareholder'), 201);
    }

    public function addRepresentative(EstateCaseRepresentativeRequest $request, ProbateCase $probateCase)
    {
        $payload = $request->validated();
        $representativeType = $probateCase->case_type === 'probate' ? 'executor' : 'administrator';
        $shareholderIds = $payload['shareholder_ids'] ?? [$payload['shareholder_id']];
        $representatives = DB::transaction(function () use ($payload, $probateCase, $representativeType, $shareholderIds) {
            $hasPrimaryRepresentative = $probateCase->representatives()
                ->where('is_primary', true)
                ->exists();

            $requestedPrimary = $payload['is_primary'] ?? ! $hasPrimaryRepresentative;

            if ($requestedPrimary) {
                $probateCase->representatives()->update(['is_primary' => false]);
            }

            $created = [];

            foreach (array_values($shareholderIds) as $index => $shareholderId) {
                $representativeShareholder = Shareholder::findOrFail($shareholderId);

                if ($representativeShareholder->id === $probateCase->shareholder_id) {
                    throw ValidationException::withMessages([
                        'shareholder_ids' => ['The deceased shareholder cannot be attached as an estate representative.'],
                    ]);
                }

                $existing = $probateCase->representatives()
                    ->where('shareholder_id', $representativeShareholder->id)
                    ->first();

                if ($existing) {
                    throw ValidationException::withMessages([
                        'shareholder_ids' => ['One or more shareholders are already attached to the estate case.'],
                    ]);
                }

                $created[] = EstateCaseRepresentative::create([
                    'probate_case_id' => $probateCase->id,
                    'shareholder_id' => $representativeShareholder->id,
                    'representative_type' => $representativeType,
                    'is_primary' => $requestedPrimary && $index === 0,
                ]);
            }

            return EstateCaseRepresentative::query()
                ->whereKey(collect($created)->pluck('id'))
                ->with('shareholder')
                ->get();
        });

        foreach ($representatives as $representative) {
            $this->logActivity($request->user()?->id, 'probate_representative_attached', [
                'probate_case_id' => $probateCase->id,
                'representative_id' => $representative->id,
                'shareholder_id' => $representative->shareholder_id,
                'representative_type' => $representative->representative_type,
                'is_primary' => $representative->is_primary,
            ]);
        }

        return response()->json($representatives, 201);
    }

    public function distribute(EstateDistributionRequest $request, ProbateCase $probateCase)
    {
        if ($probateCase->status === 'closed') {
            throw ValidationException::withMessages([
                'status' => ['Closed estate cases cannot distribute shares.'],
            ]);
        }

        $data = $request->validated();
        $fromShareholder = Shareholder::findOrFail($probateCase->shareholder_id);
        $toShareholder = Shareholder::findOrFail($data['to_shareholder_id']);
        $shareClass = ShareClass::findOrFail($data['share_class_id']);
        $registerId = (int) $shareClass->register_id;

        if ($toShareholder->id === $fromShareholder->id) {
            throw ValidationException::withMessages([
                'to_shareholder_id' => ['Distribution target must be different from the deceased shareholder.'],
            ]);
        }

        $authorization = $this->resolveDistributionAuthorization($probateCase, $toShareholder->id);
        $quantity = (float) $data['quantity'];

        $event = DB::transaction(function () use ($data, $fromShareholder, $toShareholder, $shareClass, $registerId, $probateCase, $authorization, $quantity, $request) {
            $fromSra = ShareholderRegisterAccount::where('shareholder_id', $fromShareholder->id)
                ->where('register_id', $registerId)
                ->firstOrFail();

            $toSra = ShareholderRegisterAccount::firstOrCreate(
                ['shareholder_id' => $toShareholder->id, 'register_id' => $registerId],
                ['shareholder_no' => ShareholderRegisterAccount::generateAccountNumber($toShareholder->id), 'status' => 'active']
            );

            $fromPos = SharePosition::where('sra_id', $fromSra->id)
                ->where('share_class_id', $shareClass->id)
                ->lockForUpdate()
                ->first();

            if (! $fromPos || (float) $fromPos->quantity < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => ['Insufficient units for estate distribution.'],
                ]);
            }

            $toPos = SharePosition::where('sra_id', $toSra->id)
                ->where('share_class_id', $shareClass->id)
                ->lockForUpdate()
                ->first();

            if (! $toPos) {
                $toPos = SharePosition::create([
                    'sra_id' => $toSra->id,
                    'share_class_id' => $shareClass->id,
                    'quantity' => 0,
                    'holding_mode' => $fromPos->holding_mode ?? 'demat',
                ]);
            }

            $fromPos->quantity = (string) ((float) $fromPos->quantity - $quantity);
            $toPos->quantity = (string) ((float) $toPos->quantity + $quantity);
            $fromPos->save();
            $toPos->save();

            $txRef = 'EST-DIST-' . now()->format('YmdHis') . '-' . $probateCase->id . '-' . $fromSra->id . '-' . $toSra->id;
            ShareTransaction::create([
                'sra_id' => $fromSra->id,
                'share_class_id' => $shareClass->id,
                'tx_type' => 'transfer_out',
                'quantity' => $quantity,
                'tx_ref' => $txRef,
                'tx_date' => now(),
                'created_by' => $request->user()?->id,
            ]);

            ShareTransaction::create([
                'sra_id' => $toSra->id,
                'share_class_id' => $shareClass->id,
                'tx_type' => 'transfer_in',
                'quantity' => $quantity,
                'tx_ref' => $txRef,
                'tx_date' => now(),
                'created_by' => $request->user()?->id,
            ]);

            $this->capitalValidationService->syncOutstandingUnits($registerId);
            $this->capitalValidationService->assertConstantBalanced($registerId);

            $event = ShareTransferEvent::create([
                'from_shareholder_id' => $fromShareholder->id,
                'to_shareholder_id' => $toShareholder->id,
                'from_sra_id' => $fromSra->id,
                'to_sra_id' => $toSra->id,
                'share_class_id' => $shareClass->id,
                'quantity' => $quantity,
                'tx_ref' => $txRef,
                'document_ref' => $data['document_ref'] ?? null,
                'metadata' => [
                    'flow' => 'estate_distribution',
                    'probate_case_id' => $probateCase->id,
                    'authorized_as' => $authorization['type'],
                    'authorization_record_id' => $authorization['id'],
                    'reason' => $data['reason'] ?? null,
                    'corporate_action_id' => $data['corporate_action_id'] ?? null,
                ],
                'created_by' => $request->user()?->id,
            ]);

            return $event;
        });

        $this->logActivity($request->user()?->id, 'estate_distribution', [
            'probate_case_id' => $probateCase->id,
            'event_id' => $event->id,
            'from_shareholder_id' => $fromShareholder->id,
            'to_shareholder_id' => $toShareholder->id,
            'share_class_id' => $shareClass->id,
            'quantity' => $quantity,
            'authorized_as' => $authorization['type'],
        ]);

        return response()->json([
            'message' => 'Estate distribution completed',
            'data' => $event,
        ], 201);
    }

    private function syncShareholderEstateState(ProbateCase $probateCase, ?int $userId): void
    {
        $shareholder = Shareholder::query()->lockForUpdate()->findOrFail($probateCase->shareholder_id);

        $snapshot = [
            'original_first_name' => $probateCase->original_first_name ?: $shareholder->first_name,
            'original_last_name' => $probateCase->original_last_name ?: $shareholder->last_name,
            'original_middle_name' => $probateCase->original_middle_name ?: $shareholder->middle_name,
            'original_full_name' => $probateCase->original_full_name ?: ($shareholder->full_name ?: trim(implode(' ', array_filter([
                $shareholder->first_name,
                $shareholder->middle_name,
                $shareholder->last_name,
            ])))),
        ];

        if (
            $probateCase->original_first_name !== $snapshot['original_first_name'] ||
            $probateCase->original_last_name !== $snapshot['original_last_name'] ||
            $probateCase->original_middle_name !== $snapshot['original_middle_name'] ||
            $probateCase->original_full_name !== $snapshot['original_full_name']
        ) {
            $probateCase->forceFill($snapshot)->save();
        }

        if ($shareholder->status !== 'deceased') {
            $shareholder->status = 'deceased';
            $shareholder->save();

            $this->logActivity($userId, 'shareholder_marked_deceased', [
                'shareholder_id' => $shareholder->id,
                'probate_case_id' => $probateCase->id,
            ]);
        }

        $this->applyCaseTypeAccountNaming($probateCase->fresh(), $shareholder, $userId);
    }

    private function applyCaseTypeAccountNaming(ProbateCase $probateCase, Shareholder $shareholder, ?int $userId): void
    {
        $originalFullName = $probateCase->original_full_name ?: $shareholder->full_name;
        $probateName = 'Estate of ' . $originalFullName;

        if ($probateCase->case_type === 'probate') {
            if ($shareholder->full_name === $probateName) {
                return;
            }

            $shareholder->forceFill([
                'first_name' => $probateName,
                'last_name' => null,
                'middle_name' => null,
                'full_name' => $probateName,
            ])->save();

            $this->logActivity($userId, 'probate_account_renamed', [
                'shareholder_id' => $shareholder->id,
                'probate_case_id' => $probateCase->id,
                'account_name' => $probateName,
            ]);

            return;
        }

        $targetFirstName = $probateCase->original_first_name;
        $targetLastName = $probateCase->original_last_name;
        $targetMiddleName = $probateCase->original_middle_name;
        $targetFullName = $probateCase->original_full_name;

        if (
            $shareholder->first_name === $targetFirstName &&
            $shareholder->last_name === $targetLastName &&
            $shareholder->middle_name === $targetMiddleName &&
            $shareholder->full_name === $targetFullName
        ) {
            return;
        }

        $shareholder->forceFill([
            'first_name' => $targetFirstName,
            'last_name' => $targetLastName,
            'middle_name' => $targetMiddleName,
            'full_name' => $targetFullName,
        ])->save();

        $this->logActivity($userId, 'letters_account_name_restored', [
            'shareholder_id' => $shareholder->id,
            'probate_case_id' => $probateCase->id,
            'account_name' => $targetFullName,
        ]);
    }

    private function caseRelations(): array
    {
        return [
            'shareholder',
            'beneficiaries.beneficiaryShareholder',
            'representatives.shareholder',
        ];
    }

    private function resolveDistributionAuthorization(ProbateCase $probateCase, int $toShareholderId): array
    {
        $representative = $probateCase->representatives()
            ->where('shareholder_id', $toShareholderId)
            ->first();

        if ($representative) {
            return [
                'type' => 'representative',
                'id' => $representative->id,
            ];
        }

        $beneficiary = $probateCase->beneficiaries()
            ->where('beneficiary_shareholder_id', $toShareholderId)
            ->first();

        if ($beneficiary) {
            return [
                'type' => 'beneficiary',
                'id' => $beneficiary->id,
            ];
        }

        throw ValidationException::withMessages([
            'to_shareholder_id' => ['Distribution target must be an attached representative or beneficiary on this estate case.'],
        ]);
    }

    private function logActivity(?int $userId, string $action, array $metadata = []): void
    {
        if (! $userId) {
            return;
        }

        try {
            DB::table('user_activity_logs')->insert([
                'user_id' => $userId,
                'action' => $action,
                'metadata' => json_encode($metadata),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Unable to write probate activity log', [
                'action' => $action,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function validatedPayloadWithDocument(ProbateCaseRequest $request, ?ProbateCase $probateCase = null): array
    {
        $payload = $request->safe()->except(['document']);

        if (! $request->hasFile('document')) {
            return $payload;
        }

        if ($probateCase?->document_ref) {
            Storage::disk('local')->delete($probateCase->document_ref);
        }

        $payload['document_ref'] = $request->file('document')->store('probate_documents', 'local');

        return $payload;
    }
}
