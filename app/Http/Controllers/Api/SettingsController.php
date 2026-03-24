<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    // GET /settings
    public function index()
    {
        return response()->json([
            'categories' => DB::table('settings_categories')->orderBy('name')->pluck('name'),
            'colors'     => [],
        ]);
    }

    // GET /settings/categories
    public function getCategories()
    {
        $cats = DB::table('settings_categories')->orderBy('name')->pluck('name');
        return response()->json($cats);
    }

    // GET /settings/colors
    public function getColors()
    {
        return response()->json([]);
    }

    // POST /settings/categories  { name: "..." }
    public function storeCategory(Request $request)
    {
        $validated = $request->validate(['name' => 'required|string|max:100|unique:settings_categories,name']);

        DB::table('settings_categories')->insert([
            'name'       => $validated['name'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Category created'], 201);
    }

    // DELETE /settings/categories/{name}
    public function destroyCategory($name)
    {
        $deleted = DB::table('settings_categories')->where('name', $name)->delete();

        if (!$deleted) {
            return response()->json(['error' => 'Category not found'], 404);
        }

        return response()->json(['message' => 'Category deleted']);
    }
}
