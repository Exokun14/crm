<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AddPosController extends Controller
{
    /**
     * GET /api/pos?branch_id=X
     * Returns all POS machines for a given branch.
     */
    public function index(Request $request)
    {
        $branchId = $request->query('branch_id');

        Log::info('[AddPosController] Fetching POS machines', ['branch_id' => $branchId]);

        try {
            $query = DB::table('pos_machines')
                ->orderBy('created_at', 'asc');

            if ($branchId) {
                $query->where('branch_id', (int) $branchId);
            }

            $machines = $query->get();

            Log::info('[AddPosController] POS machines fetched', [
                'branch_id' => $branchId,
                'count'     => $machines->count(),
            ]);

            return response()->json([
                'success'      => true,
                'pos_machines' => $machines,
            ], 200);

        } catch (\Exception $e) {
            Log::error('[AddPosController] Failed to fetch POS machines', [
                'error'     => $e->getMessage(),
                'branch_id' => $branchId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch POS machines: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/pos
     *
     * Expected payload (FormData or JSON):
     *   branch_id        — int, required  (FK → branches.id)
     *   model            — string, required
     *   serial_number    — string, required
     *   operating_system — string, required
     *   warranty_date    — date (Y-m-d), nullable
     */
    public function store(Request $request)
    {
        Log::info('[AddPosController] Incoming request payload', $request->all());

        $validated = $request->validate([
            'branch_id'        => 'required|integer|exists:branches,id',
            'model'            => 'required|string|max:100',
            'serial_number'    => 'required|string|max:100',
            'operating_system' => 'required|string|max:100',
            'warranty_date'    => 'nullable|date',
        ]);

        try {
            $id = DB::table('pos_machines')->insertGetId([
                'branch_id'        => (int) $validated['branch_id'],
                'model'            => $validated['model'],
                'serial_number'    => $validated['serial_number'],
                'operating_system' => $validated['operating_system'],
                'warranty_date'    => $validated['warranty_date'] ?? null,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            Log::info('[AddPosController] POS machine inserted successfully', [
                'inserted_id'   => $id,
                'branch_id'     => $validated['branch_id'],
                'model'         => $validated['model'],
                'serial_number' => $validated['serial_number'],
            ]);

            // Return the full row so the front-end can hydrate its local state
            $machine = DB::table('pos_machines')->where('id', $id)->first();

            return response()->json([
                'success'     => true,
                'message'     => 'POS machine created successfully.',
                'id'          => $id,
                'pos_machine' => $machine,
            ], 201);

        } catch (\Exception $e) {
            Log::error('[AddPosController] DB insert failed', [
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save POS machine: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/pos/{id}
     * Updates an existing POS machine record.
     */
    public function update(Request $request, int $id)
    {
        Log::info('[AddPosController] Update POS machine', ['id' => $id, 'payload' => $request->all()]);

        $validated = $request->validate([
            'model'            => 'sometimes|required|string|max:100',
            'serial_number'    => 'sometimes|required|string|max:100',
            'operating_system' => 'sometimes|required|string|max:100',
            'warranty_date'    => 'sometimes|nullable|date',
        ]);

        try {
            $affected = DB::table('pos_machines')
                ->where('id', $id)
                ->update(array_merge($validated, ['updated_at' => now()]));

            if ($affected === 0) {
                // Check if row exists at all
                $exists = DB::table('pos_machines')->where('id', $id)->exists();
                if (!$exists) {
                    return response()->json([
                        'success' => false,
                        'message' => "No POS machine found with id {$id}.",
                    ], 404);
                }
                // Row exists but data was unchanged — still a success
            }

            $machine = DB::table('pos_machines')->where('id', $id)->first();

            Log::info('[AddPosController] POS machine updated', ['id' => $id]);

            return response()->json([
                'success'     => true,
                'message'     => 'POS machine updated successfully.',
                'pos_machine' => $machine,
            ], 200);

        } catch (\Exception $e) {
            Log::error('[AddPosController] Update failed', [
                'error' => $e->getMessage(),
                'id'    => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update POS machine: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/pos/{id}
     * Deletes a POS machine record.
     */
    public function destroy(int $id)
    {
        Log::info('[AddPosController] Delete POS machine', ['id' => $id]);

        try {
            $affected = DB::table('pos_machines')->where('id', $id)->delete();

            if ($affected === 0) {
                return response()->json([
                    'success' => false,
                    'message' => "No POS machine found with id {$id}.",
                ], 404);
            }

            Log::info('[AddPosController] POS machine deleted', ['id' => $id]);

            return response()->json([
                'success' => true,
                'message' => 'POS machine deleted successfully.',
            ], 200);

        } catch (\Exception $e) {
            Log::error('[AddPosController] Delete failed', [
                'error' => $e->getMessage(),
                'id'    => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete POS machine: ' . $e->getMessage(),
            ], 500);
        }
    }
}