<?php

namespace App\Providers;

use App\Models\Movie;
use App\Models\Review;
use App\Models\Series;
use App\Models\SeriesEpisode;
use App\Observers\MovieObserver;
use App\Observers\ReviewObserver;
use App\Observers\SeriesEpisodeObserver;
use App\Observers\SeriesObserver;
use App\Services\Cinema\ImagePresentationService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per request so ImageAsset/disk lookups are memoized across cast/crew.
        $this->app->singleton(ImagePresentationService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        date_default_timezone_set((string) config('app.timezone', 'UTC'));

        // Fix for MySQL utf8mb4 key length issue
        Schema::defaultStringLength(191);

        RateLimiter::for('graphql', function (Request $request) {
            return Limit::perMinute(100)->by(
                $request->user()?->id ?? $request->ip()
            );
        });

        RateLimiter::for('reviews', function (Request $request) {
            return Limit::perMinute(10)->by(
                $request->user()?->id ?? $request->ip()
            );
        });

        RateLimiter::for('content-views', function (Request $request) {
            return Limit::perMinute(30)->by(
                $request->user()?->id ?? $request->ip()
            );
        });

        Review::observe(ReviewObserver::class);
        Movie::observe(MovieObserver::class);
        Series::observe(SeriesObserver::class);
        SeriesEpisode::observe(SeriesEpisodeObserver::class);
    }
}
