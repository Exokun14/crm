<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\FileUploadController;

/*
|--------------------------------------------------------------------------
| API Routes for LMS
| Place this in: routes/api.php
|--------------------------------------------------------------------------
*/

// ── Courses ──────────────────────────────────────────────────────────────────
Route::prefix('courses')->group(function () {
    Route::get('/',              [CourseController::class, 'index']);
    Route::post('/',             [CourseController::class, 'store']);
    Route::get('/{id}',          [CourseController::class, 'show']);
    Route::put('/{id}',          [CourseController::class, 'update']);
    Route::delete('/{id}',       [CourseController::class, 'destroy']);
    Route::put('/{id}/progress', [CourseController::class, 'updateProgress']);
    Route::put('/{id}/modules',  [CourseController::class, 'updateModules']);
    Route::post('/{id}/clone',   [CourseController::class, 'clone']);   // ← NEW
});

// ── Clients ──────────────────────────────────────────────────────────────────
Route::prefix('clients')->group(function () {
    Route::get('/',              [ClientController::class, 'index']);
    Route::get('/{id}/courses',  [ClientController::class, 'courses']);
});

// ── Activities ───────────────────────────────────────────────────────────────
Route::prefix('activities')->group(function () {
    Route::get('/',                    [ActivityController::class, 'index']);
    Route::post('/',                   [ActivityController::class, 'store']);
    Route::get('/{activityId}',        [ActivityController::class, 'show']);
    Route::put('/{activityId}',        [ActivityController::class, 'update']);
    Route::delete('/{activityId}',     [ActivityController::class, 'destroy']);
});

// ── User Progress ────────────────────────────────────────────────────────────
Route::prefix('progress')->group(function () {
    Route::get('/',       [ProgressController::class, 'index']);
    Route::post('/',      [ProgressController::class, 'store']);
    Route::put('/{id}',   [ProgressController::class, 'update']);
});

// ── Settings ─────────────────────────────────────────────────────────────────
Route::prefix('settings')->group(function () {
    Route::get('/',                      [SettingsController::class, 'index']);
    Route::get('/categories',            [SettingsController::class, 'categories']);
    Route::get('/colors',                [SettingsController::class, 'colors']);
    Route::post('/categories',           [SettingsController::class, 'storeCategory']);
    Route::delete('/categories/{name}',  [SettingsController::class, 'deleteCategory']);
});

// ── File Upload ──────────────────────────────────────────────────────────────
Route::post('/upload', [FileUploadController::class, 'upload']);

// ── Chapters ─────────────────────────────────────────────────────────────────
Route::put('/chapters/{chapterId}/done', [CourseController::class, 'markChapterDone']);
