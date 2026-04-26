<?php
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProyectoController;
use App\Http\Controllers\AreaController;
use App\Http\Controllers\SubareaController;
use App\Http\Controllers\SistemaController;
use App\Http\Controllers\SubsistemaController;
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
        Route::get('/areas',              [AreaController::class, 'index']);
        Route::post('/areas',             [AreaController::class, 'store'])->middleware('check.role:admin');
        Route::put('/areas/{id}',         [AreaController::class, 'update'])->middleware('check.role:admin');
        Route::delete('/areas/{id}',      [AreaController::class, 'destroy'])->middleware('check.role:admin');

        Route::get('/subareas',           [SubareaController::class, 'index']);
        Route::post('/subareas',          [SubareaController::class, 'store'])->middleware('check.role:admin');
        Route::put('/subareas/{id}',      [SubareaController::class, 'update'])->middleware('check.role:admin');
        Route::delete('/subareas/{id}',   [SubareaController::class, 'destroy'])->middleware('check.role:admin');

        Route::get('/sistemas',           [SistemaController::class, 'index']);
        Route::post('/sistemas',          [SistemaController::class, 'store'])->middleware('check.role:admin');
        Route::put('/sistemas/{id}',      [SistemaController::class, 'update'])->middleware('check.role:admin');
        Route::delete('/sistemas/{id}',   [SistemaController::class, 'destroy'])->middleware('check.role:admin');

        Route::get('/subsistemas',        [SubsistemaController::class, 'index']);
        Route::post('/subsistemas',       [SubsistemaController::class, 'store'])->middleware('check.role:admin');
        Route::put('/subsistemas/{id}',   [SubsistemaController::class, 'update'])->middleware('check.role:admin');
        Route::delete('/subsistemas/{id}',[SubsistemaController::class, 'destroy'])->middleware('check.role:admin');
    });

});
