<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PosPeripheralController extends Controller
{
    /**
     * GET /api/peripherals?pos_id=X
     * Returns all peripherals for a given POS machine.
     */
    public function index(Request $request)
    {
        $posId     = $request->query('pos_id');
        $companyId = $request->query('company_id');
        $branchId  = $request->query('branch_id');

        Log::info('[PosPeripheralController] Fetching peripherals', [
            'pos_id'     => $posId,
            'company_id' => $companyId,
            'branch_id'  => $branchId,
        ]);

        try {
            $query = DB::table('pos_peripherals')->orderBy('created_at', 'asc');

            if ($posId) {
                $query->where('pos_id', (int) $posId);
            } elseif ($branchId) {
                $query->where('branch_id', (int) $branchId);
            } elseif ($companyId) {
                $query->where('company_id', (int) $companyId);
            }

            $peripherals = $query->get();

            Log::info('[PosPeripheralController] Peripherals fetched', [
                'count' => $peripherals->count(),
            ]);

            return response()->json([
                'success'     => true,
                'peripherals' => $peripherals,
            ], 200);

        } catch (\Exception $e) {
            Log::error('[PosPeripheralController] Failed to fetch peripherals', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch peripherals: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/peripherals
     *
     * Expected payload (JSON or FormData):
     *   pos_id        — int, required  (FK → pos_machines.id)
     *   company_id    — int, required  (FK → company.id)
     *   branch_id     — int, required  (FK → branches.id)
     *   model_name    — string, required
     *   serial_number — string, nullable
     *   warranty_date — date (Y-m-d), nullable
     */
    public function store(Request $request)
    {
        Log::info('[PosPeripheralController] Incoming store payload', $request->all());

        $validated = $request->validate([
            'pos_id'        => 'required|integer|exists:pos_machines,id',
            'company_id'    => 'required|integer|exists:company,id',
            'branch_id'     => 'required|integer|exists:branches,id',
            'model_name'    => 'required|string|max:255',
            'serial_number' => 'nullable|string|max:255',
            'warranty_date' => 'nullable|date',
        ]);

        try {
            $id = DB::table('pos_peripherals')->insertGetId([
                'pos_id'        => (int) $validated['pos_id'],
                'company_id'    => (int) $validated['company_id'],
                'branch_id'     => (int) $validated['branch_id'],
                'model_name'    => $validated['model_name'],
                'serial_number' => $validated['serial_number'] ?? null,
                'warranty_date' => $validated['warranty_date'] ?? null,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            $peripheral = DB::table('pos_peripherals')->where('id', $id)->first();

            Log::info('[PosPeripheralController] Peripheral inserted', ['id' => $id]);

            return response()->json([
                'success'    => true,
                'message'    => 'Peripheral created successfully.',
                'id'         => $id,
                'peripheral' => $peripheral,
            ], 201);

        } catch (\Exception $e) {
            Log::error('[PosPeripheralController] Insert failed', [
                'error'   => $e->getMessage(),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save peripheral: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/peripherals/{id}
     * Updates an existing peripheral record.
     */
    public function update(Request $request, int $id)
    {
        Log::info('[PosPeripheralController] Update peripheral', ['id' => $id, 'payload' => $request->all()]);

        $validated = $request->validate([
            'model_name'    => 'sometimes|required|string|max:255',
            'serial_number' => 'sometimes|nullable|string|max:255',
            'warranty_date' => 'sometimes|nullable|date',
        ]);

        try {
            $exists = DB::table('pos_peripherals')->where('id', $id)->exists();
            if (!$exists) {
                return response()->json([
                    'success' => false,
                    'message' => "No peripheral found with id {$id}.",
                ], 404);
            }

            DB::table('pos_peripherals')
                ->where('id', $id)
                ->update(array_merge($validated, ['updated_at' => now()]));

            $peripheral = DB::table('pos_peripherals')->where('id', $id)->first();

            Log::info('[PosPeripheralController] Peripheral updated', ['id' => $id]);

            return response()->json([
                'success'    => true,
                'message'    => 'Peripheral updated successfully.',
                'peripheral' => $peripheral,
            ], 200);

        } catch (\Exception $e) {
            Log::error('[PosPeripheralController] Update failed', [
                'error' => $e->getMessage(),
                'id'    => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update peripheral: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/peripherals/{id}
     * Deletes a single peripheral record.
     */
    public function destroy(int $id)
    {
        Log::info('[PosPeripheralController] Delete peripheral', ['id' => $id]);

        try {
            $affected = DB::table('pos_peripherals')->where('id', $id)->delete();

            if ($affected === 0) {
                return response()->json([
                    'success' => false,
                    'message' => "No peripheral found with id {$id}.",
                ], 404);
            }

            Log::info('[PosPeripheralController] Peripheral deleted', ['id' => $id]);

            return response()->json([
                'success' => true,
                'message' => 'Peripheral deleted successfully.',
            ], 200);

        } catch (\Exception $e) {
            Log::error('[PosPeripheralController] Delete failed', [
                'error' => $e->getMessage(),
                'id'    => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete peripheral: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/peripherals/by-pos/{posId}
     * Deletes ALL peripherals belonging to a POS machine.
     * Called when a POS machine itself is deleted.
     */
    public function destroyByPos(int $posId)
    {
        Log::info('[PosPeripheralController] Delete all peripherals for POS', ['pos_id' => $posId]);

        try {
            $count = DB::table('pos_peripherals')->where('pos_id', $posId)->delete();

            Log::info('[PosPeripheralController] Peripherals deleted for POS', [
                'pos_id' => $posId,
                'count'  => $count,
            ]);

            return response()->json([
                'success' => true,
                'message' => "{$count} peripheral(s) deleted for POS {$posId}.",
                'count'   => $count,
            ], 200);

        } catch (\Exception $e) {
            Log::error('[PosPeripheralController] destroyByPos failed', [
                'error'  => $e->getMessage(),
                'pos_id' => $posId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete peripherals: ' . $e->getMessage(),
            ], 500);
        }
    }
}