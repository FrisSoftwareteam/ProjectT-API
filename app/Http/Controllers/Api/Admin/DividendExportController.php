<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exports\DividendEntitlementsExport;
use App\Exports\DividendPaymentsExport;
use App\Http\Controllers\Controller;
use App\Models\DividendDeclaration;
use App\Models\DividendEntitlement;
use App\Models\DividendEntitlementRun;
use App\Models\DividendWorkflowEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;

class DividendExportController extends Controller
{
    /**
     * 4.1 Export Entitlement File (CSV)
     * GET /admin/dividend-declarations/{declaration_id}/exports/entitlements
     */
    public function entitlements(int $declaration_id)
    {
        $declaration = $this->loadLiveDeclaration($declaration_id);
        $run = $this->loadFrozenRun($declaration_id);

        $entitlements = DividendEntitlement::with(['registerAccount.shareholder', 'shareClass'])
            ->where('dividend_declaration_id', $declaration_id)
            ->where('entitlement_run_id', $run->id)
            ->orderBy('register_account_id')
            ->get();

        DividendWorkflowEvent::create([
            'dividend_declaration_id' => $declaration->id,
            'event_type' => 'EXPORTED_ENTITLEMENTS',
            'actor_id' => auth()->id(),
            'note' => 'Entitlements exported',
        ]);

        return Excel::download(
            new DividendEntitlementsExport($entitlements, (float) $declaration->rate_per_share),
            'dividend_entitlements_' . $declaration_id . '.csv',
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    /**
     * 4.2 Export Payment File (CSV or XLSX)
     * GET /admin/dividend-declarations/{declaration_id}/exports/payments
     */
    public function payments(Request $request, int $declaration_id)
    {
        $declaration = $this->loadLiveDeclaration($declaration_id);
        $run = $this->loadFrozenRun($declaration_id);

        $format = strtolower((string) $request->query('format', 'csv'));
        if (!in_array($format, ['csv', 'xlsx'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid format. Supported formats: csv, xlsx',
            ], 422);
        }

        $entitlements = DividendEntitlement::with(['registerAccount.shareholder.mandates', 'shareClass'])
            ->where('dividend_declaration_id', $declaration_id)
            ->where('entitlement_run_id', $run->id)
            ->orderBy('register_account_id')
            ->get();

        $writerType = $format === 'xlsx'
            ? \Maatwebsite\Excel\Excel::XLSX
            : \Maatwebsite\Excel\Excel::CSV;

        DividendWorkflowEvent::create([
            'dividend_declaration_id' => $declaration->id,
            'event_type' => 'EXPORTED_PAYMENTS',
            'actor_id' => auth()->id(),
            'note' => 'Payments exported (' . $format . ')',
        ]);

        return Excel::download(
            new DividendPaymentsExport($entitlements, $declaration_id),
            'dividend_payments_' . $declaration_id . '.' . $format,
            $writerType
        );
    }

    /**
     * 4.3 Export Dividend Summary (PDF)
     * GET /admin/dividend-declarations/{declaration_id}/exports/summary
     */
    public function summary(int $declaration_id)
    {
        $declaration = $this->loadLiveDeclaration($declaration_id);
        $run = $this->loadFrozenRun($declaration_id);

        $byShareClass = DividendEntitlement::selectRaw('share_class_id, SUM(eligible_shares) as total_shares, SUM(gross_amount) as gross_amount, SUM(tax_amount) as tax_amount, SUM(net_amount) as net_amount')
            ->where('dividend_declaration_id', $declaration_id)
            ->where('entitlement_run_id', $run->id)
            ->groupBy('share_class_id')
            ->with('shareClass')
            ->get();

        $pdf = Pdf::loadView('exports.dividend-summary', [
            'declaration' => $declaration->load(['register.company']),
            'byShareClass' => $byShareClass,
        ]);

        DividendWorkflowEvent::create([
            'dividend_declaration_id' => $declaration->id,
            'event_type' => 'EXPORTED_SUMMARY',
            'actor_id' => auth()->id(),
            'note' => 'Summary exported (pdf)',
        ]);

        return $pdf->download('dividend_summary_' . $declaration_id . '.pdf');
    }

    private function loadLiveDeclaration(int $declaration_id): DividendDeclaration
    {
        $declaration = DividendDeclaration::findOrFail($declaration_id);
        $this->ensureLive($declaration);

        return $declaration;
    }

    private function loadFrozenRun(int $declaration_id): DividendEntitlementRun
    {
        $run = DividendEntitlementRun::where('dividend_declaration_id', $declaration_id)
            ->where('run_type', 'FROZEN')
            ->where('run_status', 'COMPLETED')
            ->orderByDesc('computed_at')
            ->first();

        if (!$run) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'No frozen entitlement run found for this declaration',
            ], 422));
        }

        return $run;
    }

    private function ensureLive(DividendDeclaration $declaration): void
    {
        if (!$declaration->isLive()) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Exports are only available for live declarations',
            ], 422));
        }
    }
}
