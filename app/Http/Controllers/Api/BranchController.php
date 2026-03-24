<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BranchController extends Controller
{
    /**
     * GET /api/branches?company_id=X
     */
    public function index(Request $request)
    {
        $companyId = $request->query('company_id');

        try {
            $query = DB::table('branches')->orderBy('created_at', 'asc');
            if ($companyId) $query->where('company_id', (int) $companyId);

            return response()->json(['success' => true, 'branches' => $query->get()], 200);
        } catch (\Exception $e) {
            Log::error('[BranchController] index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/branches
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_id'     => 'required|integer|exists:company,id',
            'branch_name'    => 'required|string|max:150',
            'license_number' => 'nullable|string|max:100',
            'msa_start_date' => 'nullable|date',
            'msa_end_date'   => 'nullable|date',
        ]);

        try {
            $id = DB::table('branches')->insertGetId(array_merge($validated, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Branch created successfully.',
                'branch'  => DB::table('branches')->where('id', $id)->first(),
            ], 201);
        } catch (\Exception $e) {
            Log::error('[BranchController] store failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/branches/{id}
     *
     * Updates license_number, msa_start_date, msa_end_date (and optionally branch_name).
     *
     * Retail/Warehouse: the front-end calls this for EVERY branch belonging to the
     *   company in parallel — all branches share the same license & MSA dates.
     * Aloha: the front-end calls this for a single target branch.
     *
     * Expected JSON body (all fields optional):
     *   license_number  — string|null
     *   msa_start_date  — date (Y-m-d)|null
     *   msa_end_date    — date (Y-m-d)|null
     *   branch_name     — string|null
     */
    public function update(Request $request, int $id)
    {
        Log::info('[BranchController] update', ['id' => $id, 'payload' => $request->all()]);

        $validated = $request->validate([
            'license_number' => 'sometimes|nullable|string|max:100',
            'msa_start_date' => 'sometimes|nullable|date',
            'msa_end_date'   => 'sometimes|nullable|date',
            'branch_name'    => 'sometimes|nullable|string|max:150',
        ]);

        try {
            $affected = DB::table('branches')
                ->where('id', $id)
                ->update(array_merge($validated, ['updated_at' => now()]));

            // $affected === 0 means the row either doesn't exist or data was identical.
            $branch = DB::table('branches')->where('id', $id)->first();

            if (!$branch) {
                return response()->json([
                    'success' => false,
                    'message' => "No branch found with id {$id}.",
                ], 404);
            }

            Log::info('[BranchController] update success', ['id' => $id, 'affected' => $affected]);

            return response()->json([
                'success' => true,
                'message' => $affected > 0 ? 'Branch updated successfully.' : 'Branch data unchanged.',
                'branch'  => $branch,
            ], 200);

        } catch (\Exception $e) {
            Log::error('[BranchController] update failed', ['error' => $e->getMessage(), 'id' => $id]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/branches/{id}
     * Cascades to pos_machines via FK constraint.
     */
    public function destroy(int $id)
    {
        Log::info('[BranchController] destroy', ['id' => $id]);

        try {
            $affected = DB::table('branches')->where('id', $id)->delete();

            if ($affected === 0) {
                return response()->json(['success' => false, 'message' => "No branch found with id {$id}."], 404);
            }

            return response()->json(['success' => true, 'message' => 'Branch deleted successfully.'], 200);
        } catch (\Exception $e) {
            Log::error('[BranchController] destroy failed', ['error' => $e->getMessage(), 'id' => $id]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}