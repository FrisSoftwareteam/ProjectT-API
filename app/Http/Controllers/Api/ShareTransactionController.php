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
        if ($request->filled('share_class_id')) {
            $query->where('share_class_id', $request->query('share_class_id'));
        }
        if ($request->filled('tx_type')) {
            $query->where('tx_type', $request->query('tx_type'));
        }
        if ($request->filled('tx_ref')) {
            $query->where('tx_ref', $request->query('tx_ref'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('tx_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('tx_date', '<=', $request->query('date_to'));
        }

        $direction = strtolower((string) $request->query('direction', 'all'));
        $inflowTypes = [
            'allot',
            'bonus',
            'rights',
            'transfer_in',
            'demat_in',
        ];
        $outflowTypes = [
            'transfer_out',
            'demat_out',
            'cancellation',
        ];

        if ($direction === 'inflow') {
            $query->whereIn('tx_type', $inflowTypes);
        } elseif ($direction === 'outflow') {
            $query->whereIn('tx_type', $outflowTypes);
        }

        $sortOrder = strtolower((string) $request->query('sort', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy('tx_date', $sortOrder)->orderBy('id', $sortOrder);

        $transactions = $query->paginate($request->query('per_page', 15));

        $transactions->getCollection()->transform(function (ShareTransaction $tx) use ($inflowTypes, $outflowTypes) {
            $base = $tx->toArray();
            $isOutflow = in_array($tx->tx_type, $outflowTypes, true);
            $isInflow = in_array($tx->tx_type, $inflowTypes, true);

            $base['direction'] = $isOutflow ? 'outflow' : ($isInflow ? 'inflow' : 'neutral');
            $base['signed_quantity'] = $isOutflow
                ? '-' . (string) $tx->quantity
                : (string) $tx->quantity;

            return $base;
        });

        return response()->json($transactions);
    }

    public function show(ShareTransaction $shareTransaction)
    {
        $outflowTypes = ['transfer_out', 'demat_out', 'cancellation'];
        $inflowTypes = ['allot', 'bonus', 'rights', 'transfer_in', 'demat_in'];
        $isOutflow = in_array($shareTransaction->tx_type, $outflowTypes, true);
        $isInflow = in_array($shareTransaction->tx_type, $inflowTypes, true);

        $payload = $shareTransaction->toArray();
        $payload['direction'] = $isOutflow ? 'outflow' : ($isInflow ? 'inflow' : 'neutral');
        $payload['signed_quantity'] = $isOutflow
            ? '-' . (string) $shareTransaction->quantity
            : (string) $shareTransaction->quantity;

        return response()->json($payload);
    }
}
