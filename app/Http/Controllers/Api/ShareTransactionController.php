<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShareTransaction;
use Illuminate\Http\Request;

class ShareTransactionController extends Controller
{
    public function index(Request $request)
    {
        $query = ShareTransaction::query();
        if ($request->filled('sra_id')) {
            $query->where('sra_id', $request->query('sra_id'));
        }
        if ($request->filled('tx_ref')) {
            $query->where('tx_ref', $request->query('tx_ref'));
        }
        return response()->json($query->paginate($request->query('per_page', 15)));
    }

    public function show(ShareTransaction $shareTransaction)
    {
        return response()->json($shareTransaction);
    }
}
