<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    public function index()
    {
        return response()->json([
            'categories' => DB::table('categories')->pluck('name'),
            'colors' => DB::table('color_palette')->orderBy('order')->pluck('hex_color'),
        ]);
    }

    public function categories()
    {
        return response()->json(DB::table('categories')->pluck('name'));
    }

    public function colors()
    {
        return response()->json(DB::table('color_palette')->orderBy('order')->pluck('hex_color'));
    }

    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:categories,name'
        ]);
        
        DB::table('categories')->insert([
            'name' => $validated['name'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Category created'], 201);
    }

    public function deleteCategory($name)
    {
        DB::table('categories')->where('name', $name)->delete();
        return response()->json(['message' => 'Category deleted']);
    }
}
