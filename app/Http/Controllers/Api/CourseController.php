<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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
        Log::channel('stderr')->info('[CourseController@index] ▶ Request params: ' . json_encode($request->all()));
        Log::channel('stderr')->info('[CourseController@index] include_templates param: ' . var_export($request->boolean('include_templates'), true));

        $query = DB::table('courses');

        if ($request->has('category') && $request->category !== 'All') {
            $query->where('cat', $request->category);
            Log::channel('stderr')->info('[CourseController@index] Filter: cat = ' . $request->category);
        }
        if ($request->has('active')) {
            $query->where('active', $request->active);
            Log::channel('stderr')->info('[CourseController@index] Filter: active = ' . $request->active);
        }
        if ($request->has('stage')) {
            $query->where('stage', $request->stage);
            Log::channel('stderr')->info('[CourseController@index] Filter: stage = ' . $request->stage);
        }

        if (!$request->has('stage') && !$request->boolean('include_templates')) {
            $query->where('stage', '!=', 'template');
            Log::channel('stderr')->info('[CourseController@index] ⚠️  Excluding templates (include_templates not set)');
        } else {
            Log::channel('stderr')->info('[CourseController@index] ✅ Including templates in results');
        }

        if ($request->has('client_id')) {
            $query->join('company_course', 'courses.id', '=', 'company_course.course_id')
                  ->where('company_course.company_id', $request->client_id)
                  ->select('courses.*');
            Log::channel('stderr')->info('[CourseController@index] Filter: client_id/company_id = ' . $request->client_id);
        }

        $courses = $query->get();

        // Log stage breakdown
        $stageBreakdown = $courses->groupBy('stage')->map->count();
        Log::channel('stderr')->info('[CourseController@index] ✅ Returning ' . $courses->count() . ' courses. Stage breakdown: ' . json_encode($stageBreakdown));

        foreach ($courses as $course) {
            $course->companies = $this->getCourseCompanies($course->id);
            $course->modules   = [];
        }

        return response()->json($courses);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /user/courses
    // ─────────────────────────────────────────────────────────────────────────
    public function getUserCourses(Request $request)
    {
        Log::channel('stderr')->info('[CourseController@getUserCourses] ▶ Called');

        $user = $request->user();

        if (!$user) {
            Log::channel('stderr')->error('[CourseController@getUserCourses] ❌ Unauthenticated');
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        Log::channel('stderr')->info('[CourseController@getUserCourses] User: id=' . $user->id . ' role=' . $user->role . ' company_id=' . $user->company_id);

        if ($user->role === 'admin' || !$user->company_id) {
            $courses = DB::table('courses')->where('stage', 'published')->get();
        } else {
            $courses = DB::table('courses')
                ->join('company_course', 'courses.id', '=', 'company_course.course_id')
                ->where('company_course.company_id', $user->company_id)
                ->where('courses.stage', 'published')
                ->select('courses.*')
                ->get();
        }

        Log::channel('stderr')->info('[CourseController@getUserCourses] ✅ Returning ' . $courses->count() . ' published courses');

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
        Log::channel('stderr')->info('[CourseController@store] ▶ Payload: ' . json_encode($request->all()));

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

        Log::channel('stderr')->info('[CourseController@store] Creating course: title="' . $validated['title'] . '" stage=' . $stage);

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

        Log::channel('stderr')->info('[CourseController@store] ✅ Created course id=' . $courseId);

        if (!empty($validated['companies'])) {
            $this->syncCompanies($courseId, $validated['companies']);
            Log::channel('stderr')->info('[CourseController@store] Synced companies: ' . json_encode($validated['companies']));
        }

        return response()->json(['id' => $courseId, 'message' => 'Course created successfully'], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /courses/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function show($id)
    {
        Log::channel('stderr')->info('[CourseController@show] ▶ id=' . $id);

        $course = DB::table('courses')->where('id', $id)->first();

        if (!$course) {
            Log::channel('stderr')->error('[CourseController@show] ❌ Course not found: id=' . $id);
            return response()->json(['error' => 'Course not found'], 404);
        }

        $course->companies = $this->getCourseCompanies($id);
        $course->modules   = $this->getCourseModules($id);

        Log::channel('stderr')->info('[CourseController@show] ✅ Returning course id=' . $id . ' modules=' . count($course->modules) . ' stage=' . $course->stage);

        return response()->json($course);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /courses/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function update(Request $request, $id)
    {
        Log::channel('stderr')->info('[CourseController@update] ▶ id=' . $id . ' payload: ' . json_encode($request->all()));

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
            Log::channel('stderr')->error('[CourseController@update] ❌ Course not found: id=' . $id);
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
            Log::channel('stderr')->info('[CourseController@update] Stage change: ' . $existing->stage . ' → ' . $validated['stage']);
        } elseif (isset($validated['active'])) {
            $updateData['active'] = $validated['active'];
            $updateData['stage']  = $validated['active'] ? 'published' : 'unpublished';
            Log::channel('stderr')->info('[CourseController@update] Active toggle → stage: ' . $updateData['stage']);
        }

        DB::table('courses')->where('id', $id)->update($updateData);
        Log::channel('stderr')->info('[CourseController@update] ✅ Updated course id=' . $id . ' fields: ' . json_encode(array_keys($updateData)));

        if (array_key_exists('companies', $validated)) {
            DB::table('company_course')->where('course_id', $id)->delete();
            if (!empty($validated['companies'])) {
                $this->syncCompanies($id, $validated['companies']);
                Log::channel('stderr')->info('[CourseController@update] Synced companies: ' . json_encode($validated['companies']));
            }
        }

        return response()->json(['message' => 'Course updated successfully']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /courses/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function destroy($id)
    {
        Log::channel('stderr')->info('[CourseController@destroy] ▶ Deleting course id=' . $id);
        DB::table('courses')->where('id', $id)->delete();
        Log::channel('stderr')->info('[CourseController@destroy] ✅ Deleted course id=' . $id);
        return response()->json(['message' => 'Course deleted successfully']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /courses/{id}/clone
    // ─────────────────────────────────────────────────────────────────────────
    public function clone(Request $request, $id)
    {
        Log::channel('stderr')->info('[CourseController@clone] ▶ Cloning course id=' . $id);

        $source = DB::table('courses')->where('id', $id)->first();

        if (!$source) {
            Log::channel('stderr')->error('[CourseController@clone] ❌ Source course not found: id=' . $id);
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

        Log::channel('stderr')->info('[CourseController@clone] ✅ Clone created: new id=' . $newId);

        $assignments = DB::table('company_course')->where('course_id', $id)->get();
        foreach ($assignments as $row) {
            DB::table('company_course')->insert([
                'course_id'   => $newId,
                'company_id'  => $row->company_id,
                'assigned_at' => now(),
            ]);
        }

        $modules = DB::table('modules')->where('course_id', $id)->orderBy('order')->get();
        Log::channel('stderr')->info('[CourseController@clone] Cloning ' . $modules->count() . ' modules');

        foreach ($modules as $module) {
            $newModuleId = DB::table('modules')->insertGetId([
                'course_id'  => $newId,
                'title'      => $module->title,
                'done'       => false,
                'order'      => $module->order,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $chapters = DB::table('chapters')->where('module_id', $module->id)->orderBy('order')->get();
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
        Log::channel('stderr')->info('[CourseController@updateProgress] ▶ id=' . $id . ' payload: ' . json_encode($request->all()));

        $validated = $request->validate([
            'progress'   => 'required|numeric|min:0|max:100',
            'enrolled'   => 'sometimes|boolean',
            'completed'  => 'sometimes|boolean',
            'time_spent' => 'sometimes|numeric|min:0',
        ]);

        $existing   = DB::table('courses')->where('id', $id)->first();
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
        Log::channel('stderr')->info('[CourseController@updateProgress] ✅ Progress updated: id=' . $id . ' progress=' . $updateData['progress']);

        return response()->json(['message' => 'Progress updated successfully']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /courses/{id}/modules
    // ─────────────────────────────────────────────────────────────────────────
    public function updateModules(Request $request, $id)
    {
        Log::channel('stderr')->info('[CourseController@updateModules] ▶ course id=' . $id);
        Log::channel('stderr')->info('[CourseController@updateModules] Raw payload keys: ' . json_encode(array_keys($request->all())));

        $validated = $request->validate([
            'modules' => 'required|array',
        ]);

        $moduleCount = count($validated['modules']);
        Log::channel('stderr')->info('[CourseController@updateModules] Module count in request: ' . $moduleCount);

        // Verify course exists
        $courseExists = DB::table('courses')->where('id', $id)->exists();
        if (!$courseExists) {
            Log::channel('stderr')->error('[CourseController@updateModules] ❌ Course id=' . $id . ' does not exist!');
            return response()->json(['error' => 'Course not found'], 404);
        }

        // Delete existing modules (chapters cascade via FK)
        $deletedModules = DB::table('modules')->where('course_id', $id)->count();
        DB::table('modules')->where('course_id', $id)->delete();
        Log::channel('stderr')->info('[CourseController@updateModules] Deleted ' . $deletedModules . ' existing modules');

        $savedModules   = 0;
        $savedChapters  = 0;

        foreach ($validated['modules'] as $index => $moduleData) {
            Log::channel('stderr')->info('[CourseController@updateModules] Inserting module ' . $index . ': "' . ($moduleData['title'] ?? 'UNTITLED') . '"');

            $moduleId = DB::table('modules')->insertGetId([
                'course_id'  => $id,
                'title'      => $moduleData['title'],
                'done'       => $moduleData['done'] ?? false,
                'order'      => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $savedModules++;

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
                    $savedChapters++;
                }
            }
        }

        Log::channel('stderr')->info('[CourseController@updateModules] ✅ Done. Saved: ' . $savedModules . ' modules, ' . $savedChapters . ' chapters for course id=' . $id);

        return response()->json(['message' => 'Modules updated successfully']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /chapters/{chapterId}/done
    // ─────────────────────────────────────────────────────────────────────────
    public function markChapterDone(Request $request, $chapterId)
    {
        Log::channel('stderr')->info('[CourseController@markChapterDone] ▶ chapterId=' . $chapterId);

        $chapter = DB::table('chapters')->where('id', $chapterId)->first();

        if (!$chapter) {
            Log::channel('stderr')->error('[CourseController@markChapterDone] ❌ Chapter not found: id=' . $chapterId);
            return response()->json(['error' => 'Chapter not found'], 404);
        }

        DB::table('chapters')->where('id', $chapterId)->update(['done' => true, 'updated_at' => now()]);

        $remaining = DB::table('chapters')
            ->where('module_id', $chapter->module_id)
            ->where('done', false)
            ->where('id', '!=', $chapterId)
            ->count();

        Log::channel('stderr')->info('[CourseController@markChapterDone] Remaining undone chapters in module: ' . $remaining);

        if ($remaining === 0) {
            DB::table('modules')->where('id', $chapter->module_id)->update(['done' => true, 'updated_at' => now()]);
            Log::channel('stderr')->info('[CourseController@markChapterDone] ✅ Module ' . $chapter->module_id . ' marked done');
        }

        return response()->json(['message' => 'Chapter marked as done']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────
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

        Log::channel('stderr')->info('[getCourseModules] course_id=' . $courseId . ' → ' . $modules->count() . ' modules found');

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

    private function syncCompanies($courseId, array $companyNames): void
    {
        Log::channel('stderr')->info('[syncCompanies] course_id=' . $courseId . ' names: ' . json_encode($companyNames));

        $companyIds = DB::table('companies')->whereIn('name', $companyNames)->pluck('id');
        Log::channel('stderr')->info('[syncCompanies] Matched company IDs: ' . json_encode($companyIds));

        foreach ($companyIds as $companyId) {
            DB::table('company_course')->insertOrIgnore([
                'course_id'   => $courseId,
                'company_id'  => $companyId,
                'assigned_at' => now(),
            ]);
        }
    }
}
