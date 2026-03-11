<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CourseController extends Controller
{
    const VALID_STAGES = ['draft', 'review_ready', 'published', 'unpublished', 'template'];

    // ─────────────────────────────────────────────────────────────────────────
    // GET /courses
    // ─────────────────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $query = DB::table('courses');

        if ($request->has('category') && $request->category !== 'All') {
            $query->where('cat', $request->category);
        }
        if ($request->has('active')) {
            $query->where('active', $request->active);
        }
        if ($request->has('stage')) {
            $query->where('stage', $request->stage);
        }
        if (!$request->has('stage')) {
            $query->where('stage', '!=', 'template');
        }

        // Filter by company_id (was: client_id via course_client)
        if ($request->has('client_id')) {
            $query->join('company_course', 'courses.id', '=', 'company_course.course_id')
                  ->where('company_course.company_id', $request->client_id)
                  ->select('courses.*');
        }

        $courses = $query->get();

        foreach ($courses as $course) {
            $course->companies = $this->getCourseCompanies($course->id);
            $course->modules   = [];
        }

        return response()->json($courses);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /user/courses  (called from CourseController if routed here,
    //                     but primary handler is the closure in api.php)
    // ─────────────────────────────────────────────────────────────────────────
    public function getUserCourses(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        if ($user->role === 'admin' || !$user->company_id) {
            $courses = DB::table('courses')
                ->where('stage', 'published')
                ->get();
        } else {
            $courses = DB::table('courses')
                ->join('company_course', 'courses.id', '=', 'company_course.course_id')
                ->where('company_course.company_id', $user->company_id)
                ->where('courses.stage', 'published')
                ->select('courses.*')
                ->get();
        }

        foreach ($courses as $course) {
            $course->companies = $this->getCourseCompanies($course->id);
            $course->modules   = [];
        }

        return response()->json($courses);
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

        return response()->json(['id' => $courseId, 'message' => 'Course created successfully'], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /courses/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function show($id)
    {
        $course = DB::table('courses')->where('id', $id)->first();

        if (!$course) {
            return response()->json(['error' => 'Course not found'], 404);
        }

        $course->companies = $this->getCourseCompanies($id);
        $course->modules   = $this->getCourseModules($id);

        return response()->json($course);
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

        // Sync companies if provided (now uses company_course + companies table)
        if (array_key_exists('companies', $validated)) {
            DB::table('company_course')->where('course_id', $id)->delete();
            if (!empty($validated['companies'])) {
                $this->syncCompanies($id, $validated['companies']);
            }
        }

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
    // POST /courses/{id}/clone
    // ─────────────────────────────────────────────────────────────────────────
    public function clone(Request $request, $id)
    {
        $source = DB::table('courses')->where('id', $id)->first();

        if (!$source) {
            return response()->json(['error' => 'Source course not found'], 404);
        }

        $newId = DB::table('courses')->insertGetId([
            'title'       => $source->title . ' (Copy)',
            'desc'        => $source->desc,
            'time'        => $source->time,
            'cat'         => $source->cat,
            'thumb'       => $source->thumb,
            'thumb_emoji' => $source->thumb_emoji,
            'active'      => false,
            'stage'       => 'draft',
            'progress'    => 0,
            'enrolled'    => false,
            'completed'   => false,
            'time_spent'  => 0,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Clone company assignments (was: course_client/client_id)
        $assignments = DB::table('company_course')->where('course_id', $id)->get();
        foreach ($assignments as $row) {
            DB::table('company_course')->insert([
                'course_id'   => $newId,
                'company_id'  => $row->company_id,
                'assigned_at' => now(),
            ]);
        }

        // Clone modules and chapters
        $modules = DB::table('modules')
            ->where('course_id', $id)
            ->orderBy('order')
            ->get();

        foreach ($modules as $module) {
            $newModuleId = DB::table('modules')->insertGetId([
                'course_id'  => $newId,
                'title'      => $module->title,
                'done'       => false,
                'order'      => $module->order,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $chapters = DB::table('chapters')
                ->where('module_id', $module->id)
                ->orderBy('order')
                ->get();

            foreach ($chapters as $chapter) {
                DB::table('chapters')->insert([
                    'module_id'  => $newModuleId,
                    'title'      => $chapter->title,
                    'type'       => $chapter->type,
                    'done'       => false,
                    'order'      => $chapter->order,
                    'content'    => $chapter->content,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $newCourse            = DB::table('courses')->where('id', $newId)->first();
        $newCourse->companies = $this->getCourseCompanies($newId);
        $newCourse->modules   = [];

        return response()->json([
            'id'      => $newId,
            'course'  => $newCourse,
            'message' => 'Course cloned successfully',
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /courses/{id}/progress
    // ─────────────────────────────────────────────────────────────────────────
    public function updateProgress(Request $request, $id)
    {
        $validated = $request->validate([
            'progress'   => 'required|numeric|min:0|max:100',
            'enrolled'   => 'sometimes|boolean',
            'completed'  => 'sometimes|boolean',
            'time_spent' => 'sometimes|numeric|min:0',
        ]);

        $existing = DB::table('courses')->where('id', $id)->first();

        $updateData = [
            'progress'   => (int) round((float) $validated['progress']),
            'enrolled'   => true,
            'updated_at' => now(),
        ];

        if ((float) $validated['progress'] >= 100) {
            $updateData['completed'] = true;
        } elseif (isset($validated['completed'])) {
            $updateData['completed'] = $validated['completed'];
        }

        if (isset($validated['time_spent']) && $existing) {
            $delta                    = max(0, (int) $validated['time_spent']);
            $currentTime              = max(0, (int) ($existing->time_spent ?? 0));
            $updateData['time_spent'] = $currentTime + min($delta, 7200);
        }

        DB::table('courses')->where('id', $id)->update($updateData);

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

        DB::table('modules')->where('course_id', $id)->delete();

        foreach ($validated['modules'] as $index => $moduleData) {
            $moduleId = DB::table('modules')->insertGetId([
                'course_id'  => $id,
                'title'      => $moduleData['title'],
                'done'       => $moduleData['done'] ?? false,
                'order'      => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (!empty($moduleData['chapters'])) {
                foreach ($moduleData['chapters'] as $chIndex => $chapterData) {
                    DB::table('chapters')->insert([
                        'module_id'  => $moduleId,
                        'title'      => $chapterData['title'],
                        'type'       => $chapterData['type'],
                        'done'       => $chapterData['done'] ?? false,
                        'order'      => $chIndex,
                        'content'    => json_encode($chapterData['content']),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        return response()->json(['message' => 'Modules updated successfully']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /chapters/{chapterId}/done
    // ─────────────────────────────────────────────────────────────────────────
    public function markChapterDone(Request $request, $chapterId)
    {
        $chapter = DB::table('chapters')->where('id', $chapterId)->first();

        if (!$chapter) {
            return response()->json(['error' => 'Chapter not found'], 404);
        }

        DB::table('chapters')
            ->where('id', $chapterId)
            ->update(['done' => true, 'updated_at' => now()]);

        $remaining = DB::table('chapters')
            ->where('module_id', $chapter->module_id)
            ->where('done', false)
            ->where('id', '!=', $chapterId)
            ->count();

        if ($remaining === 0) {
            DB::table('modules')
                ->where('id', $chapter->module_id)
                ->update(['done' => true, 'updated_at' => now()]);
        }

        return response()->json(['message' => 'Chapter marked as done']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns company names for a course.
     * Uses company_course pivot → companies table (matches migration).
     */
    private function getCourseCompanies($courseId): array
    {
        return DB::table('companies')
            ->join('company_course', 'companies.id', '=', 'company_course.company_id')
            ->where('company_course.course_id', $courseId)
            ->pluck('companies.name')
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

    /**
     * Syncs company assignments by name → looks up company IDs from companies table.
     * Previously used clients table — now correctly uses companies.
     */
    private function syncCompanies($courseId, array $companyNames): void
    {
        $companyIds = DB::table('companies')
            ->whereIn('name', $companyNames)
            ->pluck('id');

        foreach ($companyIds as $companyId) {
            DB::table('company_course')->insertOrIgnore([
                'course_id'   => $courseId,
                'company_id'  => $companyId,
                'assigned_at' => now(),
            ]);
        }
    }
}
