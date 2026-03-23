<?php
/* AuthController.php
 * app\Http\Controllers\Api\AuthController.php
 *
 * Handles credential-based login against the `users` table.
 * access_level determines which dashboard the front-end routes to:
 *   super_admin | system_admin  →  DashboardAdmin  (role: "admin")
 *   manager     | user          →  OverviewPage    (role: "client")
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * POST /api/auth/login
     *
     * Request body (JSON):
     *   email    string  required
     *   password string  required  (stored as plain text in password_hash column)
     *
     * Response (200):
     *   {
     *     "success":      true,
     *     "role":         "admin" | "client",
     *     "access_level": "super_admin" | "system_admin" | "manager" | "user",
     *     "user": {
     *       "id", "full_name", "email", "position_title",
     *       "access_level", "access_id", "status",
     *       "profile_photo", "company_id", "company_name",
     *       "company_logo", "background_color", "panel_color"
     *     }
     *   }
     *
     * Response (401):  invalid credentials
     * Response (403):  account is inactive
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        Log::info('[AuthController] Login attempt', ['email' => $validated['email']]);

        /* ── Look up user by email ─────────────────────────────── */
        $user = DB::table('users as u')
            ->leftJoin('company as c', 'c.id', '=', 'u.company_id')
            ->where('u.email', $validated['email'])
            ->select([
                'u.id',
                'u.full_name',
                'u.email',
                'u.password_hash',
                'u.position_title',
                'u.access_level',
                'u.access_id',
                'u.status',
                'u.profile_photo',
                'u.company_id',
                'c.company_name',
                'c.company_logo',
                'c.background_color',   // ← banner background color
                'c.panel_color',        // ← logo panel color
            ])
            ->first();

        /* ── Credential check ──────────────────────────────────── */
        if (!$user || $user->password_hash !== $validated['password']) {
            Log::warning('[AuthController] Login failed – bad credentials', [
                'email' => $validated['email'],
            ]);

            return response()->json([
                'success' => false,
                'message' => "The account you entered doesn't exist or the password is incorrect. Please check your credentials and try again.",
            ], 401);
        }

        /* ── Active-status check ───────────────────────────────── */
        if ($user->status !== 'active') {
            Log::warning('[AuthController] Login blocked – account inactive', [
                'email'  => $validated['email'],
                'status' => $user->status,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Your account is inactive. Please contact your administrator.',
            ], 403);
        }

        /* ── Derive front-end role from access_level ───────────── */
        $adminLevels = ['super_admin', 'system_admin'];
        $role        = in_array($user->access_level, $adminLevels, true) ? 'admin' : 'client';

        /* ── Resolve profile photo URL ─────────────────────────── */
        $photoUrl = null;
        if ($user->profile_photo) {
            if (str_starts_with($user->profile_photo, 'http')) {
                $photoUrl = $user->profile_photo;
            } else {
                $appUrl   = rtrim(config('app.url'), '/');
                $photoUrl = $appUrl . '/storage/' . ltrim($user->profile_photo, '/');
            }
        }

        /* ── Resolve company logo URL ───────────────────────────── */
        $companyLogoUrl = null;
        if ($user->company_logo) {
            if (str_starts_with($user->company_logo, 'http')) {
                $companyLogoUrl = $user->company_logo;
            } else {
                $appUrl         = rtrim(config('app.url'), '/');
                $companyLogoUrl = $appUrl . '/storage/' . ltrim($user->company_logo, '/');
            }
        }

        /* ── Build initials from full_name ─────────────────────── */
        $nameParts = array_filter(explode(' ', trim($user->full_name ?? '')));
        $initials  = '';
        foreach (array_slice($nameParts, 0, 2) as $part) {
            $initials .= strtoupper($part[0] ?? '');
        }

        Log::info('[AuthController] Login successful', [
            'id'           => $user->id,
            'email'        => $user->email,
            'access_level' => $user->access_level,
            'role'         => $role,
        ]);

        return response()->json([
            'success'      => true,
            'role'         => $role,
            'access_level' => $user->access_level,
            'user'         => [
                'id'               => $user->id,
                'full_name'        => $user->full_name,
                'email'            => $user->email,
                'initials'         => $initials ?: 'U',
                'position_title'   => $user->position_title ?? '',
                'access_level'     => $user->access_level,
                'access_id'        => $user->access_id,
                'status'           => $user->status,
                'profile_photo'    => $photoUrl,
                'company_id'       => $user->company_id,
                'company_name'     => $user->company_name  ?? '',
                'company_logo'     => $companyLogoUrl,
                'background_color' => $user->background_color ?? '#7c3aed',  // ← new
                'panel_color'      => $user->panel_color      ?? '#0f766e',  // ← new
            ],
        ], 200);
    }
}