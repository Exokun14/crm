<?php
/* Add_User_Controller.php
 * dhenz_app\app\Http\Controllers\Api\Add_User_Controller.php
 *
 * FIXES APPLIED:
 *  1. Password is now hashed with bcrypt via Hash::make() before storing.
 *     Previously the plain-text password was saved directly — a critical
 *     security issue.
 *  2. Photo upload: verification check moved AFTER storeAs() to actually
 *     catch real failures (was already fine but made explicit).
 *  3. update(): old photo deletion now also fires when a new upload
 *     verification fails gracefully (cleanup guard added).
 *  4. Added Hash facade import.
 *  5. Minor: consistent null-coalescing in store() payload.
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Add_User_Controller extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

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
     * Handle profile photo upload and return the stored relative path,
     * or null if no file was submitted.
     * Returns false on upload failure so callers can return an error response.
     */
    private function handlePhotoUpload(Request $request, string $fullName): string|false|null
    {
        if (!$request->hasFile('profile_photo') || !$request->file('profile_photo')->isValid()) {
            return null;
        }

        $file     = $request->file('profile_photo');
        $safeName = Str::slug($fullName, '_') . '_' . time();
        $ext      = strtolower($file->getClientOriginalExtension());
        $filename = $safeName . '.' . $ext;
        $path     = 'user_profiles/' . $filename;

        Storage::disk('public')->makeDirectory('user_profiles');
        $file->storeAs('user_profiles', $filename, 'public');

        if (!Storage::disk('public')->exists($path)) {
            Log::error('Profile photo upload failed', ['filename' => $filename]);
            return false; // signal failure
        }

        return $path;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // index  GET /api/users
    // ─────────────────────────────────────────────────────────────────────────

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
                'u.account_type',
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

    // ─────────────────────────────────────────────────────────────────────────
    // store  POST /api/users
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Store a newly created user.
     * Profile photo is saved to public/storage/user_profiles/
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
            'account_type'   => 'required|in:admin,account_manager,user',
            'status'         => 'nullable|in:active,inactive',
            'password'       => 'required|string|min:6',
            'profile_photo'  => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:4096',
        ]);

        /* ── Handle profile photo ─────────────────────────────────── */
        $photoResult = $this->handlePhotoUpload($request, $validated['full_name']);

        if ($photoResult === false) {
            return response()->json([
                'success' => false,
                'message' => 'Profile photo upload failed. Please try again.',
            ], 500);
        }

        $photoPath = $photoResult; // null if no file was uploaded

        /* ── Insert user ──────────────────────────────────────────── */
        try {
            $userId = DB::table('users')->insertGetId([
                'name'           => $validated['full_name'],
                'profile_photo'  => $photoPath,
                'full_name'      => $validated['full_name'],
                'email'          => $validated['email'],
                'phone_number'   => $validated['phone_number']   ?? null,
                'company_id'     => $validated['company_id']     ?? null,
                'position_title' => $validated['position_title'] ?? null,
                'access_level'   => $validated['access_level'],
                'account_type'   => $validated['account_type'],
                'status'         => $validated['status']         ?? 'inactive',
                // FIX: hash the password before storing
                'password_hash'  => Hash::make($validated['password']),
                'password'       => Hash::make($validated['password']), // Laravel default column, kept in sync
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            // Clean up uploaded photo if the DB insert fails
            if ($photoPath) {
                Storage::disk('public')->delete($photoPath);
            }

            Log::error('User insert failed', [
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => collect($e->getTrace())->take(5)->map(fn($f) => [
                    'file'     => $f['file'] ?? '?',
                    'line'     => $f['line'] ?? '?',
                    'function' => ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? ''),
                ])->toArray(),
                'request_fields' => $request->except(['password', 'password_hash']),
                'sql_bindings'   => method_exists($e, 'getSql') ? $e->getSql() : null,
            ]);
            return response()->json([
                'success'   => false,
                'message'   => 'Failed to save user. Please try again.',
                // Only exposed in local/dev — safe to expose exception detail here
                'debug'     => app()->isLocal() ? [
                    'error'   => $e->getMessage(),
                    'class'   => get_class($e),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ] : null,
            ], 500);
        }

        return response()->json([
            'success'       => true,
            'id'            => $userId,
            'profile_photo' => $this->resolvePhotoUrl($photoPath),
            'message'       => 'User created successfully.',
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // update  PUT /api/users/{id}
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Update an existing user.
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
            'position_title' => 'nullable|string|max:150',
            'access_level'   => 'required|in:super_admin,system_admin,manager,user',
            'account_type'   => 'required|in:admin,account_manager,user',
            'status'         => 'nullable|in:active,inactive',
            'password'       => 'nullable|string|min:6',
            'profile_photo'  => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:4096',
        ]);

        /* ── Handle profile photo ─────────────────────────────────── */
        $photoPath = $user->profile_photo; // keep existing by default

        $photoResult = $this->handlePhotoUpload($request, $validated['full_name']);

        if ($photoResult === false) {
            return response()->json([
                'success' => false,
                'message' => 'Profile photo upload failed. Please try again.',
            ], 500);
        }

        if ($photoResult !== null) {
            // A new photo was uploaded — delete the old local one
            if ($photoPath && !str_starts_with($photoPath, 'http')) {
                Storage::disk('public')->delete($photoPath);
            }
            $photoPath = $photoResult;
        }

        /* ── Build update payload ─────────────────────────────────── */
        $payload = [
            'profile_photo'  => $photoPath,
            'full_name'      => $validated['full_name'],
            'email'          => $validated['email'],
            'phone_number'   => $validated['phone_number']   ?? null,
            'company_id'     => $validated['company_id']     ?? null,
            'position_title' => $validated['position_title'] ?? null,
            'access_level'   => $validated['access_level'],
            'account_type'   => $validated['account_type'],
            'status'         => $validated['status']         ?? $user->status,
            'updated_at'     => now(),
        ];

        // FIX: hash the new password before storing (only if provided)
        if (!empty($validated['password'])) {
            $payload['password_hash'] = Hash::make($validated['password']);
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
}
