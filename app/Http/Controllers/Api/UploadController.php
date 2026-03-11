<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class UploadController extends Controller
{
    public function store(Request $request)
    {
        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'No file provided'], 422);
        }

        $file = $request->file('file');
        $path = $file->store('uploads', 'public');

        return response()->json([
            'url'  => '/storage/' . $path,
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'type' => $file->getMimeType(),
        ]);
    }
}
