<?php
/* CompanyController.php * dhenz_app\app\Http\Controllers\Api\CompanyController.php */

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
     * Store a newly created company in the companies table.
     */
    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'company_name'     => 'required|string|max:255',
            'industry_type'    => 'required|in:Aloha (Food & Beverage),Retail,Warehouse',
            'contact_person'   => 'required|string|max:255',
            'email'            => 'required|email|max:255',
            'phone'            => 'nullable|string|max:50',
            'account_manager'  => 'nullable|string|max:255',
            'company_logo'     => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,svg|max:4096',
            'company_logo_url' => 'nullable|url|max:2048',
            'alt_contacts'     => 'nullable|string',
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

        /* ── Insert company + alternate contacts ─────────────────────────── */
        DB::beginTransaction();

        try {
            $companyId = DB::table('companies')->insertGetId([
                'company_name'        => $validated['company_name'],
                'name'                => $validated['company_name'],
                'industry_type'       => $validated['industry_type'],
                'industry'            => $validated['industry_type'],
                'contact_person'      => $validated['contact_person'],
                'email'               => $validated['email'],
                'contact_email'       => $validated['email'],
                'phone'               => $validated['phone']           ?? null,
                'account_manager'     => $validated['account_manager'] ?? null,
                'company_logo'        => $logoPath,
                'alternate_contact_1' => null,
                'alternate_contact_2' => null,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            $altId1 = null;
            $altId2 = null;

            foreach ($altContacts as $index => $alt) {
                $altId = DB::table('company_alternate_contact')->insertGetId([
                    'company_id'     => $companyId,
                    'company_under'  => $validated['company_name'],
                    'contact_person' => trim($alt['name']  ?? ''),
                    'email'          => trim($alt['email'] ?? '') ?: null,
                    'phone'          => trim($alt['phone'] ?? '') ?: null,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);

                if ($index === 0) $altId1 = $altId;
                if ($index === 1) $altId2 = $altId;
            }

            if ($altId1 !== null || $altId2 !== null) {
                DB::table('companies')
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
            'alternate_contact_1' => $altId1,
            'alternate_contact_2' => $altId2,
            'message'             => 'Company saved successfully.',
        ], 201);
    }

    /**
     * Return all companies with their alternate contact details joined.
     */
    public function index(): \Illuminate\Http\JsonResponse
    {
        $companies = DB::table('companies as c')
            ->leftJoin('company_alternate_contact as a1', 'a1.id', '=', 'c.alternate_contact_1')
            ->leftJoin('company_alternate_contact as a2', 'a2.id', '=', 'c.alternate_contact_2')
            ->orderBy('c.created_at', 'desc')
            ->select([
                'c.id',
                // ── FIX: old seeded rows only have `name`; new rows written by store()
                //    have both. COALESCE ensures the dropdown always gets a value.
                DB::raw('COALESCE(c.company_name, c.name) as company_name'),
                'c.company_logo',
                'c.industry_type',
                'c.contact_person',
                'c.email',
                'c.phone',
                'c.account_manager',
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
                return $row;
            });

        return response()->json([
            'success' => true,
            'data'    => $companies,
        ]);
    }
}
