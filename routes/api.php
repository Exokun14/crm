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
use App\Http\Controllers\Api\CourseIconController;
use App\Http\Controllers\Api\ChatController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ── Auth user info ────────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    $user = $request->user()->load('company');

    return response()->json([
        'id'           => $user->id,
        'name'         => $user->name,
        'email'        => $user->email,
        'role'         => $user->role,
        'industry'     => $user->industry,
        'company_id'   => $user->company_id,
        'company_name' => $user->company?->name,
        'position'     => $user->position,
        'phone'        => $user->phone,
        'status'       => $user->status,
    ]);
});

// ── Auth logout ───────────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->post('/logout', function (Request $request) {
    $request->user()->currentAccessToken()?->delete();
    return response()->json(['message' => 'Logged out.']);
});

// ── DEBUG ─────────────────────────────────────────────────────────────────────
Route::get('/debug/user-courses-raw', function () {
    return response()->json([
        'total_courses'         => DB::table('courses')->count(),
        'published_courses'     => DB::table('courses')->where('stage', 'published')->count(),
        'total_companies'       => DB::table('companies')->count(),
        'company_course_rows'   => DB::table('company_course')->count(),
        'sample_pivot'          => DB::table('company_course')->limit(10)->get(),
        'users_with_company'    => DB::table('users')->whereNotNull('company_id')->count(),
        'users_without_company' => DB::table('users')->whereNull('company_id')->count(),
    ]);
});

// ── Courses for the authenticated user's company ──────────────────────────────
Route::middleware('auth:sanctum')->get('/user/courses', function (Request $request) {
    $user = $request->user();

    if ($user->role === 'admin' || !$user->company_id) {
        return response()->json(DB::table('courses')->where('stage', 'published')->get());
    }

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
    Route::get   ('courses',               [CourseController::class, 'index']);
    Route::post  ('courses',               [CourseController::class, 'store']);
    Route::get   ('courses/{id}',          [CourseController::class, 'show']);
    Route::put   ('courses/{id}',          [CourseController::class, 'update']);
    Route::delete('courses/{id}',          [CourseController::class, 'destroy']);
    Route::post  ('courses/{id}/clone',    [CourseController::class, 'clone']);
    Route::put   ('courses/{id}/progress', [CourseController::class, 'updateProgress']);
    Route::put   ('courses/{id}/modules',  [CourseController::class, 'updateModules']);
    Route::put   ('chapters/{id}/done',    [CourseController::class, 'markChapterDone']);

    // ── Activities ────────────────────────────────────────────────────────────
    Route::get   ('activities',       [ActivityController::class, 'index']);
    Route::post  ('activities',       [ActivityController::class, 'store']);
    Route::get   ('activities/{id}',  [ActivityController::class, 'show']);
    Route::put   ('activities/{id}',  [ActivityController::class, 'update']);
    Route::delete('activities/{id}',  [ActivityController::class, 'destroy']);

    // ── Progress ──────────────────────────────────────────────────────────────
    // FIX: These two named-segment routes MUST be declared before progress/{id}
    // otherwise Laravel's router would interpret "chapters" and "modules" as
    // the {id} wildcard and never reach the correct controller methods.
    Route::get  ('progress/chapters', [ProgressController::class, 'chapterProgress']);
    Route::get  ('progress/modules',  [ProgressController::class, 'moduleProgress']);

    Route::get  ('progress',      [ProgressController::class, 'index']);
    Route::post ('progress',      [ProgressController::class, 'store']);
    Route::put  ('progress/{id}', [ProgressController::class, 'update']);

    // ── Settings ──────────────────────────────────────────────────────────────
    Route::get   ('settings',                          [SettingsController::class, 'index']);
    Route::get   ('settings/categories',               [SettingsController::class, 'categories']);
    Route::post  ('settings/categories',               [SettingsController::class, 'storeCategory']);
    Route::delete('settings/categories/{name}',        [SettingsController::class, 'deleteCategory']);
    Route::put   ('settings/categories/{name}',        [SettingsController::class, 'renameCategory']);  // NEW
    Route::get   ('settings/colors',                   [SettingsController::class, 'colors']);

    // ── Course Icons (user-specific uploads) ──────────────────────────────────
    // POST   /api/course-icons          → upload icon (multipart, field: "icon")
    // GET    /api/course-icons          → list current user's icons
    // DELETE /api/course-icons/{id}     → delete one icon (owner only)
    Route::post  ('course-icons',      [CourseIconController::class, 'store']);
    Route::get   ('course-icons',      [CourseIconController::class, 'index']);
    Route::delete('course-icons/{id}', [CourseIconController::class, 'destroy']);

    // ── Clients ───────────────────────────────────────────────────────────────
    Route::get('clients',              [ClientController::class, 'index']);
    Route::get('clients/{id}',         [ClientController::class, 'show']);
    Route::get('clients/{id}/courses', [ClientController::class, 'courses']);

    // ── Companies ─────────────────────────────────────────────────────────────
    Route::get('/companies', function () {
        $companies = DB::table('companies')->get();
        Log::info('[CompanyFetch] GET /api/companies → returning ' . $companies->count() . ' rows', [
            'ids'         => $companies->pluck('id'),
            'store_names' => $companies->pluck('store_name'),
        ]);
        return response()->json($companies);
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
            ->where('company_id', $id)->where('course_id', $courseId)->delete();
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

    // ── Company branding ──────────────────────────────────────────────────────
    Route::get('/companies/{id}/branding', function ($id) {
        $company = DB::table('companies')->where('id', $id)->first();
        if (!$company) return response()->json(['error' => 'Not found'], 404);

        $coverUrl = null;
        if (!empty($company->cover_photo_path)) {
            $coverUrl = url('storage/' . $company->cover_photo_path);
        }

        return response()->json([
            'cover_photo_url' => $coverUrl,
            'brand_color'     => $company->brand_color ?? null,
        ]);
    });

    Route::post('/companies/{id}/cover-photo', function (Request $request, $id) {
        $request->validate([
            'cover_photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'],
        ]);

        $company = DB::table('companies')->where('id', $id)->first();
        if (!$company) return response()->json(['error' => 'Not found'], 404);

        if (!empty($company->cover_photo_path)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($company->cover_photo_path);
        }

        $path = $request->file('cover_photo')->store("covers/{$id}", 'public');

        DB::table('companies')->where('id', $id)->update([
            'cover_photo_path' => $path,
            'updated_at'       => now(),
        ]);

        return response()->json([
            'message'          => 'Cover photo updated.',
            'cover_photo_url'  => url('storage/' . $path),
            'cover_photo_path' => $path,
        ]);
    });

    Route::delete('/companies/{id}/cover-photo', function ($id) {
        $company = DB::table('companies')->where('id', $id)->first();
        if (!$company) return response()->json(['error' => 'Not found'], 404);

        if (!empty($company->cover_photo_path)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($company->cover_photo_path);
            DB::table('companies')->where('id', $id)->update([
                'cover_photo_path' => null,
                'updated_at'       => now(),
            ]);
        }

        return response()->json(['message' => 'Cover photo removed.']);
    });

    Route::put('/companies/{id}/brand-color', function (Request $request, $id) {
        $request->validate([
            'brand_color' => [
                'nullable', 'string',
                'regex:/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/',
            ],
        ]);

        $company = DB::table('companies')->where('id', $id)->first();
        if (!$company) return response()->json(['error' => 'Not found'], 404);

        $color = $request->input('brand_color') ?: null;

        DB::table('companies')->where('id', $id)->update([
            'brand_color' => $color,
            'updated_at'  => now(),
        ]);

        return response()->json([
            'message'     => $color ? 'Brand colour updated.' : 'Brand colour reset to logo default.',
            'brand_color' => $color,
        ]);
    });

    // ── Branches ──────────────────────────────────────────────────────────────
    Route::get('/branches', function (Request $request) {
        $query = DB::table('branches');
        if ($request->company_id) $query->where('company_id', $request->company_id);
        return response()->json($query->get());
    });

    Route::get('/branches/{id}', function ($id) {
        $branch = DB::table('branches')->where('id', $id)->first();
        if (!$branch) return response()->json(['error' => 'Not found'], 404);
        return response()->json($branch);
    });

    Route::post('/branches', function (Request $request) {
        $request->validate([
            'company_id'  => 'required|exists:companies,id',
            'name'        => 'required|string',
        ]);
        $id = DB::table('branches')->insertGetId([
            'company_id'  => $request->company_id,
            'name'        => $request->name,
            'site'        => $request->site,
            'seats'       => $request->seats ?? 0,
            'license_tag' => $request->license_tag,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        return response()->json(['id' => $id, 'message' => 'Branch created.'], 201);
    });

    Route::put('/branches/{id}', function (Request $request, $id) {
        DB::table('branches')->where('id', $id)->update([
            'name'        => $request->name,
            'site'        => $request->site,
            'seats'       => $request->seats,
            'license_tag' => $request->license_tag,
            'updated_at'  => now(),
        ]);
        return response()->json(['message' => 'Branch updated.']);
    });

    Route::delete('/branches/{id}', function ($id) {
        DB::table('branches')->where('id', $id)->delete();
        return response()->json(['message' => 'Branch deleted.']);
    });

    // ── POS Devices ───────────────────────────────────────────────────────────
    Route::get('/pos-devices', function (Request $request) {
        $query = DB::table('pos_devices');
        if ($request->company_id) $query->where('company_id', $request->company_id);
        if ($request->branch_id)  $query->where('branch_id',  $request->branch_id);
        return response()->json($query->get());
    });

    Route::get('/pos-devices/{id}', function ($id) {
        $device = DB::table('pos_devices')->where('id', $id)->first();
        if (!$device) return response()->json(['error' => 'Not found'], 404);
        return response()->json($device);
    });

    Route::post('/pos-devices', function (Request $request) {
        $request->validate(['company_id' => 'required|exists:companies,id']);
        $id = DB::table('pos_devices')->insertGetId([
            'company_id'   => $request->company_id,
            'branch_id'    => $request->branch_id,
            'status'       => $request->status ?? 'active',
            'model'        => $request->model,
            'serial'       => $request->serial,
            'ip_address'   => $request->ip_address,
            'os'           => $request->os,
            'msa_start'    => $request->msa_start,
            'msa_end'      => $request->msa_end,
            'warranty_end' => $request->warranty_end,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        return response()->json(['id' => $id, 'message' => 'POS device created.'], 201);
    });

    Route::put('/pos-devices/{id}', function (Request $request, $id) {
        DB::table('pos_devices')->where('id', $id)->update([
            'branch_id'    => $request->branch_id,
            'status'       => $request->status,
            'model'        => $request->model,
            'serial'       => $request->serial,
            'ip_address'   => $request->ip_address,
            'os'           => $request->os,
            'msa_start'    => $request->msa_start,
            'msa_end'      => $request->msa_end,
            'warranty_end' => $request->warranty_end,
            'updated_at'   => now(),
        ]);
        return response()->json(['message' => 'POS device updated.']);
    });

    Route::delete('/pos-devices/{id}', function ($id) {
        DB::table('pos_devices')->where('id', $id)->delete();
        return response()->json(['message' => 'POS device deleted.']);
    });

    // ── Licenses ──────────────────────────────────────────────────────────────
    Route::get('/licenses', function (Request $request) {
        $query = DB::table('licenses');
        if ($request->company_id) $query->where('company_id', $request->company_id);
        return response()->json($query->get());
    });

    Route::post('/licenses', function (Request $request) {
        $request->validate(['company_id' => 'required|exists:companies,id']);
        $id = DB::table('licenses')->insertGetId([
            'company_id'      => $request->company_id,
            'license_key'     => $request->license_key,
            'sa_start'        => $request->sa_start,
            'sa_end'          => $request->sa_end,
            'krunch_version'  => $request->krunch_version,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        return response()->json(['id' => $id, 'message' => 'License created.'], 201);
    });

    Route::put('/licenses/{id}', function (Request $request, $id) {
        DB::table('licenses')->where('id', $id)->update([
            'license_key'    => $request->license_key,
            'sa_start'       => $request->sa_start,
            'sa_end'         => $request->sa_end,
            'krunch_version' => $request->krunch_version,
            'updated_at'     => now(),
        ]);
        return response()->json(['message' => 'License updated.']);
    });

    Route::delete('/licenses/{id}', function ($id) {
        DB::table('licenses')->where('id', $id)->delete();
        return response()->json(['message' => 'License deleted.']);
    });

    // ── Tickets ───────────────────────────────────────────────────────────────
    Route::get('/tickets', function (Request $request) {
        $query = DB::table('tickets');
        if ($request->company_id) $query->where('company_id', $request->company_id);
        if ($request->branch_id)  $query->where('branch_id',  $request->branch_id);
        if ($request->status)     $query->where('status',     $request->status);
        return response()->json($query->orderBy('created_at', 'desc')->get());
    });

    Route::get('/tickets/{id}', function ($id) {
        $ticket = DB::table('tickets')->where('id', $id)->first();
        if (!$ticket) return response()->json(['error' => 'Not found'], 404);
        return response()->json($ticket);
    });

    Route::post('/tickets', function (Request $request) {
        $request->validate([
            'company_id' => 'required|exists:companies,id',
            'subject'    => 'required|string',
        ]);
        $id = DB::table('tickets')->insertGetId([
            'company_id'  => $request->company_id,
            'branch_id'   => $request->branch_id,
            'user_id'     => $request->user_id,
            'subject'     => $request->subject,
            'description' => $request->description,
            'status'      => $request->status ?? 'open',
            'priority'    => $request->priority ?? 'normal',
            'category'    => $request->category,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        return response()->json(['id' => $id, 'message' => 'Ticket created.'], 201);
    });

    Route::put('/tickets/{id}', function (Request $request, $id) {
        DB::table('tickets')->where('id', $id)->update([
            'subject'     => $request->subject,
            'description' => $request->description,
            'status'      => $request->status,
            'priority'    => $request->priority,
            'category'    => $request->category,
            'branch_id'   => $request->branch_id,
            'updated_at'  => now(),
        ]);
        return response()->json(['message' => 'Ticket updated.']);
    });

    Route::delete('/tickets/{id}', function ($id) {
        DB::table('tickets')->where('id', $id)->delete();
        return response()->json(['message' => 'Ticket deleted.']);
    });

    // ── Notifications ─────────────────────────────────────────────────────────
    Route::get('/notifications', function (Request $request) {
        $user  = $request->user();
        $query = DB::table('notifications')
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('company_id', $user->company_id);
            })
            ->orderBy('created_at', 'desc');
        return response()->json($query->get());
    });

    Route::put('/notifications/read-all', function (Request $request) {
        $user = $request->user();
        DB::table('notifications')
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('company_id', $user->company_id);
            })
            ->update(['read' => true]);
        return response()->json(['message' => 'All notifications marked as read.']);
    });

    Route::put('/notifications/{id}/read', function (Request $request, $id) {
        DB::table('notifications')->where('id', $id)->update(['read' => true]);
        return response()->json(['message' => 'Notification marked as read.']);
    });

    Route::post('/notifications', function (Request $request) {
        $id = DB::table('notifications')->insertGetId([
            'user_id'    => $request->user_id,
            'company_id' => $request->company_id,
            'type'       => $request->type ?? 'info',
            'title'      => $request->title,
            'message'    => $request->message,
            'read'       => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['id' => $id, 'message' => 'Notification created.'], 201);
    });

    // ── Users (portal user management) ───────────────────────────────────────
    Route::get('/users', function (Request $request) {
        $query = DB::table('users')->select(
            'id','full_name','name','email','role','company_id','created_at','status','phone','position'
        );
        if ($request->company_id) $query->where('company_id', $request->company_id);
        $users = $query->get();
        return response()->json($users);
    });

    Route::post('/users', function (Request $request) {
        $request->validate([
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|min:6',
        ]);
        $id = DB::table('users')->insertGetId([
            'full_name'     => $request->full_name ?? $request->name ?? '',
            'name'          => $request->full_name ?? $request->name ?? '',
            'email'         => $request->email,
            'password'      => bcrypt($request->password),
            'password_hash' => bcrypt($request->password),
            'role'          => $request->access_level ?? $request->role ?? 'user',
            'phone'         => $request->phone_number ?? $request->phone,
            'position'      => $request->position_title ?? $request->position,
            'status'        => $request->status ?? 'active',
            'company_id'    => $request->company_id,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        return response()->json(['id' => $id, 'message' => 'User created.'], 201);
    });

    Route::put('/users/{id}', function (Request $request, $id) {
        $fields = array_filter([
            'name'     => $request->name,
            'email'    => $request->email,
            'phone'    => $request->phone,
            'position' => $request->position,
            'status'   => $request->status,
            'role'     => $request->role,
        ], fn($v) => !is_null($v));

        $fields['updated_at'] = now();
        DB::table('users')->where('id', $id)->update($fields);
        return response()->json(['message' => 'User updated.']);
    });

    Route::delete('/users/{id}', function ($id) {
        DB::table('users')->where('id', $id)->update(['status' => 'inactive', 'updated_at' => now()]);
        return response()->json(['message' => 'User deactivated.']);
    });

    // ── Chat (Ollama-powered assistant) ───────────────────────────────────────
    Route::post  ('chat',         [ChatController::class, 'send']);
    Route::get   ('chat/history', [ChatController::class, 'history']);
    Route::delete('chat/history', [ChatController::class, 'clearHistory']);

    // ── File upload ───────────────────────────────────────────────────────────
    Route::post('upload', [UploadController::class, 'store']);
});
