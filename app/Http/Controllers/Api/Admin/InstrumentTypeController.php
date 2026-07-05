<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\InstrumentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class InstrumentTypeController extends Controller
{
    /**
     * List all instrument types (active only by default).
     */
    public function index(Request $request): JsonResponse
    {
        $query = InstrumentType::query();

        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        return response()->json([
            'success' => true,
            'data'    => $query->orderBy('name')->get(),
        ]);
    }

    /**
     * Show a single instrument type.
     */
    public function show(InstrumentType $instrumentType): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $instrumentType,
        ]);
    }

    /**
     * Create a new (admin-defined) instrument type.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name'           => 'required|string|max:100',
                'code'           => 'required|string|max:50|unique:instrument_types,code|alpha_dash',
                'category'       => 'required|in:equity,debt,fund,derivative,money_market,alternative,other',
                'precision_rule' => 'required|in:decimal_only,whole_number_only,configurable',
                'description'    => 'nullable|string|max:1000',
            ]);

            $validated['is_seeded'] = false;
            $validated['is_active'] = true;

            $instrumentType = InstrumentType::create($validated);

            Log::info('Instrument type created', [
                'instrument_type_id' => $instrumentType->id,
                'code'               => $instrumentType->code,
                'created_by'         => $request->user()?->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Instrument type created successfully',
                'data'    => $instrumentType,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating instrument type: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error creating instrument type',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an instrument type (seeded types: description only; custom: all fields).
     */
    public function update(Request $request, InstrumentType $instrumentType): JsonResponse
    {
        try {
            if ($instrumentType->is_seeded) {
                $validated = $request->validate([
                    'description' => 'nullable|string|max:1000',
                ]);
            } else {
                $validated = $request->validate([
                    'name'           => 'required|string|max:100',
                    'category'       => 'required|in:equity,debt,fund,derivative,money_market,alternative,other',
                    'precision_rule' => 'required|in:decimal_only,whole_number_only,configurable',
                    'description'    => 'nullable|string|max:1000',
                ]);
            }

            $instrumentType->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Instrument type updated successfully',
                'data'    => $instrumentType->fresh(),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        }
    }

    /**
     * Deactivate an instrument type (seeded types cannot be deactivated).
     */
    public function deactivate(Request $request, InstrumentType $instrumentType): JsonResponse
    {
        if ($instrumentType->is_seeded) {
            return response()->json([
                'success' => false,
                'message' => 'Built-in instrument types cannot be deactivated.',
            ], 422);
        }

        if ($instrumentType->registers()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot deactivate an instrument type that is in use by one or more registers.',
            ], 422);
        }

        $instrumentType->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Instrument type deactivated successfully',
        ]);
    }
}
