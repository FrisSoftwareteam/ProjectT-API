<?php

namespace App\Services;

use App\Models\Register;
use App\Models\SharePosition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CapitalValidationService
{
    private const EPSILON = 0.000001;

    public function assertChangeAllowed(int $registerId, float $delta, ?int $corporateActionId = null): void
    {
        $register = Register::findOrFail($registerId);
        if ($register->capital_behaviour_type !== 'constant') {
            return;
        }

        if (abs($delta) < self::EPSILON) {
            return;
        }

        if (! $corporateActionId) {
            throw ValidationException::withMessages([
                'capital' => ['Constant capital register changes require an approved corporate action.'],
            ]);
        }

        $approvedActionExists = DB::table('corporate_actions')
            ->where('id', $corporateActionId)
            ->where('register_id', $registerId)
            ->where('status', 'approved')
            ->exists();

        if (! $approvedActionExists) {
            throw ValidationException::withMessages([
                'corporate_action_id' => ['Provided corporate action is not approved for this register.'],
            ]);
        }
    }

    public function syncOutstandingUnits(int $registerId): Register
    {
        $register = Register::findOrFail($registerId);

        $total = (float) SharePosition::query()
            ->join('share_classes', 'share_classes.id', '=', 'share_positions.share_class_id')
            ->where('share_classes.register_id', $registerId)
            ->sum('share_positions.quantity');

        $register->total_units_outstanding = $total;

        if ($register->capital_behaviour_type === 'amortising') {
            $register->remaining_outstanding_units = $total;
        } else {
            $register->remaining_outstanding_units = $total;
        }

        $register->save();

        return $register;
    }

    public function assertConstantBalanced(int $registerId): void
    {
        $register = Register::findOrFail($registerId);
        if ($register->capital_behaviour_type !== 'constant') {
            return;
        }

        $this->syncOutstandingUnits($registerId);
        $paidUp = (float) ($register->paid_up_capital ?? 0);
        $current = (float) ($register->fresh()->total_units_outstanding ?? 0);

        if (abs($paidUp - $current) > self::EPSILON) {
            throw ValidationException::withMessages([
                'capital' => [
                    sprintf(
                        'Constant capital imbalance detected. Outstanding units %.6f must equal paid-up capital %.6f.',
                        $current,
                        $paidUp
                    ),
                ],
            ]);
        }
    }
}

