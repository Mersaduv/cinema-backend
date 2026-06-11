<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MOVIE_COUNTRY_FILTER = <<<'SQL'
(
    p_countries IS NULL OR p_countries = ''
    OR FIND_IN_SET(m.origin_country, p_countries) > 0
    OR EXISTS (
        SELECT 1
        FROM production_countries pc
        WHERE FIND_IN_SET(pc.iso_3166_1, p_countries) > 0
          AND (
              JSON_CONTAINS(COALESCE(m.metadata, JSON_OBJECT()), JSON_QUOTE(pc.iso_3166_1), '$.tmdb.country_isos')
              OR JSON_CONTAINS(COALESCE(m.metadata, JSON_OBJECT()), JSON_QUOTE(pc.name), '$.tmdb.countries')
              OR (
                  pc.native_name IS NOT NULL
                  AND pc.native_name <> ''
                  AND JSON_CONTAINS(COALESCE(m.metadata, JSON_OBJECT()), JSON_QUOTE(pc.native_name), '$.tmdb.countries')
              )
              OR (
                  pc.english_name IS NOT NULL
                  AND pc.english_name <> ''
                  AND JSON_CONTAINS(COALESCE(m.metadata, JSON_OBJECT()), JSON_QUOTE(pc.english_name), '$.tmdb.countries')
              )
          )
    )
)
SQL;

    private const SERIES_COUNTRY_FILTER = <<<'SQL'
(
    p_countries IS NULL OR p_countries = ''
    OR FIND_IN_SET(s.origin_country, p_countries) > 0
    OR EXISTS (
        SELECT 1
        FROM production_countries pc
        WHERE FIND_IN_SET(pc.iso_3166_1, p_countries) > 0
          AND (
              JSON_CONTAINS(COALESCE(s.metadata, JSON_OBJECT()), JSON_QUOTE(pc.iso_3166_1), '$.tmdb.country_isos')
              OR JSON_CONTAINS(COALESCE(s.metadata, JSON_OBJECT()), JSON_QUOTE(pc.name), '$.tmdb.countries')
              OR (
                  pc.native_name IS NOT NULL
                  AND pc.native_name <> ''
                  AND JSON_CONTAINS(COALESCE(s.metadata, JSON_OBJECT()), JSON_QUOTE(pc.native_name), '$.tmdb.countries')
              )
              OR (
                  pc.english_name IS NOT NULL
                  AND pc.english_name <> ''
                  AND JSON_CONTAINS(COALESCE(s.metadata, JSON_OBJECT()), JSON_QUOTE(pc.english_name), '$.tmdb.countries')
              )
          )
    )
)
SQL;

    public function up(): void
    {
        if (Schema::hasTable('production_countries') && ! Schema::hasColumn('production_countries', 'english_name')) {
            Schema::table('production_countries', function (Blueprint $table) {
                $table->string('english_name')->nullable()->after('native_name');
            });
        }

        $movieCountryFilter = self::MOVIE_COUNTRY_FILTER;
        $seriesCountryFilter = self::SERIES_COUNTRY_FILTER;

        DB::unprepared('DROP PROCEDURE IF EXISTS sp_GetPublicMoviesCatalog');
        DB::unprepared("
            CREATE PROCEDURE sp_GetPublicMoviesCatalog(
                IN p_search VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
                IN p_genre_ids TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
                IN p_countries TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
                IN p_languages TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
                IN p_year_from SMALLINT,
                IN p_year_to SMALLINT,
                IN p_min_rating DECIMAL(4,2),
                IN p_sort VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
                IN p_page INT,
                IN p_per_page INT
            )
            BEGIN
                DECLARE v_offset INT DEFAULT 0;
                SET v_offset = GREATEST(p_page - 1, 0) * p_per_page;

                SELECT
                    m.id, m.title, m.slug, m.synopsis, m.release_year, m.poster_path,
                    m.has_subtitle, m.has_dubbed_audio, m.filter_rating,
                    m.origin_country, m.original_language, m.published_at, m.metadata
                FROM movies m
                WHERE m.publish_status = 'published'
                    AND (p_search IS NULL OR p_search = '' OR m.title LIKE CONCAT('%', p_search, '%') OR m.original_title LIKE CONCAT('%', p_search, '%') OR m.synopsis LIKE CONCAT('%', p_search, '%'))
                    AND (p_year_from IS NULL OR p_year_from <= 0 OR m.release_year >= p_year_from)
                    AND (p_year_to IS NULL OR p_year_to <= 0 OR m.release_year <= p_year_to)
                    AND (p_min_rating IS NULL OR p_min_rating <= 0 OR m.filter_rating >= p_min_rating OR JSON_EXTRACT(m.metadata, '$.tmdb.rating') >= p_min_rating)
                    AND {$movieCountryFilter}
                    AND (p_languages IS NULL OR p_languages = '' OR FIND_IN_SET(m.original_language, p_languages) > 0)
                    AND (p_genre_ids IS NULL OR p_genre_ids = '' OR EXISTS (
                        SELECT 1 FROM movie_genre mg
                        WHERE mg.movie_id = m.id AND FIND_IN_SET(mg.genre_id, p_genre_ids) > 0
                    ))
                ORDER BY
                    CASE WHEN p_sort = 'oldest' THEN m.published_at END ASC,
                    CASE WHEN p_sort = 'title_asc' THEN m.title END ASC,
                    CASE WHEN p_sort = 'title_desc' THEN m.title END DESC,
                    CASE WHEN p_sort IN ('top_rated', 'imdb_desc') THEN m.filter_rating END DESC,
                    m.published_at DESC, m.id DESC
                LIMIT p_per_page OFFSET v_offset;

                SELECT COUNT(*) AS total FROM movies m
                WHERE m.publish_status = 'published'
                    AND (p_search IS NULL OR p_search = '' OR m.title LIKE CONCAT('%', p_search, '%') OR m.original_title LIKE CONCAT('%', p_search, '%') OR m.synopsis LIKE CONCAT('%', p_search, '%'))
                    AND (p_year_from IS NULL OR p_year_from <= 0 OR m.release_year >= p_year_from)
                    AND (p_year_to IS NULL OR p_year_to <= 0 OR m.release_year <= p_year_to)
                    AND (p_min_rating IS NULL OR p_min_rating <= 0 OR m.filter_rating >= p_min_rating OR JSON_EXTRACT(m.metadata, '$.tmdb.rating') >= p_min_rating)
                    AND {$movieCountryFilter}
                    AND (p_languages IS NULL OR p_languages = '' OR FIND_IN_SET(m.original_language, p_languages) > 0)
                    AND (p_genre_ids IS NULL OR p_genre_ids = '' OR EXISTS (
                        SELECT 1 FROM movie_genre mg
                        WHERE mg.movie_id = m.id AND FIND_IN_SET(mg.genre_id, p_genre_ids) > 0
                    ));
            END
        ");

        DB::unprepared('DROP PROCEDURE IF EXISTS sp_GetPublicSeriesCatalog');
        DB::unprepared("
            CREATE PROCEDURE sp_GetPublicSeriesCatalog(
                IN p_search VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
                IN p_genre_ids TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
                IN p_countries TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
                IN p_languages TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
                IN p_year_from SMALLINT,
                IN p_year_to SMALLINT,
                IN p_min_rating DECIMAL(4,2),
                IN p_sort VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
                IN p_page INT,
                IN p_per_page INT
            )
            BEGIN
                DECLARE v_offset INT DEFAULT 0;
                SET v_offset = GREATEST(p_page - 1, 0) * p_per_page;

                SELECT
                    s.id, s.title, s.slug, s.original_title, s.synopsis, s.release_year,
                    s.status, s.network, s.poster_url, s.backdrop_url,
                    s.has_subtitle, s.has_dubbed, s.seasons_count, s.episodes_count,
                    s.number_of_seasons, s.number_of_episodes, s.filter_rating,
                    s.filter_primary_genre, s.origin_country, s.original_language,
                    s.published_at, s.metadata
                FROM series s
                WHERE s.publish_status = 'published'
                    AND (p_search IS NULL OR p_search = '' OR s.title LIKE CONCAT('%', p_search, '%') OR s.original_title LIKE CONCAT('%', p_search, '%') OR s.synopsis LIKE CONCAT('%', p_search, '%'))
                    AND (p_year_from IS NULL OR p_year_from <= 0 OR s.release_year >= p_year_from)
                    AND (p_year_to IS NULL OR p_year_to <= 0 OR s.release_year <= p_year_to)
                    AND (p_min_rating IS NULL OR p_min_rating <= 0 OR s.filter_rating >= p_min_rating OR JSON_EXTRACT(s.metadata, '$.tmdb.rating') >= p_min_rating)
                    AND {$seriesCountryFilter}
                    AND (p_languages IS NULL OR p_languages = '' OR FIND_IN_SET(s.original_language, p_languages) > 0)
                    AND (p_genre_ids IS NULL OR p_genre_ids = '' OR EXISTS (
                        SELECT 1 FROM series_genre sg
                        WHERE sg.series_id = s.id AND FIND_IN_SET(sg.genre_id, p_genre_ids) > 0
                    ))
                ORDER BY
                    CASE WHEN p_sort = 'oldest' THEN s.published_at END ASC,
                    CASE WHEN p_sort = 'title_asc' THEN s.title END ASC,
                    CASE WHEN p_sort = 'title_desc' THEN s.title END DESC,
                    CASE WHEN p_sort IN ('top_rated', 'imdb_desc') THEN s.filter_rating END DESC,
                    s.published_at DESC, s.id DESC
                LIMIT p_per_page OFFSET v_offset;

                SELECT COUNT(*) AS total FROM series s
                WHERE s.publish_status = 'published'
                    AND (p_search IS NULL OR p_search = '' OR s.title LIKE CONCAT('%', p_search, '%') OR s.original_title LIKE CONCAT('%', p_search, '%') OR s.synopsis LIKE CONCAT('%', p_search, '%'))
                    AND (p_year_from IS NULL OR p_year_from <= 0 OR s.release_year >= p_year_from)
                    AND (p_year_to IS NULL OR p_year_to <= 0 OR s.release_year <= p_year_to)
                    AND (p_min_rating IS NULL OR p_min_rating <= 0 OR s.filter_rating >= p_min_rating OR JSON_EXTRACT(s.metadata, '$.tmdb.rating') >= p_min_rating)
                    AND {$seriesCountryFilter}
                    AND (p_languages IS NULL OR p_languages = '' OR FIND_IN_SET(s.original_language, p_languages) > 0)
                    AND (p_genre_ids IS NULL OR p_genre_ids = '' OR EXISTS (
                        SELECT 1 FROM series_genre sg
                        WHERE sg.series_id = s.id AND FIND_IN_SET(sg.genre_id, p_genre_ids) > 0
                    ));
            END
        ");
    }

    public function down(): void
    {
        // Stored procedure rollback is handled by the previous migration revision.
        if (Schema::hasTable('production_countries') && Schema::hasColumn('production_countries', 'english_name')) {
            Schema::table('production_countries', function (Blueprint $table) {
                $table->dropColumn('english_name');
            });
        }
    }
};
