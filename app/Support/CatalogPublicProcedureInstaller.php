<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Installs / refreshes public catalog stored procedures with ranking-aware sort branches.
 */
class CatalogPublicProcedureInstaller
{
    public static function install(): void
    {
        $minVotes = max(1, (int) config('cinema.catalog.top_rated_min_vote_count', 30));
        $staleHours = max(1, (int) config('cinema.catalog.trending_stale_hours', 48));

        $hasMoviePopularity = Schema::hasColumn('movies', 'filter_popularity');
        $hasMovieVoteCount = Schema::hasColumn('movies', 'vote_count');
        $hasMovieTrending = Schema::hasColumn('movies', 'trending_rank');
        $hasMovieHybrid = Schema::hasColumn('movies', 'popular_hybrid_score');
        $hasMovieInternal = Schema::hasColumn('movies', 'internal_popularity_30d');

        $hasSeriesPopularity = Schema::hasColumn('series', 'filter_popularity');
        $hasSeriesVoteCount = Schema::hasColumn('series', 'vote_count');
        $hasSeriesTrending = Schema::hasColumn('series', 'trending_rank');
        $hasSeriesHybrid = Schema::hasColumn('series', 'popular_hybrid_score');

        $moviePopularOrder = $hasMoviePopularity
            ? 'CASE WHEN p_sort = \'popular\' THEN m.filter_popularity END DESC,'
            : 'CASE WHEN p_sort = \'popular\' THEN m.published_at END DESC,';

        $movieHybridOrder = $hasMovieHybrid
            ? 'CASE WHEN p_sort = \'popular_hybrid\' THEN m.popular_hybrid_score END DESC,'
            : ($hasMoviePopularity
                ? 'CASE WHEN p_sort = \'popular_hybrid\' THEN m.filter_popularity END DESC,'
                : '');

        $movieTrendingFresh = $hasMovieTrending
            ? "CASE WHEN p_sort = 'trending' AND m.trending_rank IS NOT NULL AND m.trending_synced_at >= DATE_SUB(NOW(), INTERVAL {$staleHours} HOUR) THEN 0 ELSE 1 END ASC,
                    CASE WHEN p_sort = 'trending' AND m.trending_rank IS NOT NULL AND m.trending_synced_at >= DATE_SUB(NOW(), INTERVAL {$staleHours} HOUR) THEN m.trending_rank END ASC,"
            : '';

        $movieTrendingFallback = $hasMoviePopularity
            ? "CASE WHEN p_sort = 'trending_fallback' THEN (COALESCE(m.filter_popularity, 0) * GREATEST(0.3, 1.0 - (GREATEST(0, YEAR(NOW()) - COALESCE(m.release_year, YEAR(NOW()))) * 0.12))) END DESC,"
            : '';

        $movieTopRatedGuard = $hasMovieVoteCount
            ? "CASE WHEN p_sort IN ('top_rated', 'imdb_desc') AND COALESCE(m.vote_count, 0) >= {$minVotes} THEN 0 ELSE 1 END ASC,"
            : '';

        $seriesPopularOrder = $hasSeriesPopularity
            ? 'CASE WHEN p_sort = \'popular\' THEN s.filter_popularity END DESC,'
            : 'CASE WHEN p_sort = \'popular\' THEN s.published_at END DESC,';

        $seriesHybridOrder = $hasSeriesHybrid
            ? 'CASE WHEN p_sort = \'popular_hybrid\' THEN s.popular_hybrid_score END DESC,'
            : ($hasSeriesPopularity
                ? 'CASE WHEN p_sort = \'popular_hybrid\' THEN s.filter_popularity END DESC,'
                : '');

        $seriesTrendingFresh = $hasSeriesTrending
            ? "CASE WHEN p_sort = 'trending' AND s.trending_rank IS NOT NULL AND s.trending_synced_at >= DATE_SUB(NOW(), INTERVAL {$staleHours} HOUR) THEN 0 ELSE 1 END ASC,
                    CASE WHEN p_sort = 'trending' AND s.trending_rank IS NOT NULL AND s.trending_synced_at >= DATE_SUB(NOW(), INTERVAL {$staleHours} HOUR) THEN s.trending_rank END ASC,"
            : '';

        $seriesTrendingFallback = $hasSeriesPopularity
            ? "CASE WHEN p_sort = 'trending_fallback' THEN (COALESCE(s.filter_popularity, 0) * GREATEST(0.3, 1.0 - (GREATEST(0, YEAR(NOW()) - COALESCE(s.release_year, YEAR(NOW()))) * 0.12))) END DESC,"
            : '';

        $seriesTopRatedGuard = $hasSeriesVoteCount
            ? "CASE WHEN p_sort IN ('top_rated', 'imdb_desc') AND COALESCE(s.vote_count, 0) >= {$minVotes} THEN 0 ELSE 1 END ASC,"
            : '';

        DB::unprepared('DROP PROCEDURE IF EXISTS sp_GetPublicMoviesCatalog');
        DB::unprepared("
            CREATE PROCEDURE sp_GetPublicMoviesCatalog(
                IN p_search VARCHAR(255),
                IN p_genre_ids TEXT,
                IN p_countries TEXT,
                IN p_languages TEXT,
                IN p_year_from SMALLINT,
                IN p_year_to SMALLINT,
                IN p_min_rating DECIMAL(4,2),
                IN p_sort VARCHAR(50),
                IN p_page INT,
                IN p_per_page INT
            )
            BEGIN
                DECLARE v_offset INT DEFAULT 0;
                SET v_offset = GREATEST(p_page - 1, 0) * p_per_page;

                SELECT
                    m.id,
                    m.title,
                    m.slug,
                    m.synopsis,
                    m.release_year,
                    m.poster_path,
                    m.has_subtitle,
                    m.has_dubbed_audio,
                    m.filter_rating,
                    m.origin_country,
                    m.original_language,
                    m.published_at,
                    m.metadata
                FROM movies m
                WHERE m.publish_status = 'published'
                    AND (
                        IFNULL(TRIM(p_search), '') = ''
                        OR (
                            m.title COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', p_search COLLATE utf8mb4_unicode_ci, '%')
                            OR m.original_title COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', p_search COLLATE utf8mb4_unicode_ci, '%')
                            OR m.synopsis COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', p_search COLLATE utf8mb4_unicode_ci, '%')
                        )
                    )
                    AND (p_year_from IS NULL OR p_year_from <= 0 OR m.release_year >= p_year_from)
                    AND (p_year_to IS NULL OR p_year_to <= 0 OR m.release_year <= p_year_to)
                    AND (p_min_rating IS NULL OR p_min_rating <= 0 OR m.filter_rating >= p_min_rating OR JSON_EXTRACT(m.metadata, '$.tmdb.rating') >= p_min_rating)
                    AND (p_countries IS NULL OR p_countries = '' OR FIND_IN_SET(CAST(m.origin_country AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci, p_countries COLLATE utf8mb4_unicode_ci) > 0)
                    AND (p_languages IS NULL OR p_languages = '' OR FIND_IN_SET(CAST(m.original_language AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci, p_languages COLLATE utf8mb4_unicode_ci) > 0)
                    AND (
                        p_genre_ids IS NULL OR p_genre_ids = ''
                        OR EXISTS (
                            SELECT 1 FROM movie_genre mg
                            WHERE mg.movie_id = m.id AND FIND_IN_SET(CAST(mg.genre_id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci, p_genre_ids COLLATE utf8mb4_unicode_ci) > 0
                        )
                    )
                ORDER BY
                    {$movieTrendingFresh}
                    {$movieTrendingFallback}
                    {$movieHybridOrder}
                    {$moviePopularOrder}
                    {$movieTopRatedGuard}
                    CASE WHEN p_sort IN ('top_rated', 'imdb_desc') THEN m.filter_rating END DESC,
                    CASE WHEN p_sort IN ('release_year_desc', 'year_desc') THEN m.release_year END DESC,
                    CASE WHEN p_sort IN ('release_year_asc', 'year_asc') THEN m.release_year END ASC,
                    CASE WHEN p_sort = 'oldest' THEN m.published_at END ASC,
                    CASE WHEN p_sort = 'title_asc' THEN m.title END ASC,
                    CASE WHEN p_sort = 'title_desc' THEN m.title END DESC,
                    CASE WHEN p_sort = 'newest' THEN m.published_at END DESC,
                    m.published_at DESC,
                    m.id DESC
                LIMIT p_per_page OFFSET v_offset;

                SELECT COUNT(*) AS total
                FROM movies m
                WHERE m.publish_status = 'published'
                    AND (
                        IFNULL(TRIM(p_search), '') = ''
                        OR (
                            m.title COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', p_search COLLATE utf8mb4_unicode_ci, '%')
                            OR m.original_title COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', p_search COLLATE utf8mb4_unicode_ci, '%')
                            OR m.synopsis COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', p_search COLLATE utf8mb4_unicode_ci, '%')
                        )
                    )
                    AND (p_year_from IS NULL OR p_year_from <= 0 OR m.release_year >= p_year_from)
                    AND (p_year_to IS NULL OR p_year_to <= 0 OR m.release_year <= p_year_to)
                    AND (p_min_rating IS NULL OR p_min_rating <= 0 OR m.filter_rating >= p_min_rating OR JSON_EXTRACT(m.metadata, '$.tmdb.rating') >= p_min_rating)
                    AND (p_countries IS NULL OR p_countries = '' OR FIND_IN_SET(CAST(m.origin_country AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci, p_countries COLLATE utf8mb4_unicode_ci) > 0)
                    AND (p_languages IS NULL OR p_languages = '' OR FIND_IN_SET(CAST(m.original_language AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci, p_languages COLLATE utf8mb4_unicode_ci) > 0)
                    AND (
                        p_genre_ids IS NULL OR p_genre_ids = ''
                        OR EXISTS (
                            SELECT 1 FROM movie_genre mg
                            WHERE mg.movie_id = m.id AND FIND_IN_SET(CAST(mg.genre_id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci, p_genre_ids COLLATE utf8mb4_unicode_ci) > 0
                        )
                    );
            END
        ");

        DB::unprepared('DROP PROCEDURE IF EXISTS sp_GetPublicSeriesCatalog');
        DB::unprepared("
            CREATE PROCEDURE sp_GetPublicSeriesCatalog(
                IN p_search VARCHAR(255),
                IN p_genre_ids TEXT,
                IN p_countries TEXT,
                IN p_languages TEXT,
                IN p_year_from SMALLINT,
                IN p_year_to SMALLINT,
                IN p_min_rating DECIMAL(4,2),
                IN p_sort VARCHAR(50),
                IN p_page INT,
                IN p_per_page INT
            )
            BEGIN
                DECLARE v_offset INT DEFAULT 0;
                SET v_offset = GREATEST(p_page - 1, 0) * p_per_page;

                SELECT
                    s.id,
                    s.title,
                    s.slug,
                    s.original_title,
                    s.synopsis,
                    s.release_year,
                    s.status,
                    s.network,
                    s.poster_url,
                    s.backdrop_url,
                    s.has_subtitle,
                    s.has_dubbed,
                    s.seasons_count,
                    s.episodes_count,
                    s.number_of_seasons,
                    s.number_of_episodes,
                    s.filter_rating,
                    s.filter_primary_genre,
                    s.origin_country,
                    s.original_language,
                    s.published_at,
                    s.metadata
                FROM series s
                WHERE s.publish_status = 'published'
                    AND (
                        IFNULL(TRIM(p_search), '') = ''
                        OR (
                            s.title COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', p_search COLLATE utf8mb4_unicode_ci, '%')
                            OR s.original_title COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', p_search COLLATE utf8mb4_unicode_ci, '%')
                            OR s.synopsis COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', p_search COLLATE utf8mb4_unicode_ci, '%')
                        )
                    )
                    AND (p_year_from IS NULL OR p_year_from <= 0 OR s.release_year >= p_year_from)
                    AND (p_year_to IS NULL OR p_year_to <= 0 OR s.release_year <= p_year_to)
                    AND (p_min_rating IS NULL OR p_min_rating <= 0 OR s.filter_rating >= p_min_rating OR JSON_EXTRACT(s.metadata, '$.tmdb.rating') >= p_min_rating)
                    AND (p_countries IS NULL OR p_countries = '' OR FIND_IN_SET(CAST(s.origin_country AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci, p_countries COLLATE utf8mb4_unicode_ci) > 0)
                    AND (p_languages IS NULL OR p_languages = '' OR FIND_IN_SET(CAST(s.original_language AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci, p_languages COLLATE utf8mb4_unicode_ci) > 0)
                    AND (
                        p_genre_ids IS NULL OR p_genre_ids = ''
                        OR EXISTS (
                            SELECT 1 FROM series_genre sg
                            WHERE sg.series_id = s.id AND FIND_IN_SET(CAST(sg.genre_id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci, p_genre_ids COLLATE utf8mb4_unicode_ci) > 0
                        )
                    )
                ORDER BY
                    {$seriesTrendingFresh}
                    {$seriesTrendingFallback}
                    {$seriesHybridOrder}
                    {$seriesPopularOrder}
                    {$seriesTopRatedGuard}
                    CASE WHEN p_sort IN ('top_rated', 'imdb_desc') THEN s.filter_rating END DESC,
                    CASE WHEN p_sort IN ('release_year_desc', 'year_desc') THEN s.release_year END DESC,
                    CASE WHEN p_sort IN ('release_year_asc', 'year_asc') THEN s.release_year END ASC,
                    CASE WHEN p_sort = 'oldest' THEN s.published_at END ASC,
                    CASE WHEN p_sort = 'title_asc' THEN s.title END ASC,
                    CASE WHEN p_sort = 'title_desc' THEN s.title END DESC,
                    CASE WHEN p_sort = 'newest' THEN s.published_at END DESC,
                    s.published_at DESC,
                    s.id DESC
                LIMIT p_per_page OFFSET v_offset;

                SELECT COUNT(*) AS total
                FROM series s
                WHERE s.publish_status = 'published'
                    AND (
                        IFNULL(TRIM(p_search), '') = ''
                        OR (
                            s.title COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', p_search COLLATE utf8mb4_unicode_ci, '%')
                            OR s.original_title COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', p_search COLLATE utf8mb4_unicode_ci, '%')
                            OR s.synopsis COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', p_search COLLATE utf8mb4_unicode_ci, '%')
                        )
                    )
                    AND (p_year_from IS NULL OR p_year_from <= 0 OR s.release_year >= p_year_from)
                    AND (p_year_to IS NULL OR p_year_to <= 0 OR s.release_year <= p_year_to)
                    AND (p_min_rating IS NULL OR p_min_rating <= 0 OR s.filter_rating >= p_min_rating OR JSON_EXTRACT(s.metadata, '$.tmdb.rating') >= p_min_rating)
                    AND (p_countries IS NULL OR p_countries = '' OR FIND_IN_SET(CAST(s.origin_country AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci, p_countries COLLATE utf8mb4_unicode_ci) > 0)
                    AND (p_languages IS NULL OR p_languages = '' OR FIND_IN_SET(CAST(s.original_language AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci, p_languages COLLATE utf8mb4_unicode_ci) > 0)
                    AND (
                        p_genre_ids IS NULL OR p_genre_ids = ''
                        OR EXISTS (
                            SELECT 1 FROM series_genre sg
                            WHERE sg.series_id = s.id AND FIND_IN_SET(CAST(sg.genre_id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci, p_genre_ids COLLATE utf8mb4_unicode_ci) > 0
                        )
                    );
            END
        ");
    }
}
