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
     *
     * Before inserting, checks branch_limiter for this company.
     * If branch_counter >= branch_limiter (and limiter > 0), the
     * request is rejected with a 422 and a clear error message.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_id'          => 'required|integer|exists:company,id',
            'branch_name'         => 'required|string|max:150',
            'license_number'      => 'nullable|string|max:100',
            'msa_start_date'      => 'nullable|date',
            'msa_end_date'        => 'nullable|date',
            'implementation_date' => 'nullable|date',
        ]);

        try {
            $cid = (int) $validated['company_id'];

            /* ── Branch limit enforcement ──────────────────────────────
             * Look up the branch_limiter row for this company.
             * If branch_limiter > 0 and the live branch count has already
             * reached or exceeded it, block the creation.
             * ────────────────────────────────────────────────────────── */
            $limiterRow = DB::table('branch_limiter')
                ->where('company_id', $cid)
                ->first();

            if ($limiterRow && $limiterRow->branch_limiter > 0) {
                /* Use the live branch count rather than branch_counter
                   to avoid any stale cache issues */
                $liveCount = DB::table('branches')
                    ->where('company_id', $cid)
                    ->count();

                if ($liveCount >= $limiterRow->branch_limiter) {
                    /* Sync branch_counter so the DB stays accurate */
                    DB::table('branch_limiter')
                        ->where('company_id', $cid)
                        ->update([
                            'branch_counter' => $liveCount,
                            'updated_at'     => now(),
                        ]);

                    Log::warning('[BranchController] branch limit reached', [
                        'company_id'     => $cid,
                        'live_count'     => $liveCount,
                        'branch_limiter' => $limiterRow->branch_limiter,
                    ]);

                    return response()->json([
                        'success'        => false,
                        'limit_reached'  => true,
                        'branch_counter' => $liveCount,
                        'branch_limiter' => $limiterRow->branch_limiter,
                        'message'        => "Branch limit reached. This company has already used {$liveCount} of its {$limiterRow->branch_limiter} allowed branch" . ($limiterRow->branch_limiter !== 1 ? 'es' : '') . '. Please contact your account manager to increase the quota.',
                    ], 422);
                }
            }

            /* ── Insert the new branch ── */
            $id = DB::table('branches')->insertGetId(array_merge($validated, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            /* Sync branch_counter after successful insert */
            if ($limiterRow) {
                $newCount = DB::table('branches')->where('company_id', $cid)->count();
                DB::table('branch_limiter')
                    ->where('company_id', $cid)
                    ->update([
                        'branch_counter' => $newCount,
                        'updated_at'     => now(),
                    ]);
            }

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
     */
    public function update(Request $request, int $id)
    {
        Log::info('[BranchController] update', ['id' => $id, 'payload' => $request->all()]);

        $validated = $request->validate([
            'license_number'      => 'sometimes|nullable|string|max:100',
            'msa_start_date'      => 'sometimes|nullable|date',
            'msa_end_date'        => 'sometimes|nullable|date',
            'branch_name'         => 'sometimes|nullable|string|max:150',
            'implementation_date' => 'sometimes|nullable|date',
        ]);

        try {
            $affected = DB::table('branches')
                ->where('id', $id)
                ->update(array_merge($validated, ['updated_at' => now()]));

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
     * Also syncs branch_counter down after deletion.
     */
    public function destroy(int $id)
    {
        Log::info('[BranchController] destroy', ['id' => $id]);

        try {
            /* Get company_id before deleting so we can sync counter */
            $branch = DB::table('branches')->where('id', $id)->first();

            $affected = DB::table('branches')->where('id', $id)->delete();

            if ($affected === 0) {
                return response()->json(['success' => false, 'message' => "No branch found with id {$id}."], 404);
            }

            /* Sync branch_counter down after deletion */
            if ($branch) {
                $newCount = DB::table('branches')->where('company_id', $branch->company_id)->count();
                DB::table('branch_limiter')
                    ->where('company_id', $branch->company_id)
                    ->update([
                        'branch_counter' => $newCount,
                        'updated_at'     => now(),
                    ]);
            }

            return response()->json(['success' => true, 'message' => 'Branch deleted successfully.'], 200);

        } catch (\Exception $e) {
            Log::error('[BranchController] destroy failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}