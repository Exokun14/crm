<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UploadController extends Controller
{
    public function store(Request $request)
    {
        Log::channel('stderr')->info('[UploadController@store] ▶ Request received');

        if (!$request->hasFile('file')) {
            Log::channel('stderr')->error('[UploadController@store] ❌ No file in request');
            return response()->json(['error' => 'No file provided'], 422);
        }

        $request->validate([
            'file' => 'required|file|mimes:jpeg,jpg,png,webp,gif,svg|max:5120', // 5MB max
        ]);

        $file = $request->file('file');
        $path = $file->store('uploads', 'public');

        // Use url() so it returns a full absolute URL that works in the browser
        $url = url('storage/' . $path);

        Log::channel('stderr')->info('[UploadController@store] ✅ Uploaded: ' . $url);

        return response()->json([
            'url'  => $url,
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'type' => $file->getMimeType(),
        ]);
    }
}
