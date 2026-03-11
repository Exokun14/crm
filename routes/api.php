<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    $user = $request->user();

    return response()->json([
        'id'       => $user->id,
        'name'     => $user->name,
        'email'    => $user->email,
        'role'     => $user->role,       // 'admin' | 'user'
        'industry' => $user->industry,   // 'fnb' | 'retail' | 'warehouse' | null
    ]);
});
