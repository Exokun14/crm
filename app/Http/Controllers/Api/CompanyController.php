<?php
/* CompanyController.php
 * dhenz_app\app\Http\Controllers\Api\CompanyController.php */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CompanyController extends Controller
{
    /**
     * Resolve a stored logo path/URL into a publicly accessible URL.
     */
    private function resolveLogoUrl(?string $logoPath): ?string
    {
        if (!$logoPath) return null;

        if (str_starts_with($logoPath, 'http://') || str_starts_with($logoPath, 'https://')) {
            return $logoPath;
        }

        $appUrl = rtrim(config('app.url'), '/');
        return $appUrl . '/storage/' . ltrim($logoPath, '/');
    }

    /**
     * Resolve a stored cover photo path into a publicly accessible URL.
     */
    private function resolveCoverUrl(?string $coverPath): ?string
    {
        if (!$coverPath) return null;

        if (str_starts_with($coverPath, 'http://') || str_starts_with($coverPath, 'https://')) {
            return $coverPath;
        }

        $appUrl = rtrim(config('app.url'), '/');
        return $appUrl . '/storage/' . ltrim($coverPath, '/');
    }

    /**
     * Store a newly created company in the company table.
     *
     * Handles:
     *  - industry_type       : int FK to industry_cards.id
     *  - activation_code     : stored directly on company.activation_code
     *  - krunch_id (string)  : inserted into the krunch_id table
     *                          (company_id, krunch) — company.krunch_id FK
     *                          is then updated to point at the new row.
     *  - alt_contacts        : up to 2, stored in company_alternate_contact
     *  - background_color    : banner background color (hex string)
     *  - panel_color         : logo panel background color (hex string)
     */
    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'company_name'     => 'required|string|max:255',
            'industry_type'    => 'required|integer|exists:industry_cards,id',
            'contact_person'   => 'required|string|max:255',
            'email'            => 'required|email|max:255',
            'phone'            => 'nullable|string|max:50',
            'account_manager'  => 'nullable|string|max:255',
            'activation_code'  => 'nullable|string|max:255',
            'krunch_id'        => 'nullable|string|max:255',
            'company_logo'     => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,svg|max:4096',
            'company_logo_url' => 'nullable|url|max:2048',
            'cover_photo'      => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:8192',
            'alt_contacts'     => 'nullable|string',
            'background_color' => 'nullable|string|max:20',
            'panel_color'      => 'nullable|string|max:20',
        ]);

        /* ── Parse alternate contacts JSON ───────────────────────────────── */
        $altContacts = [];
        if (!empty($validated['alt_contacts'])) {
            $decoded = json_decode($validated['alt_contacts'], true);
            if (is_array($decoded)) {
                $altContacts = array_slice(
                    array_filter($decoded, fn($a) => !empty(trim($a['name'] ?? ''))),
                    0, 2
                );
            }
        }

        /* ── Handle logo ─────────────────────────────────────────────────── */
        $logoPath = null;

        if ($request->hasFile('company_logo') && $request->file('company_logo')->isValid()) {
            $file     = $request->file('company_logo');
            $safeName = Str::slug($validated['company_name'], '_');
            $ext      = strtolower($file->getClientOriginalExtension());
            $filename = $safeName . '.' . $ext;

            Storage::disk('public')->makeDirectory('company_logos');
            $file->storeAs('company_logos', $filename, 'public');

            if (!Storage::disk('public')->exists('company_logos/' . $filename)) {
                Log::error('Logo upload failed — file not found after storeAs', [
                    'filename' => $filename,
                    'original' => $file->getClientOriginalName(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Logo upload failed. Please try again.',
                ], 500);
            }

            $logoPath = 'company_logos/' . $filename;

        } elseif ($request->filled('company_logo_url')) {
            $logoPath = $request->input('company_logo_url');

        } elseif ($request->hasFile('company_logo')) {
            Log::warning('Invalid logo file received', [
                'error' => $request->file('company_logo')->getError(),
            ]);
        }

        /* ── Handle cover photo ──────────────────────────────────────────── */
        $coverPath = null;
        if ($request->hasFile('cover_photo') && $request->file('cover_photo')->isValid()) {
            $coverFile = $request->file('cover_photo');
            $safeName  = Str::slug($validated['company_name'], '_') . '_banner';
            $ext       = strtolower($coverFile->getClientOriginalExtension());
            $filename  = $safeName . '_' . time() . '.' . $ext;

            Storage::disk('public')->makeDirectory('banner_logos');
            $coverFile->storeAs('banner_logos', $filename, 'public');

            if (Storage::disk('public')->exists('banner_logos/' . $filename)) {
                $coverPath = 'banner_logos/' . $filename;
            } else {
                Log::error('Cover photo upload failed', ['filename' => $filename]);
            }
        }

        /* ── Insert company + krunch_id row + alternate contacts ─────────── */
        DB::beginTransaction();

        try {
            /* 1. Insert the company row (krunch_id FK set to null initially) */
            $companyId = DB::table('company')->insertGetId([
                'company_name'        => $validated['company_name'],
                'industry_type'       => (int) $validated['industry_type'],
                'contact_person'      => $validated['contact_person'],
                'email'               => $validated['email'],
                'phone'               => $validated['phone']            ?? null,
                'account_manager'     => $validated['account_manager']  ?? null,
                'activation_code'     => $validated['activation_code']  ?? null,
                'krunch_id'           => null,
                'company_logo'        => $logoPath,
                'cover_photo'         => $coverPath,
                'background_color'    => $validated['background_color'] ?? '#7c3aed',
                'panel_color'         => $validated['panel_color']       ?? '#0f766e',
                'alternate_contact_1' => null,
                'alternate_contact_2' => null,
            ]);

            /* 2. Insert krunch_id row if a value was provided */
            $krunchRowId = null;
            $krunchValue = $validated['krunch_id'] ?? null;

            if ($krunchValue !== null && trim($krunchValue) !== '') {
                $krunchRowId = DB::table('krunch_id')->insertGetId([
                    'company_id' => $companyId,
                    'krunch'     => trim($krunchValue),
                ]);

                DB::table('company')
                    ->where('id', $companyId)
                    ->update(['krunch_id' => $krunchRowId]);
            }

            /* 3. Insert alternate contacts */
            $altId1 = null;
            $altId2 = null;

            foreach ($altContacts as $index => $alt) {
                $altId = DB::table('company_alternate_contact')->insertGetId([
                    'company_id'     => $companyId,
                    'company_under'  => $validated['company_name'],
                    'contact_person' => trim($alt['name']  ?? ''),
                    'email'          => trim($alt['email'] ?? '') ?: null,
                    'phone'          => trim($alt['phone'] ?? '') ?: null,
                ]);

                if ($index === 0) $altId1 = $altId;
                if ($index === 1) $altId2 = $altId;
            }

            if ($altId1 !== null || $altId2 !== null) {
                DB::table('company')
                    ->where('id', $companyId)
                    ->update([
                        'alternate_contact_1' => $altId1,
                        'alternate_contact_2' => $altId2,
                    ]);
            }

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Company insert failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to save company. Please try again.',
            ], 500);
        }

        $logoUrl = $this->resolveLogoUrl($logoPath);

        return response()->json([
            'success'             => true,
            'id'                  => $companyId,
            'logo_path'           => $logoUrl,
            'krunch_row_id'       => $krunchRowId,
            'krunch_num'          => $krunchValue ? trim($krunchValue) : null,
            'alternate_contact_1' => $altId1,
            'alternate_contact_2' => $altId2,
            'message'             => 'Company saved successfully.',
        ], 201);
    }

    /**
     * Update an existing company's general information.
     *
     * Handles:
     *  - company_name, contact_person, email, phone     (company table)
     *  - industry_type                                  (int FK to industry_cards.id)
     *  - activation_code                                (company.activation_code)
     *  - krunch_id (string)                             (upserts krunch_id table row)
     *  - company_logo / company_logo_url
     *  - alt_contacts JSON array (up to 2)
     *  - background_color                               (banner background hex color)
     *  - panel_color                                    (logo panel hex color)
     *
     * Route:  PUT/PATCH  /api/companies/{id}
     *         POST       /api/companies/{id}   (FormData fallback)
     */
    public function update(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        /* ── Confirm company exists ──────────────────────────────────────── */
        $company = DB::table('company')->where('id', $id)->first();
        if (!$company) {
            return response()->json(['success' => false, 'message' => 'Company not found.'], 404);
        }

        /* ── Validate ────────────────────────────────────────────────────── */
        $validated = $request->validate([
            'company_name'     => 'sometimes|required|string|max:255',
            'industry_type'    => 'sometimes|integer|exists:industry_cards,id',
            'contact_person'   => 'sometimes|required|string|max:255',
            'email'            => 'sometimes|required|email|max:255',
            'phone'            => 'nullable|string|max:50',
            'activation_code'  => 'nullable|string|max:255',
            'krunch_id'        => 'nullable|string|max:255',
            'company_logo'     => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,svg|max:4096',
            'company_logo_url' => 'nullable|max:2048',
            'cover_photo'      => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:8192',
            'alt_contacts'     => 'nullable|string',
            'background_color' => 'nullable|string|max:20',
            'panel_color'      => 'nullable|string|max:20',
        ]);

        /* ── Parse alternate contacts ────────────────────────────────────── */
        $altContacts = [];
        if (!empty($validated['alt_contacts'])) {
            $decoded = json_decode($validated['alt_contacts'], true);
            if (is_array($decoded)) {
                $altContacts = array_slice(
                    array_filter($decoded, fn($a) => !empty(trim($a['name'] ?? ''))),
                    0, 2
                );
            }
        }

        /* ── Handle logo ─────────────────────────────────────────────────── */
        $logoPath = $company->company_logo;

        if ($request->hasFile('company_logo') && $request->file('company_logo')->isValid()) {
            $file     = $request->file('company_logo');
            $safeName = Str::slug($validated['company_name'] ?? $company->company_name, '_');
            $ext      = strtolower($file->getClientOriginalExtension());
            $filename = $safeName . '.' . $ext;

            Storage::disk('public')->makeDirectory('company_logos');
            $file->storeAs('company_logos', $filename, 'public');

            if (!Storage::disk('public')->exists('company_logos/' . $filename)) {
                Log::error('Logo upload failed on update', ['company_id' => $id, 'filename' => $filename]);
                return response()->json(['success' => false, 'message' => 'Logo upload failed. Please try again.'], 500);
            }

            $logoPath = 'company_logos/' . $filename;

        } elseif ($request->filled('company_logo_url')) {
            $logoPath = $request->input('company_logo_url');

        } elseif ($request->has('company_logo_url') && $request->input('company_logo_url') === '') {
            $logoPath = null;
        }

        /* ── Handle cover photo ──────────────────────────────────────────── */
        $coverPath = $company->cover_photo; // default: keep existing

        if ($request->hasFile('cover_photo') && $request->file('cover_photo')->isValid()) {
            // Delete old cover photo if it's a local file
            if ($coverPath && !str_starts_with($coverPath, 'http')) {
                Storage::disk('public')->delete($coverPath);
            }

            $coverFile = $request->file('cover_photo');
            $safeName  = Str::slug($company->company_name, '_') . '_banner';
            $ext       = strtolower($coverFile->getClientOriginalExtension());
            $filename  = $safeName . '_' . time() . '.' . $ext;

            Storage::disk('public')->makeDirectory('banner_logos');
            $coverFile->storeAs('banner_logos', $filename, 'public');

            if (Storage::disk('public')->exists('banner_logos/' . $filename)) {
                $coverPath = 'banner_logos/' . $filename;
            } else {
                Log::error('Cover photo upload failed on update', ['company_id' => $id, 'filename' => $filename]);
                return response()->json(['success' => false, 'message' => 'Cover photo upload failed. Please try again.'], 500);
            }

        } elseif ($request->has('remove_cover_photo') && $request->input('remove_cover_photo') === '1') {
            // Client explicitly removed the cover photo
            if ($coverPath && !str_starts_with($coverPath, 'http')) {
                Storage::disk('public')->delete($coverPath);
            }
            $coverPath = null;
        }

        /* ── Persist inside a transaction ────────────────────────────────── */
        DB::beginTransaction();

        try {
            /* 1. Build company update payload */
            $companyUpdateData = [
                'company_logo' => $logoPath,
                'cover_photo'  => $coverPath,
            ];

            if (isset($validated['company_name']))    $companyUpdateData['company_name']    = $validated['company_name'];
            if (isset($validated['contact_person']))  $companyUpdateData['contact_person']  = $validated['contact_person'];
            if (isset($validated['email']))           $companyUpdateData['email']           = $validated['email'];
            if (array_key_exists('phone', $validated))           $companyUpdateData['phone']           = $validated['phone'];
            if (array_key_exists('activation_code', $validated)) $companyUpdateData['activation_code'] = $validated['activation_code'];
            if (isset($validated['industry_type']))  $companyUpdateData['industry_type']  = (int) $validated['industry_type'];

            /* ── Banner colors — update only when explicitly sent ── */
            if ($request->has('background_color')) {
                $companyUpdateData['background_color'] = $validated['background_color'] ?? $company->background_color;
            }
            if ($request->has('panel_color')) {
                $companyUpdateData['panel_color'] = $validated['panel_color'] ?? $company->panel_color;
            }

            DB::table('company')->where('id', $id)->update($companyUpdateData);

            /* 2. Upsert krunch_id row */
            $krunchValue    = isset($validated['krunch_id']) ? trim($validated['krunch_id']) : null;
            $existingFkId   = $company->krunch_id;
            $newKrunchRowId = $existingFkId;

            if ($krunchValue !== null && $krunchValue !== '') {
                if ($existingFkId) {
                    DB::table('krunch_id')
                        ->where('id', $existingFkId)
                        ->update(['krunch' => $krunchValue]);
                    $newKrunchRowId = $existingFkId;
                } else {
                    $newKrunchRowId = DB::table('krunch_id')->insertGetId([
                        'company_id' => $id,
                        'krunch'     => $krunchValue,
                    ]);
                    DB::table('company')->where('id', $id)->update(['krunch_id' => $newKrunchRowId]);
                }
            } elseif ($request->has('krunch_id') && ($krunchValue === null || $krunchValue === '')) {
                if ($existingFkId) {
                    DB::table('krunch_id')->where('id', $existingFkId)->delete();
                }
                DB::table('company')->where('id', $id)->update(['krunch_id' => null]);
                $newKrunchRowId = null;
            }

            /* 3. Rebuild alternate contacts */
            $existingId1 = $company->alternate_contact_1;
            $existingId2 = $company->alternate_contact_2;
            $newAltId1   = null;
            $newAltId2   = null;
            $existingSlots = array_filter([$existingId1, $existingId2]);
            $slotsUsed     = [];

            foreach ($altContacts as $index => $alt) {
                $name    = trim($alt['name']  ?? '');
                $email   = trim($alt['email'] ?? '') ?: null;
                $phone   = trim($alt['phone'] ?? '') ?: null;
                $reuseId = $index === 0 ? $existingId1 : $existingId2;

                if ($reuseId && in_array($reuseId, $existingSlots)) {
                    DB::table('company_alternate_contact')
                        ->where('id', $reuseId)
                        ->update([
                            'company_under'  => $validated['company_name'] ?? $company->company_name,
                            'contact_person' => $name,
                            'email'          => $email,
                            'phone'          => $phone,
                        ]);
                    $altId = $reuseId;
                    $slotsUsed[] = $reuseId;
                } else {
                    $altId = DB::table('company_alternate_contact')->insertGetId([
                        'company_id'     => $id,
                        'company_under'  => $validated['company_name'] ?? $company->company_name,
                        'contact_person' => $name,
                        'email'          => $email,
                        'phone'          => $phone,
                    ]);
                }

                if ($index === 0) $newAltId1 = $altId;
                if ($index === 1) $newAltId2 = $altId;
            }

            $toDelete = array_diff($existingSlots, $slotsUsed);
            if (!empty($toDelete)) {
                DB::table('company_alternate_contact')->whereIn('id', $toDelete)->delete();
            }

            if ($request->has('alt_contacts')) {
                DB::table('company')->where('id', $id)->update([
                    'alternate_contact_1' => $newAltId1,
                    'alternate_contact_2' => $newAltId2,
                ]);
            }

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Company update failed', ['company_id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to update company. Please try again.',
            ], 500);
        }

        /* Re-fetch updated row to return fresh color values */
        $updatedCompany = DB::table('company')->where('id', $id)->first();

        return response()->json([
            'success'             => true,
            'id'                  => $id,
            'logo_path'           => $this->resolveLogoUrl($logoPath),
            'cover_photo_url'     => $this->resolveCoverUrl($coverPath),
            'background_color'    => $updatedCompany->background_color,
            'panel_color'         => $updatedCompany->panel_color,
            'krunch_row_id'       => $newKrunchRowId,
            'alternate_contact_1' => $newAltId1,
            'alternate_contact_2' => $newAltId2,
            'message'             => 'Company updated successfully.',
        ]);
    }

    /**
     * Return all companies with alternate contact details,
     * industry card title, activation code, and krunch value joined in.
     */
    public function index(): \Illuminate\Http\JsonResponse
    {
        $companies = DB::table('company as c')
            ->leftJoin('company_alternate_contact as a1', 'a1.id', '=', 'c.alternate_contact_1')
            ->leftJoin('company_alternate_contact as a2', 'a2.id', '=', 'c.alternate_contact_2')
            ->leftJoin('industry_cards as ic',            'ic.id', '=', 'c.industry_type')
            ->leftJoin('krunch_id as k',                  'k.id',  '=', 'c.krunch_id')
            ->orderBy('c.created_at', 'desc')
            ->select([
                'c.id',
                'c.company_name',
                'c.company_logo',
                'c.cover_photo',
                'c.background_color',
                'c.panel_color',
                'c.industry_type',
                'ic.title as industry_title',
                'c.contact_person',
                'c.email',
                'c.phone',
                'c.account_manager',
                'c.activation_code',
                'c.krunch_id',
                'k.krunch as krunch_num',
                'c.alternate_contact_1',
                'c.alternate_contact_2',
                'c.created_at',
                'a1.contact_person as alt1_name',
                'a1.email          as alt1_email',
                'a1.phone          as alt1_phone',
                'a2.contact_person as alt2_name',
                'a2.email          as alt2_email',
                'a2.phone          as alt2_phone',
            ])
            ->get()
            ->map(function ($row) {
                $row->company_logo = $this->resolveLogoUrl($row->company_logo);
                $row->cover_photo  = $this->resolveCoverUrl($row->cover_photo);
                return $row;
            });

        return response()->json([
            'success' => true,
            'data'    => $companies,
        ]);
    }
}