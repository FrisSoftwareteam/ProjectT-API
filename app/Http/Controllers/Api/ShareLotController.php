<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShareLot;
use Illuminate\Http\Request;

class ShareLotController extends Controller
{
    public function index(Request $request)
    {
        $query = ShareLot::query();
        if ($request->filled('sra_id')) {
            $query->where('sra_id', $request->query('sra_id'));
        }
        if ($request->filled('share_class_id')) {
            $query->where('share_class_id', $request->query('share_class_id'));
        }
        return response()->json($query->paginate($request->query('per_page', 15)));
    }

    public function show(ShareLot $shareLot)
    {
        return response()->json($shareLot);
    }
}
