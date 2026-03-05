<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CourseController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('courses');

        // Filter by category
        if ($request->has('category') && $request->category !== 'All') {
            $query->where('cat', $request->category);
        }

        // Filter by active status
        if ($request->has('active')) {
            $query->where('active', $request->active);
        }

        // Filter by client
        if ($request->has('client_id')) {
            $query->join('course_client', 'courses.id', '=', 'course_client.course_id')
                  ->where('course_client.client_id', $request->client_id)
                  ->select('courses.*');
        }

        $courses = $query->get();

        // Attach relationships
        foreach ($courses as $course) {
            $course->companies = $this->getCourseCompanies($course->id);
            $course->modules = [];
        }

        return response()->json($courses);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'desc' => 'nullable|string',
            'time' => 'required|string',
            'cat' => 'required|string',
            'thumb' => 'nullable|string',
            'thumb_emoji' => 'nullable|string',
            'companies' => 'nullable|array',
        ]);

        $courseId = DB::table('courses')->insertGetId([
            'title' => $validated['title'],
            'desc' => $validated['desc'] ?? '',
            'time' => $validated['time'],
            'cat' => $validated['cat'],
            'thumb' => $validated['thumb'] ?? null,
            'thumb_emoji' => $validated['thumb_emoji'] ?? '📚',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attach companies
        if (!empty($validated['companies'])) {
            $companies = DB::table('clients')
                ->whereIn('name', $validated['companies'])
                ->pluck('id');
            
            foreach ($companies as $clientId) {
                DB::table('course_client')->insert([
                    'course_id' => $courseId,
                    'client_id' => $clientId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return response()->json(['id' => $courseId, 'message' => 'Course created successfully'], 201);
    }

    public function show($id)
    {
        $course = DB::table('courses')->where('id', $id)->first();

        if (!$course) {
            return response()->json(['error' => 'Course not found'], 404);
        }

        $course->companies = $this->getCourseCompanies($id);
        $course->modules = $this->getCourseModules($id);

        return response()->json($course);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'desc' => 'nullable|string',
            'time' => 'sometimes|string',
            'cat' => 'sometimes|string',
            'thumb' => 'nullable|string',
            'thumb_emoji' => 'nullable|string',
            'active' => 'sometimes|boolean',
            'companies' => 'nullable|array',
        ]);

        $existing = DB::table('courses')->where('id', $id)->first();
        
        if (!$existing) {
            return response()->json(['error' => 'Course not found'], 404);
        }

        $updateData = ['updated_at' => now()];
        
        if (isset($validated['title'])) $updateData['title'] = $validated['title'];
        if (isset($validated['desc'])) $updateData['desc'] = $validated['desc'];
        if (isset($validated['time'])) $updateData['time'] = $validated['time'];
        if (isset($validated['cat'])) $updateData['cat'] = $validated['cat'];
        if (isset($validated['thumb'])) $updateData['thumb'] = $validated['thumb'];
        if (isset($validated['thumb_emoji'])) $updateData['thumb_emoji'] = $validated['thumb_emoji'];
        if (isset($validated['active'])) $updateData['active'] = $validated['active'];

        DB::table('courses')->where('id', $id)->update($updateData);

        if (isset($validated['companies'])) {
            DB::table('course_client')->where('course_id', $id)->delete();
            
            $companies = DB::table('clients')
                ->whereIn('name', $validated['companies'])
                ->pluck('id');
            
            foreach ($companies as $clientId) {
                DB::table('course_client')->insert([
                    'course_id' => $id,
                    'client_id' => $clientId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return response()->json(['message' => 'Course updated successfully']);
    }

    public function destroy($id)
    {
        DB::table('courses')->where('id', $id)->delete();
        return response()->json(['message' => 'Course deleted successfully']);
    }

    /**
     * Update course progress
     */
    public function updateProgress(Request $request, $id)
    {
        $validated = $request->validate([
            'progress' => 'required|numeric|min:0|max:100',
            'enrolled' => 'sometimes|boolean',
            'completed' => 'sometimes|boolean',
            'time_spent' => 'sometimes|numeric|min:0',
        ]);

        $existing = DB::table('courses')->where('id', $id)->first();

        $updateData = [
            'progress' => (int) round((float) $validated['progress']),
            'enrolled' => true, // ALWAYS set enrolled when progress is updated
            'updated_at' => now(),
        ];
        
        // Auto-set completed when progress reaches 100
        if ((float) $validated['progress'] >= 100) {
            $updateData['completed'] = true;
        } elseif (isset($validated['completed'])) {
            $updateData['completed'] = $validated['completed'];
        }

        if (isset($validated['time_spent']) && $existing) {
            $delta       = max(0, (int) $validated['time_spent']); // never subtract time
            $currentTime = max(0, (int) ($existing->time_spent ?? 0));
            // Cap the per-call delta at 120 minutes to guard against a
            // browser tab left open all day sending a huge value.
            $updateData['time_spent'] = $currentTime + min($delta, 120);
        }

        DB::table('courses')->where('id', $id)->update($updateData);

        return response()->json(['message' => 'Progress updated successfully']);
    }

    public function updateModules(Request $request, $id)
    {
        $validated = $request->validate([
            'modules' => 'required|array',
        ]);

        // Delete existing modules
        DB::table('modules')->where('course_id', $id)->delete();

        // Insert new modules
        foreach ($validated['modules'] as $index => $moduleData) {
            $moduleId = DB::table('modules')->insertGetId([
                'course_id' => $id,
                'title' => $moduleData['title'],
                'done' => $moduleData['done'] ?? false,
                'order' => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Insert chapters
            if (!empty($moduleData['chapters'])) {
                foreach ($moduleData['chapters'] as $chIndex => $chapterData) {
                    DB::table('chapters')->insert([
                        'module_id' => $moduleId,
                        'title' => $chapterData['title'],
                        'type' => $chapterData['type'],
                        'done' => $chapterData['done'] ?? false,
                        'order' => $chIndex,
                        'content' => json_encode($chapterData['content']),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        return response()->json(['message' => 'Modules updated successfully']);
    }

    private function getCourseCompanies($courseId)
    {
        return DB::table('clients')
            ->join('course_client', 'clients.id', '=', 'course_client.client_id')
            ->where('course_client.course_id', $courseId)
            ->pluck('clients.name')
            ->toArray();
    }

    private function getCourseModules($courseId)
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
}
