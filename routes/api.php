<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UploadController;

// ── Existing route ────────────────────────────────────────
Route::get('/users', [UserController::class, 'index']);

// ── New upload route ──────────────────────────────────────
Route::post('/upload', [UploadController::class, 'store']);
