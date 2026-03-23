<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProgressController extends Controller
{
    // GET /progress
    // Returns progress records for the authenticated user, enriched with
    // course title and company name via joins (no string duplication).
    public function index(Request $request)
    {
        $userId = Auth::id() ?? (int) $request->header('X-User-Id', 1);

        $query = DB::table('user_course_progress as ucp')
            ->join('courses',  'ucp.course_id',  '=', 'courses.id')
            ->join('users',    'ucp.user_id',    '=', 'users.id')
            ->leftJoin('company', 'users.company_id', '=', 'company.id')
            ->where('ucp.user_id', $userId)
            ->select(
                'ucp.*',
                'courses.title  as course',        // resolved from FK
                'company.company_name as company'  // resolved via users.company_id
            );

        if ($request->filled('status')) {
            $query->where('ucp.status', $request->status);
        }
        if ($request->filled('course_id')) {
            $query->where('ucp.course_id', $request->course_id);
        }

        return response()->json($query->get());
    }

    // POST /progress
    // Creates a progress record. Accepts course_id (preferred) or falls back
    // to looking up the course by title for backwards compat.
    public function store(Request $request)
    {
        $userId = Auth::id() ?? (int) $request->header('X-User-Id', 1);

        $validated = $request->validate([
            'course_id'        => 'required_without:course|integer|exists:courses,id',
            'course'           => 'required_without:course_id|string',
            'progress'         => 'required|integer|min:0|max:100',
            'started'          => 'required|date',
            'completed'        => 'nullable|date',
            'status'           => 'required|in:Not Started,In Progress,Completed',
            'time_spent'       => 'nullable|integer',
            'assessment_score' => 'nullable|integer',
        ]);

        // Resolve course_id from title if not provided directly
        $courseId = $validated['course_id'] ?? DB::table('courses')
            ->where('title', $validated['course'])
            ->value('id');

        if (!$courseId) {
            return response()->json(['error' => 'Course not found'], 404);
        }

        // Use updateOrInsert so duplicate calls are safe
        DB::table('user_course_progress')->updateOrInsert(
            ['user_id' => $userId, 'course_id' => $courseId],
            [
                'progress'         => $validated['progress'],
                'started'          => $validated['started'],
                'completed'        => $validated['completed'] ?? null,
                'status'           => $validated['status'],
                'time_spent'       => $validated['time_spent'] ?? 0,
                'assessment_score' => $validated['assessment_score'] ?? null,
                'updated_at'       => now(),
                'created_at'       => now(),
            ]
        );

        $record = DB::table('user_course_progress')
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->first();

        return response()->json(['id' => $record->id, 'message' => 'Progress saved'], 201);
    }

    // PUT /progress/{id}
    public function update(Request $request, $id)
    {
        $userId = Auth::id() ?? (int) $request->header('X-User-Id', 1);

        $validated = $request->validate([
            'progress'         => 'sometimes|integer|min:0|max:100',
            'completed'        => 'nullable|date',
            'status'           => 'sometimes|in:Not Started,In Progress,Completed',
            'time_spent'       => 'nullable|integer|min:0',
            'assessment_score' => 'nullable|integer',
        ]);

        $existing = DB::table('user_course_progress')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$existing) {
            return response()->json(['error' => 'Progress record not found'], 404);
        }

        $updateData = ['updated_at' => now()];

        if (isset($validated['progress']))         $updateData['progress']         = $validated['progress'];
        if (isset($validated['completed']))        $updateData['completed']        = $validated['completed'];
        if (isset($validated['status']))           $updateData['status']           = $validated['status'];
        if (isset($validated['assessment_score'])) $updateData['assessment_score'] = $validated['assessment_score'];

        if (isset($validated['time_spent'])) {
            $delta                    = max(0, (int) $validated['time_spent']);
            $updateData['time_spent'] = max(0, (int)($existing->time_spent ?? 0)) + min($delta, 480);
        }

        DB::table('user_course_progress')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->update($updateData);

        return response()->json(['message' => 'Progress updated']);
    }

    // GET /progress/chapters?course_id={id}
    public function chapterProgress(Request $request)
    {
        $userId = Auth::id() ?? (int) $request->header('X-User-Id', 1);

        $query = DB::table('user_chapter_progress')
            ->where('user_chapter_progress.user_id', $userId);

        if ($request->filled('course_id')) {
            $query->join('chapters', 'user_chapter_progress.chapter_id', '=', 'chapters.id')
                  ->join('modules',  'chapters.module_id', '=', 'modules.id')
                  ->where('modules.course_id', $request->course_id)
                  ->select('user_chapter_progress.*');
        }

        return response()->json($query->get());
    }

    // GET /progress/modules?course_id={id}
    public function moduleProgress(Request $request)
    {
        $userId = Auth::id() ?? (int) $request->header('X-User-Id', 1);

        $query = DB::table('user_module_progress')
            ->where('user_module_progress.user_id', $userId);

        if ($request->filled('course_id')) {
            $query->join('modules', 'user_module_progress.module_id', '=', 'modules.id')
                  ->where('modules.course_id', $request->course_id)
                  ->select('user_module_progress.*');
        }

        return response()->json($query->get());
    }
}
