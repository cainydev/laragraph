<?php

use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\WorkbenchController;

Route::post('/workflows/{name}/run', [WorkbenchController::class, 'run']);
Route::get('/runs/{id}', [WorkbenchController::class, 'runStatus']);
Route::post('/runs/{id}/pause', [WorkbenchController::class, 'pauseRun']);
Route::post('/runs/{id}/abort', [WorkbenchController::class, 'abortRun']);
Route::post('/runs/{id}/resume', [WorkbenchController::class, 'resumeRun']);
