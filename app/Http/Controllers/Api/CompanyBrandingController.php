<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CompanyBrandingController extends Controller
{
    // -------------------------------------------------------------------------
    // PUT/PATCH  /api/companies/{company}/cover-photo
    // -------------------------------------------------------------------------
    /**
     * Upload (or replace) the company's hero / cover photo.
     *
     * Accepts:  multipart/form-data   field: "cover_photo"  (image, max 5 MB)
     * Returns:  { cover_photo_url: "https://..." }
     */
    public function updateCoverPhoto(Request $request, Company $company): JsonResponse
    {
        $this->authorizeCompany($request, $company);

        $request->validate([
            'cover_photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'],
        ]);

        // Delete the old file if one exists
        if ($company->cover_photo_path) {
            Storage::disk('public')->delete($company->cover_photo_path);
        }

        // Store the new file in  storage/app/public/covers/{company_id}/
        $path = $request->file('cover_photo')
            ->store("covers/{$company->id}", 'public');

        $company->update(['cover_photo_path' => $path]);

        return response()->json([
            'message'          => 'Cover photo updated successfully.',
            'cover_photo_url'  => Storage::disk('public')->url($path),
            'cover_photo_path' => $path,
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE  /api/companies/{company}/cover-photo
    // -------------------------------------------------------------------------
    /**
     * Remove the company's cover photo (reverts to the default background).
     */
    public function deleteCoverPhoto(Request $request, Company $company): JsonResponse
    {
        $this->authorizeCompany($request, $company);

        if ($company->cover_photo_path) {
            Storage::disk('public')->delete($company->cover_photo_path);
            $company->update(['cover_photo_path' => null]);
        }

        return response()->json(['message' => 'Cover photo removed.']);
    }

    // -------------------------------------------------------------------------
    // PUT/PATCH  /api/companies/{company}/brand-color
    // -------------------------------------------------------------------------
    /**
     * Save the brand colour chosen in the colour-wheel picker.
     *
     * Accepts:  { "brand_color": "#f97316" }
     *           Pass null / empty string to clear the override and let the
     *           frontend auto-derive the colour from the company logo.
     *
     * Returns:  { brand_color: "#f97316" }
     */
    public function updateBrandColor(Request $request, Company $company): JsonResponse
    {
        $this->authorizeCompany($request, $company);

        $request->validate([
            'brand_color' => [
                'nullable',
                'string',
                // Allow #RGB, #RRGGBB, #RRGGBBAA
                'regex:/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/',
            ],
        ]);

        $color = $request->input('brand_color') ?: null;   // empty string → null
        $company->update(['brand_color' => $color]);

        return response()->json([
            'message'     => $color ? 'Brand colour updated.' : 'Brand colour reset to logo default.',
            'brand_color' => $company->brand_color,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET  /api/companies/{company}/branding
    // -------------------------------------------------------------------------
    /**
     * Return all branding data for a company in one call
     * (handy for the initial page load in OverviewPage).
     */
    public function show(Company $company): JsonResponse
    {
        return response()->json([
            'cover_photo_url' => $company->cover_photo_path
                ? Storage::disk('public')->url($company->cover_photo_path)
                : null,
            'brand_color' => $company->brand_color,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Make sure the authenticated user belongs to (or manages) the company.
     * Throw a 403 if not.
     */
    private function authorizeCompany(Request $request, Company $company): void
    {
        $user = $request->user();

        // Admins / super-admins can edit any company
        if ($user->isAdmin()) {
            return;
        }

        // Regular users may only edit their own company
        if ((int) $user->company_id !== (int) $company->id) {
            abort(403, 'You are not authorised to modify this company.');
        }
    }
}
