<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\UserController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\RoomController;

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
Route::get('/settings', [UserController::class, 'settings']);
Route::post('/terms', [UserController::class, 'accept']);
Route::get('/accounts/{type}/{name}', [UserController::class, 'check']);
Route::post('/accounts', [UserController::class, 'register']);
Route::get('/games/{id}', [GameController::class, 'spectate']);
Route::middleware('auth:sanctum')->put('/accounts/{id}', [UserController::class, 'update']);
Route::middleware('auth:sanctum')->get('/accounts/{id}', [UserController::class, 'view']);
Route::middleware('auth:sanctum')->delete('/accounts', [UserController::class, 'logout']);
Route::middleware('auth:sanctum')->get('/games', [GameController::class, 'status']);
Route::middleware('auth:sanctum')->post('/games', [GameController::class, 'play']);
Route::middleware('auth:sanctum')->post('/games/{id}', [GameController::class, 'join']);
Route::middleware('auth:sanctum')->get('/rooms/{name}', [RoomController::class, 'check']);
Route::middleware('auth:sanctum')->post('/rooms', [GameController::class, 'create']);
Route::middleware('auth:sanctum')->put('/rooms/{id}', [RoomController::class, 'update']);
