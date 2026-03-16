<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

/**
 * CourseIconController
 *
 * Handles user-specific course icon uploads.
 * Icons are stored at:  storage/app/public/course_icons/{user_id}/{filename}
 * They are scoped to the authenticated user — each user sees only their own icons.
 *
 * Routes (add to api.php inside auth:sanctum group):
 *   POST   /api/course-icons          → upload a new icon
 *   GET    /api/course-icons          → list all icons for the current user
 *   DELETE /api/course-icons/{id}     → delete one icon by DB id
 */
class CourseIconController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/course-icons
    // Upload a new icon for the authenticated user.
    // ─────────────────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $user = Auth::user();

        Log::channel('stderr')->info('[CourseIconController@store] ▶ user_id=' . $user->id);

        $request->validate([
            'icon' => 'required|image|mimes:jpeg,jpg,png,webp,gif,svg|max:2048', // 2 MB max
        ]);

        $file      = $request->file('icon');
        $userId    = $user->id;

        // Store under public disk so it's web-accessible via /storage/...
        $path = $file->store("course_icons/{$userId}", 'public');

        // Persist a record so we can list & delete icons per user
        $iconId = DB::table('course_icons')->insertGetId([
            'user_id'    => $userId,
            'path'       => $path,
            'name'       => $file->getClientOriginalName(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $url = url('storage/' . $path);

        Log::channel('stderr')->info('[CourseIconController@store] ✅ Icon saved: id=' . $iconId . ' url=' . $url);

        return response()->json([
            'id'   => $iconId,
            'url'  => $url,
            'name' => $file->getClientOriginalName(),
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/course-icons
    // List all icons uploaded by the authenticated user.
    // ─────────────────────────────────────────────────────────────────────────
    public function index()
    {
        $userId = Auth::id();

        Log::channel('stderr')->info('[CourseIconController@index] ▶ user_id=' . $userId);

        $icons = DB::table('course_icons')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($icon) {
                $icon->url = url('storage/' . $icon->path);
                return $icon;
            });

        Log::channel('stderr')->info('[CourseIconController@index] ✅ Returning ' . $icons->count() . ' icons for user_id=' . $userId);

        return response()->json($icons);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /api/course-icons/{id}
    // Delete one icon. Only the owner can delete their own icons.
    // ─────────────────────────────────────────────────────────────────────────
    public function destroy($id)
    {
        $userId = Auth::id();

        Log::channel('stderr')->info('[CourseIconController@destroy] ▶ id=' . $id . ' user_id=' . $userId);

        $icon = DB::table('course_icons')
            ->where('id', $id)
            ->where('user_id', $userId) // security: only owner can delete
            ->first();

        if (!$icon) {
            Log::channel('stderr')->warning('[CourseIconController@destroy] ❌ Not found or unauthorized: id=' . $id . ' user=' . $userId);
            return response()->json(['error' => 'Icon not found or not yours'], 404);
        }

        // Remove file from disk
        Storage::disk('public')->delete($icon->path);

        // Remove DB record
        DB::table('course_icons')->where('id', $id)->delete();

        Log::channel('stderr')->info('[CourseIconController@destroy] ✅ Deleted icon id=' . $id);

        return response()->json(['message' => 'Icon deleted successfully']);
    }
}
