<?php

use App\Http\Controllers\AdminContentRequestController;
use App\Http\Controllers\AdminTicketController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\AdminCollectionController;
use App\Http\Controllers\AdminMovieController;
use App\Http\Controllers\AdminReviewController;
use App\Http\Controllers\AdminPersonController;
use App\Http\Controllers\AdminSeriesController;
use App\Http\Controllers\AdminUploadController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AdminViewerController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContentViewController;
use App\Http\Controllers\FilterOptionsController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\PublicHomeController;
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
// Viewer auth — self-registration and public login
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/login', [AuthController::class, 'login']);
// Staff/admin auth — accounts are created only by admins
Route::post('/staff/login', [AuthController::class, 'staffLogin']);

// Public catalog/search (basic bot & burst protection)
Route::middleware('throttle:90,1')->group(function () {
    Route::get('/filter-options', FilterOptionsController::class);
    Route::get('/home/critical', [PublicHomeController::class, 'critical']);
    Route::get('/home/latest-episodes', [PublicHomeController::class, 'latestEpisodes']);
    Route::get('/search', SearchController::class);

    Route::get('/movies', [PublicMovieController::class, 'index']);
    Route::get('/movies/{movie:slug}', [PublicMovieController::class, 'show']);
    Route::get('/movies/{movie:slug}/playback', [PublicMovieController::class, 'playback']);
    Route::get('/movies/{movie:slug}/related', [PublicMovieController::class, 'related']);
    Route::get('/movies/{movie:slug}/reviews', [PublicReviewController::class, 'movieReviews']);

    Route::get('/series', [PublicSeriesController::class, 'index']);
    Route::get('/series/{series:slug}', [PublicSeriesController::class, 'show']);
    Route::get('/series/{series:slug}/playback', [PublicSeriesController::class, 'playback']);
    Route::get('/series/{series:slug}/related', [PublicSeriesController::class, 'related']);
    Route::get('/series/{series:slug}/episodes', [PublicSeriesController::class, 'episodes']);
    Route::get('/series/{series:slug}/reviews', [PublicReviewController::class, 'seriesReviews']);

    Route::get('/collections', [PublicCollectionController::class, 'index']);
    Route::get('/collections/{collection:slug}', [PublicCollectionController::class, 'show']);

    Route::get('/persons', [PersonController::class, 'index']);
    Route::get('/persons/{slug}', [PersonController::class, 'show']);
});

// Player/session endpoints
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/v1/playback/session', [PlaybackSessionController::class, 'show']);
    Route::get('/v1/playback/verify-token', [PlaybackSessionController::class, 'verifyToken']);
});
Route::post('/v1/content-views', [ContentViewController::class, 'store'])
    ->middleware('throttle:content-views');

Route::prefix('v1/tv/activate')->group(function () {
    Route::post('/request', [TvActivationController::class, 'request'])->middleware('throttle:10,1');
    Route::get('/poll', [TvActivationController::class, 'poll'])->middleware('throttle:60,1');
    Route::post('/confirm', [TvActivationController::class, 'confirm'])->middleware(['auth:sanctum', 'viewer']);
});

// Shared auth routes (viewer or staff token)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
});

// Viewer-only audience features
Route::middleware(['auth:sanctum', 'viewer'])->group(function () {
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
});

// Admin routes
Route::middleware(['auth:sanctum', 'staff', 'role:admin,media,support'])->prefix('admin')->group(function () {
    Route::middleware('permission:view_dashboard')->get('/dashboard/summary', [AdminDashboardController::class, 'summary']);

    Route::middleware('permission:view_users,create_users')->get('/users', [AdminUserController::class, 'index']);
    Route::middleware('permission:create_users')->get('/users/options', [AdminUserController::class, 'options']);
    Route::middleware('permission:create_users')->post('/users', [AdminUserController::class, 'store']);

    Route::middleware('permission:view_viewers,create_viewers,manage_viewers')->get('/viewers', [AdminViewerController::class, 'index']);
    Route::middleware('permission:create_viewers')->post('/viewers', [AdminViewerController::class, 'store']);
    Route::middleware('permission:manage_viewers')->match(['put', 'patch'], '/viewers/{viewer}/status', [AdminViewerController::class, 'updateStatus']);

    Route::middleware('permission:edit_movies,create_movies,edit_series,create_series')->post('/upload/image', [AdminUploadController::class, 'storeImage']);

    Route::middleware('permission:view_movies,create_movies,edit_movies')->get('/movies/check-slug', [AdminMovieController::class, 'checkSlug']);
    Route::middleware('permission:view_movies,create_movies,edit_movies')->get('/movies/check-tmdb', [AdminMovieController::class, 'checkTmdbId']);
    Route::middleware('permission:view_movies')->get('/movies', [AdminMovieController::class, 'index']);
    Route::middleware('permission:view_movies')->get('/movies/options', [AdminMovieController::class, 'options']);
    Route::middleware('permission:create_movies')->post('/movies', [AdminMovieController::class, 'store']);
    Route::middleware('permission:edit_movies,create_movies')->post('/movies/ingest-tmdb-images', [AdminMovieController::class, 'ingestTmdbImages']);
    Route::middleware('permission:edit_movies')->post('/movies/preview-hls-package', [AdminMovieController::class, 'previewHlsPackage']);
    Route::middleware('permission:edit_movies')->post('/movies/check-hls-exists', [AdminMovieController::class, 'checkHlsExists']);
    Route::middleware('permission:view_movies,edit_movies')->get('/movies/{movie}', [AdminMovieController::class, 'show']);
    Route::middleware('permission:edit_movies')->get('/movies/{movie}/preview-token', [AdminMovieController::class, 'previewToken']);
    Route::middleware('permission:edit_movies')->get('/movies/{movie}/history', [AdminMovieController::class, 'history']);
    Route::middleware('permission:edit_movies')->post('/movies/{movie}/process', [AdminMovieController::class, 'queueProcessing']);
    Route::middleware('permission:edit_movies')->post('/movies/{movie}/stop-processing', [AdminMovieController::class, 'stopProcessing']);
    Route::middleware('permission:edit_movies')->match(['put', 'patch'], '/movies/{movie}/draft-form', [AdminMovieController::class, 'updateDraftForm']);
    Route::middleware('permission:edit_movies')->match(['put', 'patch'], '/movies/{movie}/publication-form', [AdminMovieController::class, 'updatePublicationForm']);
    Route::middleware('permission:edit_movies')->get('/movies/{movie}/pipeline', [AdminMovieController::class, 'getPipeline']);
    Route::middleware('permission:edit_movies')->match(['put', 'patch'], '/movies/{movie}/publish-status', [AdminMovieController::class, 'updatePublishStatus']);
    Route::middleware('permission:delete_movies')->delete('/movies/{movie}', [AdminMovieController::class, 'destroy']);

    Route::middleware('permission:view_series,create_series,edit_series')->get('/series/check-slug', [AdminSeriesController::class, 'checkSlug']);
    Route::middleware('permission:view_series,create_series,edit_series')->get('/series/check-tmdb', [AdminSeriesController::class, 'checkTmdbId']);
    Route::middleware('permission:view_series')->get('/series', [AdminSeriesController::class, 'index']);
    Route::middleware('permission:view_series')->get('/series/options', [AdminSeriesController::class, 'options']);
    Route::middleware('permission:create_series')->post('/series', [AdminSeriesController::class, 'store']);
    Route::middleware('permission:edit_series,create_series')->post('/series/ingest-tmdb-images', [AdminSeriesController::class, 'ingestTmdbImages']);
    Route::middleware('permission:edit_series')->post('/series/preview-hls-package', [AdminSeriesController::class, 'previewHlsPackage']);
    Route::middleware('permission:view_series,edit_series')->get('/series/{series}', [AdminSeriesController::class, 'show']);
    Route::middleware('permission:edit_series')->get('/series/{series}/preview-token', [AdminSeriesController::class, 'previewToken']);
    Route::middleware('permission:edit_series')->get('/series/{series}/history', [AdminSeriesController::class, 'history']);
    Route::middleware('permission:edit_series')->match(['put', 'patch'], '/series/{series}', [AdminSeriesController::class, 'update']);
    Route::middleware('permission:edit_series')->match(['put', 'patch'], '/series/{series}/seasons/{seasonNumber}/episodes/{episodeNumber}', [AdminSeriesController::class, 'upsertEpisode']);
    Route::middleware('permission:edit_series')->get('/series/{series}/pipeline', [AdminSeriesController::class, 'getPipeline']);
    Route::middleware('permission:edit_series')->match(['put', 'patch'], '/series/{series}/publish-status', [AdminSeriesController::class, 'updatePublishStatus']);
    Route::middleware('permission:delete_series')->delete('/series/{series}', [AdminSeriesController::class, 'destroy']);

    // Collections are read-only in admin for now.
    Route::middleware('permission:view_collections')->get('/collections', [AdminCollectionController::class, 'index']);
    Route::middleware('permission:view_collections')->get('/collections/{collection}', [AdminCollectionController::class, 'show']);

    Route::middleware('permission:edit_movies,create_movies,edit_series,create_series')->get('/tmdb/search', [TmdbController::class, 'search']);
    Route::middleware('permission:edit_movies,create_movies')->get('/tmdb/fetch', [TmdbController::class, 'fetch']);
    Route::middleware('permission:view_collections')->get('/tmdb/collection/fetch', [TmdbController::class, 'fetchCollection']);
    Route::middleware('permission:edit_series,create_series')->get('/tmdb/tv/fetch', [TmdbController::class, 'fetchTv']);
    Route::middleware('permission:edit_series,create_series')->get('/tmdb/tv/season/fetch', [TmdbController::class, 'fetchTvSeason']);
    Route::middleware('permission:edit_movies,create_movies,edit_series,create_series')->get('/tmdb/person/fetch', [TmdbController::class, 'fetchPerson']);

    Route::middleware('permission:edit_movies,edit_series')->get('/persons', [AdminPersonController::class, 'index']);
    Route::middleware('permission:edit_movies,edit_series')->get('/persons/{person}', [AdminPersonController::class, 'show']);
    Route::middleware('permission:edit_movies,edit_series')->put('/persons/{person}/refresh-from-tmdb', [AdminPersonController::class, 'refreshFromTmdb']);

    Route::middleware('permission:view_comments,moderate_comments,delete_comments')->get('/reviews', [AdminReviewController::class, 'index']);
    Route::middleware('permission:moderate_comments')->match(['put', 'patch'], '/reviews/{review}', [AdminReviewController::class, 'update']);
    Route::middleware('permission:moderate_comments')->put('/reviews/{review}/approve', [AdminReviewController::class, 'approve']);
    Route::middleware('permission:moderate_comments')->put('/reviews/{review}/reject', [AdminReviewController::class, 'reject']);
    Route::middleware('permission:delete_comments')->delete('/reviews/{review}', [AdminReviewController::class, 'destroy']);

    Route::middleware('permission:view_requests,manage_requests')->get('/requests', [AdminContentRequestController::class, 'index']);
    Route::middleware('permission:manage_requests')->match(['put', 'patch'], '/requests/{contentRequest}', [AdminContentRequestController::class, 'update']);

    Route::middleware('permission:view_tickets,reply_tickets,manage_tickets')->get('/tickets', [AdminTicketController::class, 'index']);
    Route::middleware('permission:view_tickets,reply_tickets,manage_tickets')->get('/tickets/{supportTicket}', [AdminTicketController::class, 'show']);
    Route::middleware('permission:reply_tickets,manage_tickets')->match(['put', 'patch'], '/tickets/{supportTicket}', [AdminTicketController::class, 'update']);

});
