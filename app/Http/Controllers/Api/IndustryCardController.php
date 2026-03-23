<?php
/* IndustryCardController.php
 * app\Http\Controllers\Api\IndustryCardController.php */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IndustryCardController extends Controller
{
    /**
     * GET /api/industry-cards
     * Return all industry cards ordered by created_at ASC
     * (oldest first — so card #1 is always the first created).
     */
    public function index(): \Illuminate\Http\JsonResponse
    {
        $cards = DB::table('industry_cards')
            ->orderBy('created_at', 'asc')   // ← was 'desc', now 'asc'
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $cards,
        ]);
    }

    /**
     * POST /api/industry-cards
     * Insert a new industry card.
     *
     * Expected body (JSON):
     *   icon      string  required  (varchar 50)
     *   title     string  required  (varchar 100)
     *   sub_title string  nullable  (varchar 150)
     *   color     string  nullable  (varchar 30)  — hex e.g. #7c3aed
     *   count     int     optional  default 0
     *   tickets   int     optional  default 0
     */
    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'icon'      => 'required|string|max:50',
            'title'     => 'required|string|max:100',
            'sub_title' => 'nullable|string|max:150',
            'color'     => 'nullable|string|max:30',
            'count'     => 'nullable|integer|min:0',
            'tickets'   => 'nullable|integer|min:0',
        ]);

        try {
            $id = DB::table('industry_cards')->insertGetId([
                'icon'      => $validated['icon'],
                'title'     => $validated['title'],
                'sub_title' => $validated['sub_title'] ?? null,
                'color'     => $validated['color']     ?? null,
                'count'     => $validated['count']     ?? 0,
                'tickets'   => $validated['tickets']   ?? 0,
                // created_at has DEFAULT CURRENT_TIMESTAMP — no need to set manually
            ]);
        } catch (\Throwable $e) {
            Log::error('IndustryCard insert failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to save industry card. Please try again.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'id'      => $id,
            'message' => 'Industry card saved successfully.',
        ], 201);
    }

    /**
     * PUT /api/industry-cards/{id}
     * Update an existing industry card.
     */
    public function update(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $card = DB::table('industry_cards')->where('id', $id)->first();
        if (!$card) {
            return response()->json(['success' => false, 'message' => 'Industry card not found.'], 404);
        }

        $validated = $request->validate([
            'icon'      => 'sometimes|required|string|max:50',
            'title'     => 'sometimes|required|string|max:100',
            'sub_title' => 'nullable|string|max:150',
            'color'     => 'nullable|string|max:30',
            'count'     => 'nullable|integer|min:0',
            'tickets'   => 'nullable|integer|min:0',
        ]);

        try {
            DB::table('industry_cards')->where('id', $id)->update($validated);
        } catch (\Throwable $e) {
            Log::error('IndustryCard update failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to update industry card.',
            ], 500);
        }

        return response()->json(['success' => true, 'id' => $id, 'message' => 'Industry card updated.']);
    }

    /**
     * DELETE /api/industry-cards/{id}
     */
    public function destroy(int $id): \Illuminate\Http\JsonResponse
    {
        $deleted = DB::table('industry_cards')->where('id', $id)->delete();
        if (!$deleted) {
            return response()->json(['success' => false, 'message' => 'Industry card not found.'], 404);
        }
        return response()->json(['success' => true, 'message' => 'Industry card deleted.']);
    }
}









