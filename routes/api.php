<?php

use App\Http\Controllers\AdminContentRequestController;
use App\Http\Controllers\AdminTicketController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\AdminCollectionController;
use App\Http\Controllers\AdminMovieController;
use App\Http\Controllers\AdminReviewController;
use App\Http\Controllers\AdminPersonController;
use App\Http\Controllers\AdminSeriesController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FilterOptionsController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\PublicContentRequestController;
use App\Http\Controllers\PublicTicketController;
use App\Http\Controllers\PublicCollectionController;
use App\Http\Controllers\PublicMovieController;
use App\Http\Controllers\PublicReviewController;
use App\Http\Controllers\PublicSeriesController;
use App\Http\Controllers\TmdbController;
use App\Http\Controllers\TvActivationController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\PlaybackSessionController;
use App\Http\Controllers\WatchProgressController;
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
Route::get('/filter-options', FilterOptionsController::class);
Route::get('/search', SearchController::class);
Route::get('/movies', [PublicMovieController::class, 'index']);
Route::get('/movies/{movie:slug}', [PublicMovieController::class, 'show']);
Route::get('/movies/{movie:slug}/related', [PublicMovieController::class, 'related']);
Route::get('/movies/{movie:slug}/reviews', [PublicReviewController::class, 'movieReviews']);
Route::get('/series', [PublicSeriesController::class, 'index']);
Route::get('/series/{series:slug}', [PublicSeriesController::class, 'show']);
Route::get('/series/{series:slug}/related', [PublicSeriesController::class, 'related']);
Route::get('/series/{series:slug}/episodes', [PublicSeriesController::class, 'episodes']);
Route::get('/series/{series:slug}/reviews', [PublicReviewController::class, 'seriesReviews']);
Route::get('/collections', [PublicCollectionController::class, 'index']);
Route::get('/collections/{collection:slug}', [PublicCollectionController::class, 'show']);
Route::get('/persons', [PersonController::class, 'index']);
Route::get('/persons/{slug}', [PersonController::class, 'show']);
Route::get('/v1/playback/session', [PlaybackSessionController::class, 'show']);
Route::get('/v1/playback/verify-token', [PlaybackSessionController::class, 'verifyToken']);

Route::prefix('v1/tv/activate')->group(function () {
    Route::post('/request', [TvActivationController::class, 'request'])->middleware('throttle:10,1');
    Route::get('/poll', [TvActivationController::class, 'poll'])->middleware('throttle:60,1');
    Route::post('/confirm', [TvActivationController::class, 'confirm'])->middleware('auth:sanctum');
});

// Protected routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/complete-profile', [\App\Http\Controllers\SocialAuthController::class, 'completeProfile']);

    Route::middleware('throttle:reviews')->group(function () {
        Route::post('/reviews', [PublicReviewController::class, 'store']);
    });
    Route::put('/reviews/{review}', [PublicReviewController::class, 'update']);
    Route::delete('/reviews/{review}', [PublicReviewController::class, 'destroy']);

    Route::get('/requests', [PublicContentRequestController::class, 'index']);
    Route::middleware('throttle:reviews')->post('/requests', [PublicContentRequestController::class, 'store']);

    Route::get('/ticket-categories', [PublicTicketController::class, 'categories']);
    Route::get('/tickets', [PublicTicketController::class, 'index']);
    Route::get('/tickets/{supportTicket}', [PublicTicketController::class, 'show']);
    Route::match(['put', 'patch'], '/tickets/{supportTicket}/close', [PublicTicketController::class, 'close']);
    Route::middleware('throttle:reviews')->post('/tickets/{supportTicket}/messages', [PublicTicketController::class, 'storeMessage']);
    Route::middleware('throttle:reviews')->post('/tickets', [PublicTicketController::class, 'store']);
    Route::middleware('throttle:reviews')->post('/upload', [UploadController::class, 'store']);

    Route::prefix('v1')->group(function () {
        Route::get('/watch-progress', [WatchProgressController::class, 'index']);
        Route::post('/watch-progress', [WatchProgressController::class, 'store']);
        Route::post('/watch-progress/migrate', [WatchProgressController::class, 'migrate']);
        Route::get('/watch-progress/{mediaId}', [WatchProgressController::class, 'show']);
        Route::delete('/watch-progress/{mediaId}', [WatchProgressController::class, 'destroy']);
    });

    // Example protected routes
    // Route::get('/movies', [MovieController::class, 'index']);
    // Route::get('/movies/{id}', [MovieController::class, 'show']);
});

// Admin routes
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/dashboard/summary', [AdminDashboardController::class, 'summary']);

    Route::get('/users', [AdminUserController::class, 'index']);
    Route::get('/users/options', [AdminUserController::class, 'options']);
    Route::post('/users', [AdminUserController::class, 'store']);

    Route::get('/movies/check-slug', [AdminMovieController::class, 'checkSlug']);
    Route::get('/movies', [AdminMovieController::class, 'index']);
    Route::get('/movies/options', [AdminMovieController::class, 'options']);
    Route::post('/movies', [AdminMovieController::class, 'store']);
    Route::post('/movies/ingest-tmdb-images', [AdminMovieController::class, 'ingestTmdbImages']);
    Route::post('/movies/preview-hls-package', [AdminMovieController::class, 'previewHlsPackage']);
    Route::post('/movies/check-hls-exists', [AdminMovieController::class, 'checkHlsExists']);
    Route::get('/movies/{movie}', [AdminMovieController::class, 'show']);
    Route::get('/movies/{movie}/preview-token', [AdminMovieController::class, 'previewToken']);
    Route::get('/movies/{movie}/history', [AdminMovieController::class, 'history']);
    Route::post('/movies/{movie}/process', [AdminMovieController::class, 'queueProcessing']);
    Route::post('/movies/{movie}/stop-processing', [AdminMovieController::class, 'stopProcessing']);
    Route::match(['put', 'patch'], '/movies/{movie}/draft-form', [AdminMovieController::class, 'updateDraftForm']);
    Route::match(['put', 'patch'], '/movies/{movie}/publication-form', [AdminMovieController::class, 'updatePublicationForm']);
    Route::get('/movies/{movie}/pipeline', [AdminMovieController::class, 'getPipeline']);
    Route::match(['put', 'patch'], '/movies/{movie}/publish-status', [AdminMovieController::class, 'updatePublishStatus']);
    Route::delete('/movies/{movie}', [AdminMovieController::class, 'destroy']);

    Route::get('/series/check-slug', [AdminSeriesController::class, 'checkSlug']);
    Route::get('/series', [AdminSeriesController::class, 'index']);
    Route::get('/series/options', [AdminSeriesController::class, 'options']);
    Route::post('/series', [AdminSeriesController::class, 'store']);
    Route::post('/series/ingest-tmdb-images', [AdminSeriesController::class, 'ingestTmdbImages']);
    Route::post('/series/preview-hls-package', [AdminSeriesController::class, 'previewHlsPackage']);
    Route::get('/series/{series}', [AdminSeriesController::class, 'show']);
    Route::get('/series/{series}/preview-token', [AdminSeriesController::class, 'previewToken']);
    Route::get('/series/{series}/history', [AdminSeriesController::class, 'history']);
    Route::match(['put', 'patch'], '/series/{series}', [AdminSeriesController::class, 'update']);
    Route::match(['put', 'patch'], '/series/{series}/seasons/{seasonNumber}/episodes/{episodeNumber}', [AdminSeriesController::class, 'upsertEpisode']);
    Route::get('/series/{series}/pipeline', [AdminSeriesController::class, 'getPipeline']);
    Route::match(['put', 'patch'], '/series/{series}/publish-status', [AdminSeriesController::class, 'updatePublishStatus']);
    Route::delete('/series/{series}', [AdminSeriesController::class, 'destroy']);

    Route::get('/collections', [AdminCollectionController::class, 'index']);
    Route::post('/collections', [AdminCollectionController::class, 'store']);
    Route::post('/collections/ingest-tmdb-images', [AdminCollectionController::class, 'ingestTmdbImages']);
    Route::get('/collections/{collection}', [AdminCollectionController::class, 'show']);
    Route::match(['put', 'patch'], '/collections/{collection}', [AdminCollectionController::class, 'update']);
    Route::match(['put', 'patch'], '/collections/{collection}/movies', [AdminCollectionController::class, 'syncMovies']);
    Route::delete('/collections/{collection}', [AdminCollectionController::class, 'destroy']);

    Route::get('/tmdb/search', [TmdbController::class, 'search']);
    Route::get('/tmdb/fetch', [TmdbController::class, 'fetch']);
    Route::get('/tmdb/collection/fetch', [TmdbController::class, 'fetchCollection']);
    Route::get('/tmdb/tv/fetch', [TmdbController::class, 'fetchTv']);
    Route::get('/tmdb/tv/season/fetch', [TmdbController::class, 'fetchTvSeason']);
    Route::get('/tmdb/person/fetch', [TmdbController::class, 'fetchPerson']);

    Route::get('/persons', [AdminPersonController::class, 'index']);
    Route::get('/persons/{person}', [AdminPersonController::class, 'show']);
    Route::put('/persons/{person}/refresh-from-tmdb', [AdminPersonController::class, 'refreshFromTmdb']);

    Route::get('/reviews', [AdminReviewController::class, 'index']);
    Route::match(['put', 'patch'], '/reviews/{review}', [AdminReviewController::class, 'update']);
    Route::put('/reviews/{review}/approve', [AdminReviewController::class, 'approve']);
    Route::put('/reviews/{review}/reject', [AdminReviewController::class, 'reject']);
    Route::delete('/reviews/{review}', [AdminReviewController::class, 'destroy']);

    Route::get('/requests', [AdminContentRequestController::class, 'index']);
    Route::match(['put', 'patch'], '/requests/{contentRequest}', [AdminContentRequestController::class, 'update']);

    Route::get('/tickets', [AdminTicketController::class, 'index']);
    Route::get('/tickets/{supportTicket}', [AdminTicketController::class, 'show']);
    Route::match(['put', 'patch'], '/tickets/{supportTicket}', [AdminTicketController::class, 'update']);

});
