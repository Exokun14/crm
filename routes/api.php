<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\AddPosController;
use App\Http\Controllers\Api\Add_User_Controller;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/companies',      [CompanyController::class, 'store']);
Route::get('/companies',       [CompanyController::class, 'index']);
Route::get('/companies/{id}',  [CompanyController::class, 'show']);   // ← added: getById

Route::get('/branches',         [BranchController::class, 'index']);
Route::post('/branches',        [BranchController::class, 'store']);
Route::put('/branches/{id}',    [BranchController::class, 'update']);   // ← added
Route::delete('/branches/{id}', [BranchController::class, 'destroy']);  // ← added

Route::get('/pos',         [AddPosController::class, 'index']);
Route::post('/pos',        [AddPosController::class, 'store']);
Route::put('/pos/{id}',    [AddPosController::class, 'update']);
Route::delete('/pos/{id}', [AddPosController::class, 'destroy']);

// Alias: frontend calls /pos-devices, backend uses /pos controller
Route::get('/pos-devices', [AddPosController::class, 'index']);   // ← added

// ── Routes missing from original ──────────────────────────────────────────
use App\Http\Controllers\Api\LicenseController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\TicketController;

Route::get('/licenses',                    [LicenseController::class,      'index']);
Route::get('/notifications',               [NotificationController::class,  'index']);
Route::put('/notifications/{id}/read',     [NotificationController::class,  'markRead']);
Route::put('/notifications/read-all',      [NotificationController::class,  'markAllRead']);
Route::get('/tickets',                     [TicketController::class,        'index']);


Route::get('/users',         [Add_User_Controller::class, 'index']);
Route::post('/users',        [Add_User_Controller::class, 'store']);
Route::post('/users/{id}',   [Add_User_Controller::class, 'update']);  // POST + _method=PUT (FormData)
Route::put('/users/{id}',    [Add_User_Controller::class, 'update']);  // native PUT (JSON)