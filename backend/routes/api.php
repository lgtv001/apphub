<?php
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProyectoController;
use App\Http\Controllers\AreaController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',     [AuthController::class, 'me']);

    Route::get('/proyectos',      [ProyectoController::class, 'index']);
    Route::get('/proyectos/{id}', [ProyectoController::class, 'show']);
    Route::post('/proyectos',     [ProyectoController::class, 'store'])->middleware('check.role:superuser');
    Route::put('/proyectos/{id}', [ProyectoController::class, 'update'])->middleware('check.role:superuser');

    Route::prefix('proyectos/{proyecto_id}')->middleware('check.project')->group(function () {
        Route::get('/areas',         [AreaController::class, 'index']);
        Route::post('/areas',        [AreaController::class, 'store'])->middleware('check.role:admin');
        Route::put('/areas/{id}',    [AreaController::class, 'update'])->middleware('check.role:admin');
        Route::delete('/areas/{id}', [AreaController::class, 'destroy'])->middleware('check.role:admin');
    });

});
