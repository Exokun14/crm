<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\AddPosController;
use App\Http\Controllers\Api\PosPeripheralController;
use App\Http\Controllers\Api\Add_User_Controller;
use App\Http\Controllers\Api\IndustryCardController;
use App\Http\Controllers\Api\BranchLimiterController;

// ── Learning Module ────────────────────────────────────────────────────────
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\CourseIconController;
use App\Http\Controllers\Api\AIChatController;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/* ── Authentication ───────────────────────────────────────────── */
Route::post('/auth/login', [AuthController::class, 'login']);

/* ── Companies ────────────────────────────────────────────────── */
Route::post('/companies',        [CompanyController::class, 'store']);
Route::get('/companies',         [CompanyController::class, 'index']);
Route::post('/companies/{id}',   [CompanyController::class, 'update']);
Route::put('/companies/{id}',    [CompanyController::class, 'update']);

/* ── Branches ─────────────────────────────────────────────────── */
Route::get('/branches',         [BranchController::class, 'index']);
Route::post('/branches',        [BranchController::class, 'store']);
Route::put('/branches/{id}',    [BranchController::class, 'update']);
Route::delete('/branches/{id}', [BranchController::class, 'destroy']);

/* ── POS Machines ─────────────────────────────────────────────── */
Route::get('/pos',         [AddPosController::class, 'index']);
Route::post('/pos',        [AddPosController::class, 'store']);
Route::put('/pos/{id}',    [AddPosController::class, 'update']);
Route::delete('/pos/{id}', [AddPosController::class, 'destroy']);

/* ── POS Peripherals ──────────────────────────────────────────── */
Route::get('/peripherals',                      [PosPeripheralController::class, 'index']);
Route::post('/peripherals',                     [PosPeripheralController::class, 'store']);
Route::put('/peripherals/{id}',                 [PosPeripheralController::class, 'update']);
Route::post('/peripherals/{id}',                [PosPeripheralController::class, 'update']);
Route::delete('/peripherals/{id}',              [PosPeripheralController::class, 'destroy']);
Route::delete('/peripherals/by-pos/{posId}',    [PosPeripheralController::class, 'destroyByPos']);

/* ── Users ────────────────────────────────────────────────────── */
Route::get('/users',         [Add_User_Controller::class, 'index']);
Route::post('/users',        [Add_User_Controller::class, 'store']);
Route::post('/users/{id}',   [Add_User_Controller::class, 'update']);
Route::put('/users/{id}',    [Add_User_Controller::class, 'update']);
Route::delete('/users/{id}', [Add_User_Controller::class, 'destroy']);

/* ── Industry Cards ───────────────────────────────────────────── */
Route::get('/industry-cards',         [IndustryCardController::class, 'index']);
Route::post('/industry-cards',        [IndustryCardController::class, 'store']);
Route::put('/industry-cards/{id}',    [IndustryCardController::class, 'update']);
Route::delete('/industry-cards/{id}', [IndustryCardController::class, 'destroy']);

/* ── Branch Limiter ───────────────────────────────────────────── */
Route::get('/branch-limiter',         [BranchLimiterController::class, 'index']);
Route::post('/branch-limiter',        [BranchLimiterController::class, 'store']);
Route::put('/branch-limiter/{id}',    [BranchLimiterController::class, 'update']);
Route::delete('/branch-limiter/{id}', [BranchLimiterController::class, 'destroy']);

/* ═══════════════════════════════════════════════════════════════
   LEARNING MODULE ROUTES
   ═══════════════════════════════════════════════════════════════ */

/* ── Courses ──────────────────────────────────────────────────── */
Route::get('/courses',                    [CourseController::class, 'index']);
Route::post('/courses',                   [CourseController::class, 'store']);
Route::get('/courses/{id}',               [CourseController::class, 'show']);
Route::put('/courses/{id}',               [CourseController::class, 'update']);
Route::delete('/courses/{id}',            [CourseController::class, 'destroy']);
Route::put('/courses/{id}/progress',      [CourseController::class, 'updateProgress']);
Route::put('/courses/{id}/modules',       [CourseController::class, 'updateModules']);

/* ── Chapters ─────────────────────────────────────────────────── */
Route::put('/chapters/{chapterId}/done',  [CourseController::class, 'markChapterDone']);

/* ── Activities ───────────────────────────────────────────────── */
Route::get('/activities',                 [ActivityController::class, 'index']);
Route::post('/activities',                [ActivityController::class, 'store']);
Route::get('/activities/{id}',            [ActivityController::class, 'show']);
Route::put('/activities/{id}',            [ActivityController::class, 'update']);
Route::delete('/activities/{id}',         [ActivityController::class, 'destroy']);
Route::put('/activities/{id}/status',     [ActivityController::class, 'updateStatus']);

/* ── Progress ─────────────────────────────────────────────────── */
Route::get('/progress',                   [ProgressController::class, 'index']);
Route::post('/progress',                  [ProgressController::class, 'store']);
Route::put('/progress/{id}',              [ProgressController::class, 'update']);
Route::get('/progress/chapters',          [ProgressController::class, 'chapterProgress']);
Route::get('/progress/modules',           [ProgressController::class, 'moduleProgress']);

/* ── Clients (maps to 'company' table) ────────────────────────── */
Route::get('/clients',                    [ClientController::class, 'index']);
Route::get('/clients/{id}',               [ClientController::class, 'show']);
Route::get('/clients/{id}/courses',       [ClientController::class, 'courses']);

/* ── Settings / Categories ────────────────────────────────────── */
Route::get('/settings',                                    [SettingsController::class, 'index']);
Route::get('/settings/categories',                         [SettingsController::class, 'getCategories']);
Route::get('/settings/colors',                             [SettingsController::class, 'getColors']);
Route::post('/settings/categories',                        [SettingsController::class, 'storeCategory']);
Route::delete('/settings/categories/{name}',               [SettingsController::class, 'destroyCategory']);

/* ── File Upload ──────────────────────────────────────────────── */
Route::post('/upload',                    [UploadController::class, 'store']);

/* ── Course Icons ─────────────────────────────────────────────── */
Route::get('/course-icons',               [CourseIconController::class, 'index']);
Route::post('/course-icons',              [CourseIconController::class, 'store']);
Route::delete('/course-icons/{id}',       [CourseIconController::class, 'destroy']);

/* ── AI Chat ──────────────────────────────────────────────────── */
Route::post('/ai/chat',         [AIChatController::class, 'chat']);
Route::get('/ai/chat/history',  [AIChatController::class, 'history']);
Route::post('/ai/chat/clear',   [AIChatController::class, 'clear']);
