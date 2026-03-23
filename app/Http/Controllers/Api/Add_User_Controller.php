<?php
/* Add_User_Controller.php
 * dhenz_app\app\Http\Controllers\Api\Add_User_Controller.php
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Add_User_Controller extends Controller
{
    /**
     * Resolve a stored profile photo path into a publicly accessible URL.
     */
    private function resolvePhotoUrl(?string $path): ?string
    {
        if (!$path) return null;

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $appUrl = rtrim(config('app.url'), '/');
        return $appUrl . '/storage/' . ltrim($path, '/');
    }

    /**
     * Delete a local profile photo from disk (safe: ignores http URLs and nulls).
     */
    private function deleteLocalPhoto(?string $path): void
    {
        if ($path && !str_starts_with($path, 'http')) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Return all users joined with their company name.
     */
    public function index(): \Illuminate\Http\JsonResponse
    {
        $users = DB::table('users as u')
            ->leftJoin('company as c', 'c.id', '=', 'u.company_id')
            ->orderBy('u.created_at', 'desc')
            ->select([
                'u.id',
                'u.profile_photo',
                'u.full_name',
                'u.email',
                'u.phone_number',
                'u.company_id',
                'c.company_name',
                'u.position_title',
                'u.access_level',
                'u.access_id',
                'u.status',
                'u.created_at',
                'u.updated_at',
            ])
            ->get()
            ->map(function ($row) {
                $row->profile_photo = $this->resolvePhotoUrl($row->profile_photo);
                return $row;
            });

        return response()->json([
            'success' => true,
            'data'    => $users,
        ]);
    }

    /**
     * Store a newly created user.
     * Profile photo is saved to public/storage/user_profiles/
     * The client renames the file to {slug}_{timestamp}.{ext} before sending,
     * but we also rename server-side as a safety net.
     */
    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'full_name'      => 'required|string|max:150',
            'email'          => 'required|email|max:150|unique:users,email',
            'phone_number'   => 'nullable|string|max:30',
            'company_id'     => 'nullable|integer|exists:company,id',
            'position_title' => 'nullable|string|max:150',
            'access_level'   => 'required|in:super_admin,system_admin,manager,user',
            'access_id'      => 'required|integer',
            'status'         => 'nullable|in:active,inactive',
            'password'       => 'required|string|min:6',
            'profile_photo'  => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:4096',
        ]);

        /* ── Handle profile photo ─────────────────────────────────── */
        $photoPath = null;

        if ($request->hasFile('profile_photo') && $request->file('profile_photo')->isValid()) {
            $photoPath = $this->storePhoto($request->file('profile_photo'), $validated['full_name']);

            if ($photoPath === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Profile photo upload failed. Please try again.',
                ], 500);
            }
        }

        /* ── Insert user ──────────────────────────────────────────── */
        try {
            $userId = DB::table('users')->insertGetId([
                'profile_photo'  => $photoPath,
                'full_name'      => $validated['full_name'],
                'email'          => $validated['email'],
                'phone_number'   => $validated['phone_number']   ?? null,
                'company_id'     => $validated['company_id']     ?? null,
                'position_title' => $validated['position_title'] ?? null,
                'access_level'   => $validated['access_level'],
                'access_id'      => $validated['access_id'],
                'status'         => $validated['status']         ?? 'inactive',
                'password_hash'  => $validated['password'],
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('User insert failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to save user. Please try again.',
            ], 500);
        }

        return response()->json([
            'success'       => true,
            'id'            => $userId,
            'profile_photo' => $this->resolvePhotoUrl($photoPath),
            'message'       => 'User created successfully.',
        ], 201);
    }

    /**
     * Update an existing user.
     *
     * Accepts POST + _method=PUT (FormData with optional file) or native PUT (JSON).
     *
     * Photo behaviour:
     *   • remove_photo=1  → delete existing file, set profile_photo to NULL
     *   • profile_photo file sent → replace existing file with new upload
     *   • neither sent → keep existing photo unchanged
     */
    public function update(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $user = DB::table('users')->where('id', $id)->first();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User not found.'], 404);
        }

        $validated = $request->validate([
            'full_name'      => 'required|string|max:150',
            'email'          => "required|email|max:150|unique:users,email,{$id}",
            'phone_number'   => 'nullable|string|max:30',
            'company_id'     => 'nullable|integer|exists:company,id',
            'company_name'   => 'nullable|string|max:150',
            'position_title' => 'nullable|string|max:150',
            'access_level'   => 'required|in:super_admin,system_admin,manager,user',
            'access_id'      => 'required|integer',
            'status'         => 'nullable|in:active,inactive',
            'password'       => 'nullable|string|min:6',
            'profile_photo'  => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:4096',
            'remove_photo'   => 'nullable|in:0,1',
        ]);

        /* ── Resolve company_id ───────────────────────────────────── */
        $companyId = $validated['company_id'] ?? $user->company_id;

        if (empty($companyId) && !empty($validated['company_name'])) {
            $company   = DB::table('company')
                ->whereRaw('LOWER(company_name) = ?', [strtolower(trim($validated['company_name']))])
                ->first();
            $companyId = $company?->id ?? $user->company_id;
        }

        /* ── Handle profile photo ─────────────────────────────────── */
        $photoPath = $user->profile_photo; // default: keep existing

        if (!empty($validated['remove_photo'])) {
            $this->deleteLocalPhoto($user->profile_photo);
            $photoPath = null;

        } elseif ($request->hasFile('profile_photo') && $request->file('profile_photo')->isValid()) {
            $this->deleteLocalPhoto($user->profile_photo);

            $photoPath = $this->storePhoto($request->file('profile_photo'), $validated['full_name']);

            if ($photoPath === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Profile photo upload failed. Please try again.',
                ], 500);
            }
        }

        /* ── Build update payload ─────────────────────────────────── */
        $payload = [
            'profile_photo'  => $photoPath,
            'full_name'      => $validated['full_name'],
            'email'          => $validated['email'],
            'phone_number'   => $validated['phone_number']   ?? null,
            'company_id'     => $companyId,
            'position_title' => $validated['position_title'] ?? null,
            'access_level'   => $validated['access_level'],
            'access_id'      => $validated['access_id'],
            'status'         => $validated['status']         ?? $user->status,
            'updated_at'     => now(),
        ];

        if (!empty($validated['password'])) {
            $payload['password_hash'] = $validated['password'];
        }

        try {
            DB::table('users')->where('id', $id)->update($payload);
        } catch (\Throwable $e) {
            Log::error('User update failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user. Please try again.',
            ], 500);
        }

        return response()->json([
            'success'       => true,
            'id'            => $id,
            'profile_photo' => $this->resolvePhotoUrl($photoPath),
            'message'       => 'User updated successfully.',
        ]);
    }

    /**
     * Delete a user and their profile photo from storage.
     */
    public function destroy(int $id): \Illuminate\Http\JsonResponse
    {
        $user = DB::table('users')->where('id', $id)->first();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User not found.'], 404);
        }

        $this->deleteLocalPhoto($user->profile_photo);

        try {
            DB::table('users')->where('id', $id)->delete();
        } catch (\Throwable $e) {
            Log::error('User delete failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user. Please try again.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'id'      => $id,
            'message' => 'User deleted successfully.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Private helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Store an uploaded photo to public/storage/user_profiles/ and return
     * the relative path (e.g. "user_profiles/jane_smith_1718123456.jpg").
     * Returns null on failure.
     */
    private function storePhoto(\Illuminate\Http\UploadedFile $file, string $fullName): ?string
    {
        $originalName = $file->getClientOriginalName();
        $ext          = strtolower($file->getClientOriginalExtension());

        if (preg_match('/^[a-z0-9_]+-?\d{10,}\.(' . $ext . ')$/i', $originalName)) {
            $filename = $originalName;
        } else {
            $safeName = Str::slug($fullName, '_') ?: 'user';
            $filename = $safeName . '_' . time() . '.' . $ext;
        }

        Storage::disk('public')->makeDirectory('user_profiles');
        $file->storeAs('user_profiles', $filename, 'public');

        if (!Storage::disk('public')->exists('user_profiles/' . $filename)) {
            Log::error('Profile photo upload failed', ['filename' => $filename]);
            return null;
        }

        return 'user_profiles/' . $filename;
    }
}









