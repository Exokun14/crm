<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CourseController extends Controller
{
    const VALID_STAGES = ['draft', 'review_ready', 'published', 'unpublished', 'template'];

    // ─────────────────────────────────────────────────────────────────────────
    // GET /courses
    // ─────────────────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        Log::channel('stderr')->info('[CourseController@index] ▶ params: ' . json_encode($request->all()));

        $query = DB::table('courses');

        if ($request->filled('category') && $request->category !== 'All') {
            $query->where('cat', $request->category);
        }
        if ($request->has('active')) {
            $query->where('active', $request->active);
        }
        if ($request->has('stage')) {
            $query->where('stage', $request->stage);
        }

        // Exclude templates by default unless explicitly requested
        if (!$request->has('stage') && !$request->boolean('include_templates')) {
            $query->where('stage', '!=', 'template');
        }

        // FIX: filter by company_id using company_course pivot
        if ($request->filled('client_id')) {
            $query->join('company_course', 'courses.id', '=', 'company_course.course_id')
                  ->where('company_course.company_id', $request->client_id)
                  ->select('courses.*');
        }

        $courses = $query->get();

        foreach ($courses as $course) {
            $course->companies = $this->getCourseCompanies($course->id);
            $course->modules   = [];
        }

        Log::channel('stderr')->info('[CourseController@index] ✅ Returning ' . $courses->count() . ' courses');
        return response()->json($courses);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /courses/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function show($id)
    {
        Log::channel('stderr')->info('[CourseController@show] ▶ id=' . $id);

        $course = DB::table('courses')->where('id', $id)->first();

        if (!$course) {
            return response()->json(['error' => 'Course not found'], 404);
        }

        $course->companies = $this->getCourseCompanies($id);
        $course->modules   = $this->getCourseModules($id);

        Log::channel('stderr')->info('[CourseController@show] ✅ modules=' . count($course->modules));
        return response()->json($course);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /courses
    // ─────────────────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'desc'        => 'nullable|string',
            'time'        => 'required|string',
            'cat'         => 'required|string',
            'thumb'       => 'nullable|string',
            'thumb_emoji' => 'nullable|string',
            'companies'   => 'nullable|array',
            'stage'       => 'nullable|string|in:draft,review_ready,published,unpublished,template',
        ]);

        $stage  = $validated['stage'] ?? 'draft';
        $active = $stage === 'published';

        $courseId = DB::table('courses')->insertGetId([
            'title'       => $validated['title'],
            'desc'        => $validated['desc'] ?? '',
            'time'        => $validated['time'],
            'cat'         => $validated['cat'],
            'thumb'       => $validated['thumb'] ?? null,
            'thumb_emoji' => $validated['thumb_emoji'] ?? '📚',
            'active'      => $active,
            'stage'       => $stage,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        if (!empty($validated['companies'])) {
            $this->syncCompanies($courseId, $validated['companies']);
        }

        Log::channel('stderr')->info('[CourseController@store] ✅ Created course id=' . $courseId);
        return response()->json(['id' => $courseId, 'message' => 'Course created successfully'], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /courses/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'desc'        => 'nullable|string',
            'time'        => 'sometimes|string',
            'cat'         => 'sometimes|string',
            'thumb'       => 'nullable|string',
            'thumb_emoji' => 'nullable|string',
            'active'      => 'sometimes|boolean',
            'companies'   => 'nullable|array',
            'stage'       => 'nullable|string|in:draft,review_ready,published,unpublished,template',
        ]);

        $existing = DB::table('courses')->where('id', $id)->first();
        if (!$existing) {
            return response()->json(['error' => 'Course not found'], 404);
        }

        $updateData = ['updated_at' => now()];

        if (isset($validated['title']))       $updateData['title']       = $validated['title'];
        if (isset($validated['desc']))        $updateData['desc']        = $validated['desc'];
        if (isset($validated['time']))        $updateData['time']        = $validated['time'];
        if (isset($validated['cat']))         $updateData['cat']         = $validated['cat'];
        if (isset($validated['thumb']))       $updateData['thumb']       = $validated['thumb'];
        if (isset($validated['thumb_emoji'])) $updateData['thumb_emoji'] = $validated['thumb_emoji'];

        if (isset($validated['stage'])) {
            $updateData['stage']  = $validated['stage'];
            $updateData['active'] = $validated['stage'] === 'published';
        } elseif (isset($validated['active'])) {
            $updateData['active'] = $validated['active'];
            $updateData['stage']  = $validated['active'] ? 'published' : 'unpublished';
        }

        DB::table('courses')->where('id', $id)->update($updateData);

        if (array_key_exists('companies', $validated)) {
            DB::table('company_course')->where('course_id', $id)->delete();
            if (!empty($validated['companies'])) {
                $this->syncCompanies($id, $validated['companies']);
            }
        }

        Log::channel('stderr')->info('[CourseController@update] ✅ Updated course id=' . $id);
        return response()->json(['message' => 'Course updated successfully']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /courses/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function destroy($id)
    {
        DB::table('courses')->where('id', $id)->delete();
        return response()->json(['message' => 'Course deleted successfully']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /courses/{id}/progress
    // Upserts into user_course_progress — never touches the shared courses row.
    // ─────────────────────────────────────────────────────────────────────────
    public function updateProgress(Request $request, $id)
    {
        $userId = Auth::id() ?? (int) $request->header('X-User-Id', 1);

        $validated = $request->validate([
            'progress'   => 'required|numeric|min:0|max:100',
            'enrolled'   => 'sometimes|nullable',
            'completed'  => 'sometimes|nullable',
            'time_spent' => 'sometimes|numeric|min:0',
        ]);

        $progress    = (int) round((float) $validated['progress']);
        $isCompleted = $progress >= 100
            || in_array($validated['completed'] ?? null, [true, 1, '1', 'true'], true);

        $course = DB::table('courses')->where('id', $id)->first();
        if (!$course) {
            return response()->json(['error' => 'Course not found'], 404);
        }

        // FIX: upsert by (user_id, course_id) — not by course name string
        $existing = DB::table('user_course_progress')
            ->where('user_id',  $userId)
            ->where('course_id', $id)
            ->first();

        $status = $isCompleted ? 'Completed' : ($progress > 0 ? 'In Progress' : 'Not Started');

        if ($existing) {
            $updateData = [
                'progress'   => $progress,
                'status'     => $status,
                'updated_at' => now(),
            ];
            if ($isCompleted && !$existing->completed) {
                $updateData['completed'] = now()->toDateString();
            }
            if (isset($validated['time_spent'])) {
                $delta                    = max(0, (int) $validated['time_spent']);
                $updateData['time_spent'] = max(0, (int)($existing->time_spent ?? 0)) + min($delta, 480);
            }
            DB::table('user_course_progress')->where('id', $existing->id)->update($updateData);
        } else {
            DB::table('user_course_progress')->insert([
                'user_id'    => $userId,
                'course_id'  => $id,
                'progress'   => $progress,
                'status'     => $status,
                'started'    => now()->toDateString(),
                'completed'  => $isCompleted ? now()->toDateString() : null,
                'time_spent' => isset($validated['time_spent']) ? min(max(0, (int)$validated['time_spent']), 480) : 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Progress updated successfully']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /courses/{id}/modules
    // ─────────────────────────────────────────────────────────────────────────
    public function updateModules(Request $request, $id)
    {
        $validated = $request->validate([
            'modules' => 'required|array',
        ]);

        if (!DB::table('courses')->where('id', $id)->exists()) {
            return response()->json(['error' => 'Course not found'], 404);
        }

        // Replace all modules + chapters (chapters cascade via FK)
        DB::table('modules')->where('course_id', $id)->delete();

        $savedModules  = 0;
        $savedChapters = 0;

        foreach ($validated['modules'] as $index => $moduleData) {
            $moduleId = DB::table('modules')->insertGetId([
                'course_id'  => $id,
                'title'      => $moduleData['title'],
                'done'       => $moduleData['done'] ?? false,
                'order'      => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $savedModules++;

            foreach ($moduleData['chapters'] ?? [] as $chIndex => $chapterData) {
                DB::table('chapters')->insert([
                    'module_id'  => $moduleId,
                    'title'      => $chapterData['title'],
                    'type'       => $chapterData['type'],
                    'done'       => $chapterData['done'] ?? false,
                    'order'      => $chIndex,
                    'content'    => json_encode($chapterData['content'] ?? []),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $savedChapters++;
            }
        }

        Log::channel('stderr')->info('[CourseController@updateModules] ✅ ' . $savedModules . ' modules, ' . $savedChapters . ' chapters saved for course id=' . $id);
        return response()->json(['message' => 'Modules updated successfully']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /chapters/{chapterId}/done
    // Writes to per-user progress tables — never touches shared chapters row.
    // ─────────────────────────────────────────────────────────────────────────
    public function markChapterDone(Request $request, $chapterId)
    {
        $userId = Auth::id() ?? (int) $request->header('X-User-Id', 1);

        $chapter = DB::table('chapters')->where('id', $chapterId)->first();
        if (!$chapter) {
            return response()->json(['error' => 'Chapter not found'], 404);
        }

        // Upsert user_chapter_progress
        $exists = DB::table('user_chapter_progress')
            ->where('user_id', $userId)->where('chapter_id', $chapterId)->exists();

        if ($exists) {
            DB::table('user_chapter_progress')
                ->where('user_id', $userId)->where('chapter_id', $chapterId)
                ->update(['done' => true, 'updated_at' => now()]);
        } else {
            DB::table('user_chapter_progress')->insert([
                'user_id'    => $userId,
                'chapter_id' => $chapterId,
                'done'       => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Auto-complete module if all its chapters are done for this user
        $totalInModule = DB::table('chapters')->where('module_id', $chapter->module_id)->count();
        $doneInModule  = DB::table('user_chapter_progress')
            ->join('chapters', 'user_chapter_progress.chapter_id', '=', 'chapters.id')
            ->where('user_chapter_progress.user_id', $userId)
            ->where('chapters.module_id', $chapter->module_id)
            ->where('user_chapter_progress.done', true)
            ->count();

        $moduleDone = $totalInModule > 0 && $doneInModule >= $totalInModule;

        $modExists = DB::table('user_module_progress')
            ->where('user_id', $userId)->where('module_id', $chapter->module_id)->exists();

        if ($modExists) {
            DB::table('user_module_progress')
                ->where('user_id', $userId)->where('module_id', $chapter->module_id)
                ->update(['done' => $moduleDone, 'updated_at' => now()]);
        } else {
            DB::table('user_module_progress')->insert([
                'user_id'    => $userId,
                'module_id'  => $chapter->module_id,
                'done'       => $moduleDone,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Chapter marked as done', 'module_done' => $moduleDone]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function getCourseCompanies($courseId): array
    {
        // Returns company IDs (integers) — frontend expects number[] not name strings
        return DB::table('company_course')
            ->where('course_id', $courseId)
            ->pluck('company_id')
            ->map(fn($id) => (int) $id)
            ->toArray();
    }

    private function getCourseModules($courseId): array
    {
        $modules = DB::table('modules')
            ->where('course_id', $courseId)
            ->orderBy('order')
            ->get();

        foreach ($modules as $module) {
            $module->chapters = DB::table('chapters')
                ->where('module_id', $module->id)
                ->orderBy('order')
                ->get()
                ->map(function ($chapter) {
                    $chapter->content = json_decode($chapter->content, true);
                    return $chapter;
                })
                ->toArray();
        }

        return $modules->toArray();
    }

    private function syncCompanies($courseId, array $companyIds): void
    {
        // Accepts integer IDs directly — no name lookup needed
        foreach ($companyIds as $companyId) {
            DB::table('company_course')->insertOrIgnore([
                'course_id'   => $courseId,
                'company_id'  => (int) $companyId,
                'assigned_at' => now(),
            ]);
        }
    }
}
