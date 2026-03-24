<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProgressController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('user_course_progress');

        // FIX: Scope to the logged-in user only.
        // Previously this returned ALL rows because user_id was never
        // filtered. Now only the authenticated user's records are returned.
        $query->where('user_id', Auth::id());

        if ($request->has('company')) {
            $query->where('company', $request->company);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'             => 'required|string',
            'company'          => 'required|string',
            'course'           => 'required|string',
            'progress'         => 'required|integer|min:0|max:100',
            'started'          => 'required|date',
            'completed'        => 'nullable|date',
            'status'           => 'required|in:Not Started,In Progress,Completed',
            'time_spent'       => 'nullable|integer',
            'assessment_score' => 'nullable|integer',
        ]);

        // FIX: Save user_id on creation so records can be scoped per user.
        // Previously user_id was always NULL, making it impossible to filter
        // by user in index().
        $id = DB::table('user_course_progress')->insertGetId(array_merge($validated, [
            'user_id'    => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return response()->json(['id' => $id, 'message' => 'Progress created'], 201);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'progress'         => 'sometimes|integer|min:0|max:100',
            'completed'        => 'nullable|date',
            'status'           => 'sometimes|in:Not Started,In Progress,Completed',
            'time_spent'       => 'nullable|integer|min:0',
            'assessment_score' => 'nullable|integer',
        ]);

        // FIX: Scope the lookup to the logged-in user so users cannot
        // update each other's progress records by guessing an ID.
        $existing = DB::table('user_course_progress')
            ->where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$existing) {
            return response()->json(['error' => 'Progress record not found'], 404);
        }

        $updateData = ['updated_at' => now()];

        if (isset($validated['progress']))         $updateData['progress']         = $validated['progress'];
        if (isset($validated['completed']))        $updateData['completed']        = $validated['completed'];
        if (isset($validated['status']))           $updateData['status']           = $validated['status'];
        if (isset($validated['assessment_score'])) $updateData['assessment_score'] = $validated['assessment_score'];

        // Accumulate time_spent (stored in seconds) — never overwrite with a smaller value
        if (isset($validated['time_spent'])) {
            $delta       = max(0, (int) $validated['time_spent']);
            $currentTime = max(0, (int) ($existing->time_spent ?? 0));
            // Cap per-call delta at 7200 seconds (2 hours) to guard against stale tabs
            $updateData['time_spent'] = $currentTime + min($delta, 7200);
        }

        DB::table('user_course_progress')
            ->where('id', $id)
            ->where('user_id', Auth::id())
            ->update($updateData);

        return response()->json(['message' => 'Progress updated']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /progress/chapters?course_id={id}
    // Returns all chapter completion rows for the authenticated user,
    // optionally scoped to chapters belonging to a specific course.
    // ─────────────────────────────────────────────────────────────────────────
    public function chapterProgress(Request $request)
    {
        $query = DB::table('user_chapter_progress')
            ->where('user_chapter_progress.user_id', Auth::id());

        if ($request->has('course_id')) {
            $query->join('chapters', 'user_chapter_progress.chapter_id', '=', 'chapters.id')
                  ->join('modules',  'chapters.module_id',               '=', 'modules.id')
                  ->where('modules.course_id', $request->course_id)
                  ->select('user_chapter_progress.*');
        }

        return response()->json($query->get());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /progress/modules?course_id={id}
    // Returns all module completion rows for the authenticated user,
    // optionally scoped to modules belonging to a specific course.
    // ─────────────────────────────────────────────────────────────────────────
    public function moduleProgress(Request $request)
    {
        $query = DB::table('user_module_progress')
            ->where('user_module_progress.user_id', Auth::id());

        if ($request->has('course_id')) {
            $query->join('modules', 'user_module_progress.module_id', '=', 'modules.id')
                  ->where('modules.course_id', $request->course_id)
                  ->select('user_module_progress.*');
        }

        return response()->json($query->get());
    }
}
