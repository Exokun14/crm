<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BranchLimiterController extends Controller
{
    /* ──────────────────────────────────────────────────────────────
     * HELPER — counts how many branches belong to a company
     * and syncs that number into branch_limiter.branch_counter.
     * Returns the live count so callers can use it immediately.
     * ────────────────────────────────────────────────────────────── */
    private function syncBranchCounter(int $companyId): int
    {
        $liveCount = DB::table('branches')
            ->where('company_id', $companyId)
            ->count();

        DB::table('branch_limiter')
            ->where('company_id', $companyId)
            ->update([
                'branch_counter' => $liveCount,
                'updated_at'     => now(),
            ]);

        return $liveCount;
    }

    /* ──────────────────────────────────────────────────────────────
     * GET /api/branch-limiter?company_id=X
     *
     * Returns the branch_limiter row for the given company.
     * branch_counter is automatically synced to the live count of
     * branches that belong to this company before responding.
     * NOTE: Aloha companies are excluded — no rows are created for them.
     * ────────────────────────────────────────────────────────────── */
    public function index(Request $request)
    {
        $companyId = $request->query('company_id');

        try {
            if ($companyId) {
                $cid = (int) $companyId;

                /* Verify the company exists */
                $company = DB::table('company')->where('id', $cid)->first();
                if (!$company) {
                    return response()->json([
                        'success' => false,
                        'message' => "Company {$cid} not found.",
                    ], 404);
                }

                /* ── Aloha guard ──────────────────────────────────────────
                 * Resolve the company's industry type via industry_cards.
                 * If the industry title contains "aloha" (case-insensitive),
                 * we do NOT create or sync a branch_limiter row — just return
                 * empty data so the front-end shows nothing.
                 * ────────────────────────────────────────────────────────── */
                $industryTitle = DB::table('industry_cards')
                    ->where('id', $company->industry_card_id ?? null)
                    ->value('title');

                $isAloha = $industryTitle && stripos($industryTitle, 'aloha') !== false;

                if ($isAloha) {
                    return response()->json(['success' => true, 'data' => []], 200);
                }

                /* Auto-create row if it does not exist yet (non-Aloha only) */
                $exists = DB::table('branch_limiter')->where('company_id', $cid)->exists();
                if (!$exists) {
                    DB::table('branch_limiter')->insert([
                        'company_id'     => $cid,
                        'branch_counter' => 0,
                        'branch_limiter' => 0,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                }

                /* Sync branch_counter with live branches count */
                $this->syncBranchCounter($cid);

                $rows = DB::table('branch_limiter')->where('company_id', $cid)->get();
            } else {
                $rows = DB::table('branch_limiter')->get();
            }

            return response()->json(['success' => true, 'data' => $rows], 200);

        } catch (\Exception $e) {
            Log::error('[BranchLimiterController] index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /* ──────────────────────────────────────────────────────────────
     * POST /api/branch-limiter
     *
     * Creates a branch_limiter record for a company.
     * branch_counter is ignored from the request — it is always
     * calculated from the live branches count.
     * ────────────────────────────────────────────────────────────── */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_id'     => 'required|integer|exists:company,id',
            'branch_limiter' => 'nullable|integer|min:0',
        ]);

        try {
            $cid = (int) $validated['company_id'];

            /* ── Aloha guard — refuse to create rows for Aloha companies ── */
            $company = DB::table('company')->where('id', $cid)->first();
            $industryTitle = $company
                ? DB::table('industry_cards')->where('id', $company->industry_card_id ?? null)->value('title')
                : null;
            if ($industryTitle && stripos($industryTitle, 'aloha') !== false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Branch limiter is not applicable to Aloha companies.',
                ], 422);
            }

            /* Prevent duplicate rows per company */
            $existing = DB::table('branch_limiter')->where('company_id', $cid)->first();
            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => "A branch_limiter record already exists for company {$cid}. Use PUT to update it.",
                    'data'    => $existing,
                ], 409);
            }

            /* Compute live branch count for this company */
            $liveCount = DB::table('branches')->where('company_id', $cid)->count();

            $id = DB::table('branch_limiter')->insertGetId([
                'company_id'     => $cid,
                'branch_counter' => $liveCount,
                'branch_limiter' => $validated['branch_limiter'] ?? 0,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Branch limiter record created.',
                'data'    => DB::table('branch_limiter')->where('id', $id)->first(),
            ], 201);

        } catch (\Exception $e) {
            Log::error('[BranchLimiterController] store failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /* ──────────────────────────────────────────────────────────────
     * PUT /api/branch-limiter/{id}
     *
     * Updates branch_limiter (the cap) for a row.
     * branch_counter is always re-synced from the live branches
     * count — it cannot be set manually.
     * ────────────────────────────────────────────────────────────── */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'branch_limiter' => 'required|integer|min:0',
        ]);

        try {
            $row = DB::table('branch_limiter')->where('id', $id)->first();

            if (!$row) {
                return response()->json([
                    'success' => false,
                    'message' => "No branch_limiter record found with id {$id}.",
                ], 404);
            }

            /* Sync branch_counter from live count, then apply new limiter */
            $liveCount = DB::table('branches')
                ->where('company_id', $row->company_id)
                ->count();

            DB::table('branch_limiter')->where('id', $id)->update([
                'branch_counter' => $liveCount,
                'branch_limiter' => $validated['branch_limiter'],
                'updated_at'     => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Branch limiter updated.',
                'data'    => DB::table('branch_limiter')->where('id', $id)->first(),
            ], 200);

        } catch (\Exception $e) {
            Log::error('[BranchLimiterController] update failed', ['error' => $e->getMessage(), 'id' => $id]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /* ──────────────────────────────────────────────────────────────
     * DELETE /api/branch-limiter/{id}
     * ────────────────────────────────────────────────────────────── */
    public function destroy(int $id)
    {
        try {
            $affected = DB::table('branch_limiter')->where('id', $id)->delete();

            if ($affected === 0) {
                return response()->json([
                    'success' => false,
                    'message' => "No branch_limiter record found with id {$id}.",
                ], 404);
            }

            return response()->json(['success' => true, 'message' => 'Deleted successfully.'], 200);

        } catch (\Exception $e) {
            Log::error('[BranchLimiterController] destroy failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}