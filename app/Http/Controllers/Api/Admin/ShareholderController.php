<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShareholderRequest;
use App\Models\Shareholder;
use App\Services\ShareholderAccountNumberService;
use App\Http\Requests\ShareholderAddressRequest;
use App\Http\Requests\ShareholderMandateRequest;
use App\Models\ShareholderMandate;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\ShareholderIdentityRequest;
use App\Models\ShareholderIdentity;
use App\Http\Requests\ShareholderAddressUpdateRequest;
use App\Models\ShareholderAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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

    public function storeWithDetails(\Illuminate\Http\Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shareholder' => 'required|array',
            'shareholder.holder_type' => 'required|in:individual,corporate',
            'shareholder.first_name' => 'required|string|max:255',
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

            $shareholder->load('addresses', 'mandates', 'identities', 'holdings', 'certificates', 'registerAccounts');

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
            'holdings',
            'certificates',
            'registerAccounts'
        )->findOrFail($id);

        return response()->json($shareholder);
    }
    
    public function update(ShareholderRequest $request, $id)
    {
        $shareholder = Shareholder::find($id);
        $shareholder->update($request->all());

        return response()->json($shareholder);
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
}
