<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\UserController;
use App\Http\Controllers\GameController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/accounts', [UserController::class, 'register']);
Route::middleware('auth:sanctum')->put('/accounts/{id}', [UserController::class, 'update']);
Route::middleware('auth:sanctum')->get('/accounts/{id}', [UserController::class, 'view']);
Route::middleware('auth:sanctum')->get('/games', [GameController::class, 'play']);
Route::middleware('auth:sanctum')->post('/games', [GameController::class, 'join']);