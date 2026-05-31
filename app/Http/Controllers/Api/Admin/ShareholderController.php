<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkShareholderRequest;
use App\Http\Requests\CreateShareholderRegisterAccountRequest;
use App\Http\Requests\ShareholderRequest;
use App\Models\Shareholder;
use App\Services\ShareholderAccountNumberService;
use App\Http\Requests\ShareholderAddressRequest;
use App\Http\Requests\ShareholderMandateRequest;
use App\Models\ShareholderMandate;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\ShareholderIdentityRequest;
use App\Models\ShareholderIdentity;
use App\Models\ShareholderRegisterAccount;
use App\Http\Requests\ShareholderAddressUpdateRequest;
use App\Models\ShareholderAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ShareholderController extends Controller
{
    public function __construct(
        protected ShareholderAccountNumberService $accountNumberService
    ) {
    }

    public function index()
    {
        $query = Shareholder::query();

        $search = trim((string) request()->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('middle_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('account_no', 'like', $like);
            });
        }

        $query->with(['registerAccounts' => function ($q) {
            $q->select(
                'id',
                'shareholder_id',
                'register_id',
                'shareholder_no',
                'chn',
                'cscs_account_no',
                'status'
            );
        }]);

        $shareholders = $query->paginate(20);

        return response()->json($shareholders);
    }

    public function store(ShareholderRequest $request)
    {
        $data = $request->validated();
        $data['account_no'] = $this->accountNumberService->generate();

        $shareholder = Shareholder::create($data);

        return response()->json($shareholder, 201);
    }

    public function bulkStore(BulkShareholderRequest $request)
    {
        $payload = $request->validated('shareholders');

        $shareholders = DB::transaction(function () use ($payload) {
            $created = [];

            foreach ($payload as $shareholderData) {
                $shareholderData['account_no'] = $this->accountNumberService->generate();
            $shareholderData['full_name'] = trim(($shareholderData['first_name'] ?? '') . ' ' . ($shareholderData['last_name'] ?? ''));
                $created[] = Shareholder::create($shareholderData);
            }

            return collect($created);
        });

        return response()->json([
            'success' => true,
            'message' => 'Shareholders created successfully',
            'count' => $shareholders->count(),
            'data' => $shareholders,
        ], 201);
    }

    public function storeWithDetails(\Illuminate\Http\Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shareholder' => 'required|array',
            'shareholder.holder_type' => 'required|in:individual,corporate',
            'shareholder.first_name' => 'required|string|max:255',
            'shareholder.full_name' => 'nullable|string|max:255',
            'shareholder.last_name' => 'nullable|string|max:100',
            'shareholder.middle_name' => 'nullable|string|max:100',
            'shareholder.email' => 'required|email|unique:shareholders,email',
            'shareholder.phone' => 'required|string|max:32|unique:shareholders,phone',
            'shareholder.date_of_birth' => 'nullable|date',
            'shareholder.sex' => 'nullable|in:male,female,other',
            'shareholder.rc_number' => 'nullable|string|max:50',
            'shareholder.nin' => 'nullable|string|max:20',
            'shareholder.bvn' => 'nullable|string|max:20',
            'shareholder.tax_id' => 'nullable|string|max:50',
            'shareholder.next_of_kin_name' => 'nullable|string|max:255',
            'shareholder.next_of_kin_phone' => 'nullable|string|max:32',
            'shareholder.next_of_kin_relationship' => 'nullable|string|max:100',
            'shareholder.status' => 'required|in:active,dormant,deceased,closed',

            'addresses' => 'required|array|min:1',
            'addresses.*.address_line1' => 'required|string|max:255',
            'addresses.*.address_line2' => 'nullable|string|max:255',
            'addresses.*.city' => 'nullable|string|max:100',
            'addresses.*.state' => 'nullable|string|max:100',
            'addresses.*.postal_code' => 'nullable|string|max:20',
            'addresses.*.country' => 'nullable|string|max:100',
            'addresses.*.is_primary' => 'required|boolean',
            'addresses.*.valid_from' => 'nullable|date',
            'addresses.*.valid_to' => 'nullable|date',

            'mandates' => 'nullable|array',
            'mandates.*.bank_name' => 'required_with:mandates|string|max:150',
            'mandates.*.account_name' => 'required_with:mandates|string|max:255',
            'mandates.*.account_number' => 'required_with:mandates|string|max:20',
            'mandates.*.bvn' => 'nullable|string|max:20',
            'mandates.*.status' => 'required_with:mandates|in:pending,verified,active,rejected,revoked',
            'mandates.*.verified_by' => 'nullable|exists:admin_users,id',
            'mandates.*.verified_at' => 'nullable|date',

            'identities' => 'nullable|array',
            'identities.*.id_type' => 'required_with:identities|in:passport,drivers_license,nin,bvn,cac_cert,other',
            'identities.*.id_value' => 'required_with:identities|string|max:100',
            'identities.*.issued_on' => 'nullable|date',
            'identities.*.expires_on' => 'nullable|date',
            'identities.*.verified_status' => 'required_with:identities|in:pending,verified,rejected',
            'identities.*.verified_by' => 'nullable|exists:admin_users,id',
            'identities.*.verified_at' => 'nullable|date',
            'identities.*.file_ref' => 'nullable|string|max:255',
        ], [
            'shareholder.required' => 'The shareholder object is required.',
            'shareholder.array'    => 'The shareholder data must be an object.',
            'shareholder.holder_type.required' => 'Holder type is required.',
            'shareholder.holder_type.in'       => 'Holder type must be either "individual" or "corporate".',
            'shareholder.first_name.required' => 'First name is required.',
            'shareholder.first_name.string'   => 'First name must be a valid text value.',
            'shareholder.first_name.max'      => 'First name must not exceed 255 characters.',
            'shareholder.full_name.string' => 'Full name must be a valid text value.',
            'shareholder.full_name.max'    => 'Full name must not exceed 255 characters.',

            'shareholder.last_name.string' => 'Last name must be a valid text value.',
            'shareholder.last_name.max'    => 'Last name must not exceed 100 characters.',
            'shareholder.middle_name.string' => 'Middle name must be a valid text value.',
            'shareholder.middle_name.max'    => 'Middle name must not exceed 100 characters.',

            'shareholder.email.required' => 'Email address is required.',
            'shareholder.email.email'    => 'Please enter a valid email address.',
            'shareholder.email.unique'   => 'This email address is already registered to another shareholder.',

            'shareholder.phone.required' => 'Phone number is required.',
            'shareholder.phone.string'   => 'Phone number must be a valid text value.',
            'shareholder.phone.max'      => 'Phone number must not exceed 32 characters.',
            'shareholder.phone.unique'   => 'This phone number is already registered to another shareholder.',

            'shareholder.date_of_birth.date' => 'Date of birth must be a valid date.',

            'shareholder.sex.in' => 'Gender must be one of: male, female, or other.',

            'shareholder.rc_number.string' => 'RC number must be a valid text value.',
            'shareholder.rc_number.max'    => 'RC number must not exceed 50 characters.',

            'shareholder.nin.string' => 'NIN must be a valid text value.',
            'shareholder.nin.max'    => 'NIN must not exceed 20 characters.',

            'shareholder.bvn.string' => 'BVN must be a valid text value.',
            'shareholder.bvn.max'    => 'BVN must not exceed 20 characters.',

            'shareholder.tax_id.string' => 'Tax ID must be a valid text value.',
            'shareholder.tax_id.max'    => 'Tax ID must not exceed 50 characters.',

            'shareholder.next_of_kin_name.string'         => 'Next of kin name must be a valid text value.',
            'shareholder.next_of_kin_name.max'            => 'Next of kin name must not exceed 255 characters.',
            'shareholder.next_of_kin_phone.string'        => 'Next of kin phone must be a valid text value.',
            'shareholder.next_of_kin_phone.max'           => 'Next of kin phone must not exceed 32 characters.',
            'shareholder.next_of_kin_relationship.string' => 'Next of kin relationship must be a valid text value.',
            'shareholder.next_of_kin_relationship.max'    => 'Next of kin relationship must not exceed 100 characters.',

            'shareholder.status.required' => 'Status is required.',
            'shareholder.status.in'       => 'Status must be one of: active, dormant, deceased, or closed.',

            'addresses.required' => 'At least one address is required.',
            'addresses.array'    => 'Addresses must be provided as a list.',
            'addresses.min'      => 'At least one address is required.',

            'addresses.*.address_line1.required' => 'Address line 1 is required for each address.',
            'addresses.*.address_line1.string'   => 'Address line 1 must be a valid text value.',
            'addresses.*.address_line1.max'      => 'Address line 1 must not exceed 255 characters.',
            'addresses.*.address_line2.string'   => 'Address line 2 must be a valid text value.',
            'addresses.*.address_line2.max'      => 'Address line 2 must not exceed 255 characters.',
            'addresses.*.city.string'            => 'City must be a valid text value.',
            'addresses.*.city.max'               => 'City must not exceed 100 characters.',
            'addresses.*.state.string'           => 'State must be a valid text value.',
            'addresses.*.state.max'              => 'State must not exceed 100 characters.',
            'addresses.*.postal_code.string'     => 'Postal code must be a valid text value.',
            'addresses.*.postal_code.max'        => 'Postal code must not exceed 20 characters.',
            'addresses.*.country.string'         => 'Country must be a valid text value.',
            'addresses.*.country.max'            => 'Country must not exceed 100 characters.',
            'addresses.*.is_primary.required'    => 'Each address must specify whether it is the primary address.',
            'addresses.*.is_primary.boolean'     => 'The primary address flag must be true or false.',
            'addresses.*.valid_from.date'        => 'The address valid-from date must be a valid date.',
            'addresses.*.valid_to.date'          => 'The address valid-to date must be a valid date.',

            'mandates.array'                       => 'Mandates must be provided as a list.',
            'mandates.*.bank_name.required_with'   => 'Bank name is required for each mandate.',
            'mandates.*.bank_name.string'          => 'Bank name must be a valid text value.',
            'mandates.*.bank_name.max'             => 'Bank name must not exceed 150 characters.',
            'mandates.*.account_name.required_with'=> 'Account name is required for each mandate.',
            'mandates.*.account_name.string'       => 'Account name must be a valid text value.',
            'mandates.*.account_name.max'          => 'Account name must not exceed 255 characters.',
            'mandates.*.account_number.required_with' => 'Account number is required for each mandate.',
            'mandates.*.account_number.string'     => 'Account number must be a valid text value.',
            'mandates.*.account_number.max'        => 'Account number must not exceed 20 characters.',
            'mandates.*.bvn.string'                => 'Mandate BVN must be a valid text value.',
            'mandates.*.bvn.max'                   => 'Mandate BVN must not exceed 20 characters.',
            'mandates.*.status.required_with'      => 'Status is required for each mandate.',
            'mandates.*.status.in'                 => 'Mandate status must be one of: pending, verified, active, rejected, or revoked.',
            'mandates.*.verified_by.exists'        => 'The specified mandate verifier does not exist.',
            'mandates.*.verified_at.date'          => 'The mandate verified-at date must be a valid date.',

            'identities.array'                          => 'Identities must be provided as a list.',
            'identities.*.id_type.required_with'        => 'ID type is required for each identity record.',
            'identities.*.id_type.in'                   => 'ID type must be one of: passport, drivers_license, nin, bvn, cac_cert, or other.',
            'identities.*.id_value.required_with'       => 'ID value is required for each identity record.',
            'identities.*.id_value.string'              => 'ID value must be a valid text value.',
            'identities.*.id_value.max'                 => 'ID value must not exceed 100 characters.',
            'identities.*.issued_on.date'               => 'The identity issued-on date must be a valid date.',
            'identities.*.expires_on.date'              => 'The identity expiry date must be a valid date.',
            'identities.*.verified_status.required_with'=> 'Verified status is required for each identity record.',
            'identities.*.verified_status.in'           => 'Verified status must be one of: pending, verified, or rejected.',
            'identities.*.verified_by.exists'           => 'The specified identity verifier does not exist.',
            'identities.*.verified_at.date'             => 'The identity verified-at date must be a valid date.',
            'identities.*.file_ref.string'              => 'File reference must be a valid text value.',
            'identities.*.file_ref.max'                 => 'File reference must not exceed 255 characters.',
        ]);

        $validator->after(function ($validator) use ($request) {
            $addresses = (array) $request->input('addresses', []);
            $primaryCount = 0;
            foreach ($addresses as $address) {
                if (!empty($address['is_primary'])) {
                    $primaryCount++;
                }
            }

            if ($primaryCount > 1) {
                $validator->errors()->add('addresses', 'Only one primary address is allowed.');
            }
            if ($primaryCount === 0) {
                $validator->errors()->add('addresses', 'At least one address must be marked as primary.');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();

        DB::beginTransaction();

        try {
            $shareholderData = $payload['shareholder'];
            $shareholderData['account_no'] = $this->accountNumberService->generate();
            $shareholderData['full_name'] = trim(($shareholderData['first_name'] ?? '') . ' ' . ($shareholderData['last_name'] ?? ''));

            $shareholder = Shareholder::create($shareholderData);

            $addresses = array_map(function ($row) use ($shareholder) {
                $row['shareholder_id'] = $shareholder->id;
                return $row;
            }, $payload['addresses']);
            ShareholderAddress::insert($addresses);

            if (!empty($payload['mandates'])) {
                $mandates = array_map(function ($row) use ($shareholder) {
                    $row['shareholder_id'] = $shareholder->id;
                    return $row;
                }, $payload['mandates']);
                ShareholderMandate::insert($mandates);
            }

            if (!empty($payload['identities'])) {
                $identities = array_map(function ($row) use ($shareholder) {
                    $row['shareholder_id'] = $shareholder->id;
                    return $row;
                }, $payload['identities']);
                ShareholderIdentity::insert($identities);
            }

            DB::commit();

            $shareholder->load('addresses', 'mandates', 'identities', 'holdings.shareClass.register.company', 'certificates', 'registerAccounts');

            return response()->json([
                'success' => true,
                'message' => 'Shareholder created successfully',
                'data' => $shareholder,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error creating shareholder',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $shareholder = Shareholder::with(
            'addresses',
            'mandates',
            'identities',
            'holdings.shareClass.register.company',
            'certificates',
            'registerAccounts',
            'activeCautions'
        )->findOrFail($id);

        return response()->json($shareholder);
    }
    
    public function update(ShareholderRequest $request, $id)
    {
        $shareholder = Shareholder::findOrFail($id);
        $shareholder->update($request->validated());

        return response()->json($shareholder->fresh());
    }
    
    public function destroy($id)
    {
        $shareholder = Shareholder::find($id);
        $shareholder->delete();

        return response()->json(null, 204);
    }

    public function addAddress(ShareholderAddressRequest $request)
    {
        $shareholder = ShareholderAddress::create($request->validated());

        return response()->json($shareholder);
    }

    public function updateAddress(
        ShareholderAddressUpdateRequest $request,
        $shareholderId,
        $addressId
    ) {
        $shareholder = Shareholder::findOrFail($shareholderId);
        $address = ShareholderAddress::where('id', $addressId)
            ->where('shareholder_id', $shareholder->id)
            ->firstOrFail();

        $address->update($request->validated());

        return response()->json($address);
    }

    public function addMandate($id,ShareholderMandateRequest $request)
    {
        $payload = $request->validated();
        $payload['shareholder_id'] = $id;
        $shareholderMandate = ShareholderMandate::create($payload);

        return response()->json($shareholderMandate);
    }

    public function updateMandate(ShareholderMandateRequest $request, $shareholderId, $mandateId)
    {
        $shareholderMandate = ShareholderMandate::where('id', $mandateId)
            ->where('shareholder_id', $shareholderId)
            ->firstOrFail();
        $payload = $request->validated();
        $payload['shareholder_id'] = $shareholderId;
        $shareholderMandate->update($payload);
        return response()->json($shareholderMandate);
    }

    public function getAllShareholdersParameters($id)
    {
        $shareholderMandates = Shareholder::find($id)::with(
            'addresses',
            'mandates',
            'identities',
            'holdings',
            'certificates',
            'registerAccounts'
        )->get();
        return response()->json($shareholderMandates);
    }

    public function shareholderIdentityCreate(ShareholderIdentityRequest $request, $id)
    {   
        Log::info('Shareholder identity create request: ' . json_encode($request->validated()));
        $shareholderIdentity = ShareholderIdentity::create($request->validated());

        return response()->json($shareholderIdentity);
    }

    public function shareholderIdentityUpdate(ShareholderIdentityRequest $request, $id)
    {
        $shareholderIdentity = ShareholderIdentity::find($id);
        $shareholderIdentity->update($request->validated());

        return response()->json($shareholderIdentity);
    }

    public function addRegisterAccount(CreateShareholderRegisterAccountRequest $request, $shareholderId)
    {
        $shareholder = Shareholder::findOrFail($shareholderId);
        $payload = $request->validated();

        $existing = ShareholderRegisterAccount::query()
            ->where('shareholder_id', $shareholder->id)
            ->where('register_id', $payload['register_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Shareholder already belongs to this register',
                'data' => $existing,
            ], 409);
        }

        $registerAccount = ShareholderRegisterAccount::query()->create([
            'shareholder_id' => $shareholder->id,
            'register_id' => $payload['register_id'],
            'shareholder_no' => $payload['shareholder_no'] ?? $this->generateShareholderNo($shareholder->id),
            'chn' => $payload['chn'] ?? null,
            'cscs_account_no' => $payload['cscs_account_no'] ?? null,
            'residency_status' => $payload['residency_status'] ?? 'resident',
            'kyc_level' => $payload['kyc_level'] ?? 'basic',
            'status' => $payload['status'] ?? 'active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Shareholder added to register successfully',
            'data' => $registerAccount,
        ], 201);
    }

    private function generateShareholderNo(int $shareholderId): string
    {
        return 'SRA-' . str_pad((string) $shareholderId, 8, '0', STR_PAD_LEFT) . '-' . strtoupper(Str::random(4));
    }
}