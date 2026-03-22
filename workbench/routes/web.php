<?php

use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\WorkbenchController;

Route::get('/', [WorkbenchController::class, 'workflowIndex']);
Route::get('/workflow/{name}', [WorkbenchController::class, 'workflowDetail']);
Route::get('/run/{id}', [WorkbenchController::class, 'runDetail']);

// Allow unauthenticated channel auth for the demo workbench
Route::post('/broadcasting/auth', fn () => response()->json(['auth' => '']))
    ->withoutMiddleware(['auth']);
