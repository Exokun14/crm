<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Company;
use App\Models\Course;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\UploadController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ── Auth user info ────────────────────────────────────────────────────────────
// Eager-loads company so company_name resolves in a single JOIN,
// not a separate lazy query.
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    $user = $request->user()->load('company');

    return response()->json([
        'id'           => $user->id,
        'name'         => $user->name,
        'email'        => $user->email,
        'role'         => $user->role,
        'industry'     => $user->industry,
        'company_id'   => $user->company_id,
        'company_name' => $user->company?->name,   // ← NEW: from companies.name
    ]);
});

// ── DEBUG: remove after confirming everything works ───────────────────────────
Route::get('/debug/user-courses-raw', function () {
    return response()->json([
        'total_courses'        => DB::table('courses')->count(),
        'published_courses'    => DB::table('courses')->where('stage', 'published')->count(),
        'total_companies'      => DB::table('companies')->count(),
        'company_course_rows'  => DB::table('company_course')->count(),
        'sample_pivot'         => DB::table('company_course')->limit(10)->get(),
        'users_with_company'   => DB::table('users')->whereNotNull('company_id')->count(),
        'users_without_company'=> DB::table('users')->whereNull('company_id')->count(),
    ]);
});

// ── Courses for the authenticated user's company ──────────────────────────────
Route::middleware('auth:sanctum')->get('/user/courses', function (Request $request) {
    $user = $request->user();

    // Admins or users with no company see all published courses
    if ($user->role === 'admin' || !$user->company_id) {
        $courses = DB::table('courses')->where('stage', 'published')->get();
        return response()->json($courses);
    }

    // Use raw DB join — avoids Eloquent withTimestamps() issue on pivot table
    $courses = DB::table('courses')
        ->join('company_course', 'courses.id', '=', 'company_course.course_id')
        ->where('company_course.company_id', $user->company_id)
        ->where('courses.stage', 'published')
        ->select('courses.*')
        ->get();

    Log::info('[/user/courses] user=' . $user->id . ' company=' . $user->company_id . ' courses=' . $courses->count());

    return response()->json($courses);
});

// ── All authenticated routes ──────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // ── Courses ───────────────────────────────────────────────────────────────
    Route::get   ('courses',                    [CourseController::class, 'index']);
    Route::post  ('courses',                    [CourseController::class, 'store']);
    Route::get   ('courses/{id}',               [CourseController::class, 'show']);
    Route::put   ('courses/{id}',               [CourseController::class, 'update']);
    Route::delete('courses/{id}',               [CourseController::class, 'destroy']);
    Route::post  ('courses/{id}/clone',         [CourseController::class, 'clone']);
    Route::put   ('courses/{id}/progress',      [CourseController::class, 'updateProgress']);
    Route::put   ('courses/{id}/modules',       [CourseController::class, 'updateModules']);
    Route::put   ('chapters/{id}/done',         [CourseController::class, 'markChapterDone']);

    // ── Activities ────────────────────────────────────────────────────────────
    Route::get   ('activities',                 [ActivityController::class, 'index']);
    Route::post  ('activities',                 [ActivityController::class, 'store']);
    Route::get   ('activities/{id}',            [ActivityController::class, 'show']);
    Route::put   ('activities/{id}',            [ActivityController::class, 'update']);
    Route::delete('activities/{id}',            [ActivityController::class, 'destroy']);

    // ── Progress ──────────────────────────────────────────────────────────────
    Route::get   ('progress',                   [ProgressController::class, 'index']);
    Route::post  ('progress',                   [ProgressController::class, 'store']);
    Route::put   ('progress/{id}',              [ProgressController::class, 'update']);

    // ── Settings ──────────────────────────────────────────────────────────────
    Route::get   ('settings',                   [SettingsController::class, 'index']);
    Route::get   ('settings/categories',        [SettingsController::class, 'categories']);
    Route::post  ('settings/categories',        [SettingsController::class, 'storeCategory']);
    Route::delete('settings/categories/{name}', [SettingsController::class, 'deleteCategory']);
    Route::get   ('settings/colors',            [SettingsController::class, 'colors']);

    // ── Clients ───────────────────────────────────────────────────────────────
    Route::get   ('clients',                    [ClientController::class, 'index']);
    Route::get   ('clients/{id}',               [ClientController::class, 'show']);
    Route::get   ('clients/{id}/courses',       [ClientController::class, 'courses']);

    // ── Companies (raw DB — avoids withTimestamps pivot issue) ────────────────
    Route::get('/companies', function () {
        return response()->json(DB::table('companies')->get());
    });

    Route::get('/companies/{id}', function ($id) {
        $company = DB::table('companies')->where('id', $id)->first();
        if (!$company) return response()->json(['error' => 'Not found'], 404);
        $company->courses = DB::table('courses')
            ->join('company_course', 'courses.id', '=', 'company_course.course_id')
            ->where('company_course.company_id', $id)
            ->select('courses.id', 'courses.title', 'courses.cat', 'courses.stage')
            ->get();
        return response()->json($company);
    });

    Route::post('/companies/{id}/courses', function (Request $request, $id) {
        $request->validate(['course_id' => 'required|exists:courses,id']);
        DB::table('company_course')->insertOrIgnore([
            'course_id'   => $request->course_id,
            'company_id'  => $id,
            'assigned_at' => now(),
        ]);
        return response()->json(['message' => 'Course assigned to company.']);
    });

    Route::delete('/companies/{id}/courses/{courseId}', function ($id, $courseId) {
        DB::table('company_course')
            ->where('company_id', $id)
            ->where('course_id', $courseId)
            ->delete();
        return response()->json(['message' => 'Course removed from company.']);
    });

    Route::put('/companies/{id}/courses', function (Request $request, $id) {
        $request->validate(['course_ids' => 'required|array', 'course_ids.*' => 'exists:courses,id']);
        DB::table('company_course')->where('company_id', $id)->delete();
        foreach ($request->course_ids as $courseId) {
            DB::table('company_course')->insertOrIgnore([
                'course_id'   => $courseId,
                'company_id'  => $id,
                'assigned_at' => now(),
            ]);
        }
        return response()->json(['message' => 'Company courses updated.']);
    });

    // ── File upload ───────────────────────────────────────────────────────────
    Route::post('upload', [UploadController::class, 'store']);
});
