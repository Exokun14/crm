<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SettingsController
 *
 * Manages global settings stored in a simple `settings` key-value table.
 * Categories are stored as individual rows with key = 'category' and value = the name.
 * Colors are stored as a JSON array in a single row with key = 'colors'.
 *
 * Table schema (create if not exists):
 *   settings (id, key varchar, value text, created_at, updated_at)
 *
 * Routes already in api.php:
 *   GET    /api/settings
 *   GET    /api/settings/categories
 *   POST   /api/settings/categories          body: { name }
 *   DELETE /api/settings/categories/{name}
 *   PUT    /api/settings/categories/{name}   body: { name }  ← NEW (rename)
 *   GET    /api/settings/colors
 */
class SettingsController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/settings
    // ─────────────────────────────────────────────────────────────────────────
    public function index()
    {
        return response()->json([
            'categories' => $this->getCategories(),
            'colors'     => $this->getColorsArray(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/settings/categories
    // ─────────────────────────────────────────────────────────────────────────
    public function categories()
    {
        Log::channel('stderr')->info('[SettingsController@categories] ▶ Fetching categories');
        $cats = $this->getCategories();
        Log::channel('stderr')->info('[SettingsController@categories] ✅ Returning ' . count($cats) . ' categories');
        return response()->json($cats);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/settings/categories
    // body: { "name": "New Category" }
    // ─────────────────────────────────────────────────────────────────────────
    public function storeCategory(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $name = trim($request->name);

        Log::channel('stderr')->info('[SettingsController@storeCategory] ▶ name=' . $name);

        // Prevent duplicates (case-insensitive)
        $exists = DB::table('settings')
            ->where('key', 'category')
            ->whereRaw('LOWER(value) = ?', [strtolower($name)])
            ->exists();

        if ($exists) {
            return response()->json(['error' => 'Category already exists'], 422);
        }

        DB::table('settings')->insert([
            'key'        => 'category',
            'value'      => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::channel('stderr')->info('[SettingsController@storeCategory] ✅ Created category: ' . $name);

        return response()->json(['message' => 'Category created', 'name' => $name], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /api/settings/categories/{name}
    // ─────────────────────────────────────────────────────────────────────────
    public function deleteCategory($name)
    {
        $name = urldecode($name);

        Log::channel('stderr')->info('[SettingsController@deleteCategory] ▶ name=' . $name);

        $deleted = DB::table('settings')
            ->where('key', 'category')
            ->where('value', $name)
            ->delete();

        if (!$deleted) {
            return response()->json(['error' => 'Category not found'], 404);
        }

        Log::channel('stderr')->info('[SettingsController@deleteCategory] ✅ Deleted category: ' . $name);

        return response()->json(['message' => 'Category deleted']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /api/settings/categories/{oldName}
    // body: { "name": "New Name" }
    // Rename an existing category.
    // ─────────────────────────────────────────────────────────────────────────
    public function renameCategory(Request $request, $oldName)
    {
        $oldName = urldecode($oldName);
        $request->validate([
            'name' => 'required|string|max:100',
        ]);
        $newName = trim($request->name);

        Log::channel('stderr')->info('[SettingsController@renameCategory] ▶ ' . $oldName . ' → ' . $newName);

        // Check the old one exists
        $row = DB::table('settings')
            ->where('key', 'category')
            ->where('value', $oldName)
            ->first();

        if (!$row) {
            return response()->json(['error' => 'Category not found'], 404);
        }

        // Prevent collision with another existing category
        $collision = DB::table('settings')
            ->where('key', 'category')
            ->whereRaw('LOWER(value) = ?', [strtolower($newName)])
            ->where('id', '!=', $row->id)
            ->exists();

        if ($collision) {
            return response()->json(['error' => 'A category with that name already exists'], 422);
        }

        DB::table('settings')
            ->where('id', $row->id)
            ->update(['value' => $newName, 'updated_at' => now()]);

        Log::channel('stderr')->info('[SettingsController@renameCategory] ✅ Renamed: ' . $oldName . ' → ' . $newName);

        return response()->json(['message' => 'Category renamed', 'name' => $newName]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/settings/colors
    // ─────────────────────────────────────────────────────────────────────────
    public function colors()
    {
        return response()->json($this->getColorsArray());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────
    private function getCategories(): array
    {
        return DB::table('settings')
            ->where('key', 'category')
            ->orderBy('value')
            ->pluck('value')
            ->toArray();
    }

    private function getColorsArray(): array
    {
        $row = DB::table('settings')->where('key', 'colors')->first();
        if (!$row) {
            // Return sensible defaults if no colors are configured yet
            return ['#7c3aed', '#0d9488', '#f59e0b', '#ef4444', '#3b82f6', '#10b981'];
        }
        return json_decode($row->value, true) ?? [];
    }
}
