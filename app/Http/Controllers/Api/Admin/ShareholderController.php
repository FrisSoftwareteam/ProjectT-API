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

    public function show($id)
    {
        $shareholder = Shareholder::find($id)::with('addresses','mandates','identities')->get();

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
        $shareholderMandate = ShareholderMandate::create($request->validated());

        return response()->json($shareholderMandate);
    }

    public function updateMandate(ShareholderMandateRequest $request, $shareholderId, $mandateId)
    {
        $shareholderMandate = ShareholderMandate::where('id', $mandateId)
            ->where('shareholder_id', $shareholderId)
            ->firstOrFail();
        $shareholderMandate->update($request->validated());
        return response()->json($shareholderMandate);
    }

    public function getAllShareholdersParameters($id)
    {
        $shareholderMandates = Shareholder::find($id)::with('addresses','mandates','identities')->get();
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
