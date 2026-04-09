<?php

use App\Http\Controllers\AdminMovieController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PublicMovieController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

// Public routes
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'Cinema API is running',
        'version' => '1.0.0'
    ]);
});

// Authentication routes (public)
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/movies', [PublicMovieController::class, 'index']);
Route::get('/movies/{movie:slug}', [PublicMovieController::class, 'show']);

// Protected routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/complete-profile', [\App\Http\Controllers\SocialAuthController::class, 'completeProfile']);
    
    // Example protected routes
    // Route::get('/movies', [MovieController::class, 'index']);
    // Route::get('/movies/{id}', [MovieController::class, 'show']);
});

// Admin routes
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/users', [AdminUserController::class, 'index']);
    Route::get('/users/options', [AdminUserController::class, 'options']);
    Route::post('/users', [AdminUserController::class, 'store']);

    Route::get('/movies', [AdminMovieController::class, 'index']);
    Route::get('/movies/options', [AdminMovieController::class, 'options']);
    Route::post('/movies', [AdminMovieController::class, 'store']);
    Route::get('/movies/{movie}', [AdminMovieController::class, 'show']);
    Route::post('/movies/{movie}/process', [AdminMovieController::class, 'queueProcessing']);
});
