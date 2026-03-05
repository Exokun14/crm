<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class UploadController extends Controller
{
    public function store(Request $request)
    {
        // Validate: must be a file, max 10MB
        $request->validate([
            'file' => 'required|file|max:10240',
        ]);

        // Save the file to storage/app/public/uploads
        $path = $request->file('file')->store('uploads', 'public');

        // Return the public URL so React can use it
        return response()->json([
            'url'  => Storage::url($path),
            'path' => $path,
        ]);
    }
}
