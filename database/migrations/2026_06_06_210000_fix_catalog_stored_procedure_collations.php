<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MySQL 8 binds VARCHAR procedure parameters with utf8mb4_0900_ai_ci while
 * cinema tables use utf8mb4_unicode_ci — comparisons/LIKE then fail with error 1267.
 */
return new class extends Migration
{
    private const CS = 'utf8mb4_unicode_ci';

    public function up(): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_GetAdminMoviesList');
        DB::unprepared('
            CREATE PROCEDURE sp_GetAdminMoviesList(
                IN p_search VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
                IN p_pub_status VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
                IN p_proc_status VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
                IN p_page INT,
                IN p_per_page INT
            )
            BEGIN
                DECLARE v_offset INT DEFAULT 0;
                SET v_offset = GREATEST(p_page - 1, 0) * p_per_page;

                SELECT
                    m.id, m.title, m.slug, LEFT(m.synopsis, 200) AS synopsis,
                    m.release_year, m.poster_path, m.has_subtitle, m.has_dubbed_audio,
                    m.publish_status, m.processing_status, m.processing_error,
                    m.created_at, m.created_by,
                    u.name AS created_by_name, u.email AS created_by_email,
                    JSON_UNQUOTE(JSON_EXTRACT(m.metadata, \'$.tmdb.poster_url\')) AS tmdb_poster_url
                FROM movies m
                LEFT JOIN users u ON u.id = m.created_by
                WHERE
                    (p_pub_status IS NULL OR p_pub_status = \'\' OR m.publish_status = p_pub_status)
                    AND (p_proc_status IS NULL OR p_proc_status = \'\' OR m.processing_status = p_proc_status)
                    AND (
                        p_search IS NULL OR p_search = \'\'
                        OR m.title LIKE CONCAT(\'%\', p_search, \'%\')
                        OR m.original_title LIKE CONCAT(\'%\', p_search, \'%\')
                        OR m.slug LIKE CONCAT(\'%\', p_search, \'%\')
                    )
                ORDER BY m.created_at DESC, m.id DESC
                LIMIT p_per_page OFFSET v_offset;

                SELECT COUNT(*) AS total FROM movies m
                WHERE
                    (p_pub_status IS NULL OR p_pub_status = \'\' OR m.publish_status = p_pub_status)
                    AND (p_proc_status IS NULL OR p_proc_status = \'\' OR m.processing_status = p_proc_status)
                    AND (
                        p_search IS NULL OR p_search = \'\'
                        OR m.title LIKE CONCAT(\'%\', p_search, \'%\')
                        OR m.original_title LIKE CONCAT(\'%\', p_search, \'%\')
                        OR m.slug LIKE CONCAT(\'%\', p_search, \'%\')
                    );

                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN m.publish_status = \'published\' THEN 1 ELSE 0 END) AS published,
                    SUM(CASE WHEN m.publish_status = \'draft\' THEN 1 ELSE 0 END) AS drafts,
                    SUM(CASE WHEN m.processing_status = \'ready\' THEN 1 ELSE 0 END) AS ready,
                    SUM(CASE WHEN m.processing_status IN (\'queued\', \'processing\') THEN 1 ELSE 0 END) AS processing
                FROM movies m;
            END
        ');

        DB::unprepared('DROP PROCEDURE IF EXISTS sp_GetAdminSeriesList');
        DB::unprepared('
            CREATE PROCEDURE sp_GetAdminSeriesList(
                IN p_search VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
                IN p_pub_status VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
                IN p_status VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
                IN p_page INT,
                IN p_per_page INT
            )
            BEGIN
                DECLARE v_offset INT DEFAULT 0;
                SET v_offset = GREATEST(p_page - 1, 0) * p_per_page;

                SELECT
                    s.id, s.title, s.slug, LEFT(s.synopsis, 200) AS synopsis,
                    s.first_air_date, s.status, s.network, s.poster_url,
                    s.has_subtitle, s.has_dubbed, s.is_featured, s.publish_status,
                    s.seasons_count, s.episodes_count, s.created_at, s.created_by,
                    u.name AS created_by_name, u.email AS created_by_email
                FROM series s
                LEFT JOIN users u ON u.id = s.created_by
                WHERE
                    (p_pub_status IS NULL OR p_pub_status = \'\' OR s.publish_status = p_pub_status)
                    AND (p_status IS NULL OR p_status = \'\' OR s.status = p_status)
                    AND (
                        p_search IS NULL OR p_search = \'\'
                        OR s.title LIKE CONCAT(\'%\', p_search, \'%\')
                        OR s.original_title LIKE CONCAT(\'%\', p_search, \'%\')
                        OR s.slug LIKE CONCAT(\'%\', p_search, \'%\')
                        OR s.network LIKE CONCAT(\'%\', p_search, \'%\')
                    )
                ORDER BY s.created_at DESC, s.id DESC
                LIMIT p_per_page OFFSET v_offset;

                SELECT COUNT(*) AS total FROM series s
                WHERE
                    (p_pub_status IS NULL OR p_pub_status = \'\' OR s.publish_status = p_pub_status)
                    AND (p_status IS NULL OR p_status = \'\' OR s.status = p_status)
                    AND (
                        p_search IS NULL OR p_search = \'\'
                        OR s.title LIKE CONCAT(\'%\', p_search, \'%\')
                        OR s.original_title LIKE CONCAT(\'%\', p_search, \'%\')
                        OR s.slug LIKE CONCAT(\'%\', p_search, \'%\')
                        OR s.network LIKE CONCAT(\'%\', p_search, \'%\')
                    );

                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN s.publish_status = \'published\' THEN 1 ELSE 0 END) AS published,
                    SUM(CASE WHEN s.publish_status = \'draft\' THEN 1 ELSE 0 END) AS drafts,
                    SUM(CASE WHEN s.publish_status = \'scheduled\' THEN 1 ELSE 0 END) AS scheduled,
                    SUM(CASE WHEN s.is_featured = 1 THEN 1 ELSE 0 END) AS featured
                FROM series s;
            END
        ');

        DB::unprepared('DROP PROCEDURE IF EXISTS sp_GetPublicMoviesCatalog');
        DB::unprepared('
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
                WHERE m.publish_status = \'published\'
                    AND (p_search IS NULL OR p_search = \'\' OR m.title LIKE CONCAT(\'%\', p_search, \'%\') OR m.original_title LIKE CONCAT(\'%\', p_search, \'%\') OR m.synopsis LIKE CONCAT(\'%\', p_search, \'%\'))
                    AND (p_year_from IS NULL OR p_year_from <= 0 OR m.release_year >= p_year_from)
                    AND (p_year_to IS NULL OR p_year_to <= 0 OR m.release_year <= p_year_to)
                    AND (p_min_rating IS NULL OR p_min_rating <= 0 OR m.filter_rating >= p_min_rating OR JSON_EXTRACT(m.metadata, \'$.tmdb.rating\') >= p_min_rating)
                    AND (p_countries IS NULL OR p_countries = \'\' OR FIND_IN_SET(m.origin_country, p_countries) > 0)
                    AND (p_languages IS NULL OR p_languages = \'\' OR FIND_IN_SET(m.original_language, p_languages) > 0)
                    AND (p_genre_ids IS NULL OR p_genre_ids = \'\' OR EXISTS (
                        SELECT 1 FROM movie_genre mg
                        WHERE mg.movie_id = m.id AND FIND_IN_SET(mg.genre_id, p_genre_ids) > 0
                    ))
                ORDER BY
                    CASE WHEN p_sort = \'oldest\' THEN m.published_at END ASC,
                    CASE WHEN p_sort = \'title_asc\' THEN m.title END ASC,
                    CASE WHEN p_sort = \'title_desc\' THEN m.title END DESC,
                    CASE WHEN p_sort IN (\'top_rated\', \'imdb_desc\') THEN m.filter_rating END DESC,
                    m.published_at DESC, m.id DESC
                LIMIT p_per_page OFFSET v_offset;

                SELECT COUNT(*) AS total FROM movies m
                WHERE m.publish_status = \'published\'
                    AND (p_search IS NULL OR p_search = \'\' OR m.title LIKE CONCAT(\'%\', p_search, \'%\') OR m.original_title LIKE CONCAT(\'%\', p_search, \'%\') OR m.synopsis LIKE CONCAT(\'%\', p_search, \'%\'))
                    AND (p_year_from IS NULL OR p_year_from <= 0 OR m.release_year >= p_year_from)
                    AND (p_year_to IS NULL OR p_year_to <= 0 OR m.release_year <= p_year_to)
                    AND (p_min_rating IS NULL OR p_min_rating <= 0 OR m.filter_rating >= p_min_rating OR JSON_EXTRACT(m.metadata, \'$.tmdb.rating\') >= p_min_rating)
                    AND (p_countries IS NULL OR p_countries = \'\' OR FIND_IN_SET(m.origin_country, p_countries) > 0)
                    AND (p_languages IS NULL OR p_languages = \'\' OR FIND_IN_SET(m.original_language, p_languages) > 0)
                    AND (p_genre_ids IS NULL OR p_genre_ids = \'\' OR EXISTS (
                        SELECT 1 FROM movie_genre mg
                        WHERE mg.movie_id = m.id AND FIND_IN_SET(mg.genre_id, p_genre_ids) > 0
                    ));
            END
        ');

        DB::unprepared('DROP PROCEDURE IF EXISTS sp_GetPublicSeriesCatalog');
        DB::unprepared('
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
                WHERE s.publish_status = \'published\'
                    AND (p_search IS NULL OR p_search = \'\' OR s.title LIKE CONCAT(\'%\', p_search, \'%\') OR s.original_title LIKE CONCAT(\'%\', p_search, \'%\') OR s.synopsis LIKE CONCAT(\'%\', p_search, \'%\'))
                    AND (p_year_from IS NULL OR p_year_from <= 0 OR s.release_year >= p_year_from)
                    AND (p_year_to IS NULL OR p_year_to <= 0 OR s.release_year <= p_year_to)
                    AND (p_min_rating IS NULL OR p_min_rating <= 0 OR s.filter_rating >= p_min_rating OR JSON_EXTRACT(s.metadata, \'$.tmdb.rating\') >= p_min_rating)
                    AND (p_countries IS NULL OR p_countries = \'\' OR FIND_IN_SET(s.origin_country, p_countries) > 0)
                    AND (p_languages IS NULL OR p_languages = \'\' OR FIND_IN_SET(s.original_language, p_languages) > 0)
                    AND (p_genre_ids IS NULL OR p_genre_ids = \'\' OR EXISTS (
                        SELECT 1 FROM series_genre sg
                        WHERE sg.series_id = s.id AND FIND_IN_SET(sg.genre_id, p_genre_ids) > 0
                    ))
                ORDER BY
                    CASE WHEN p_sort = \'oldest\' THEN s.published_at END ASC,
                    CASE WHEN p_sort = \'title_asc\' THEN s.title END ASC,
                    CASE WHEN p_sort = \'title_desc\' THEN s.title END DESC,
                    CASE WHEN p_sort IN (\'top_rated\', \'imdb_desc\') THEN s.filter_rating END DESC,
                    s.published_at DESC, s.id DESC
                LIMIT p_per_page OFFSET v_offset;

                SELECT COUNT(*) AS total FROM series s
                WHERE s.publish_status = \'published\'
                    AND (p_search IS NULL OR p_search = \'\' OR s.title LIKE CONCAT(\'%\', p_search, \'%\') OR s.original_title LIKE CONCAT(\'%\', p_search, \'%\') OR s.synopsis LIKE CONCAT(\'%\', p_search, \'%\'))
                    AND (p_year_from IS NULL OR p_year_from <= 0 OR s.release_year >= p_year_from)
                    AND (p_year_to IS NULL OR p_year_to <= 0 OR s.release_year <= p_year_to)
                    AND (p_min_rating IS NULL OR p_min_rating <= 0 OR s.filter_rating >= p_min_rating OR JSON_EXTRACT(s.metadata, \'$.tmdb.rating\') >= p_min_rating)
                    AND (p_countries IS NULL OR p_countries = \'\' OR FIND_IN_SET(s.origin_country, p_countries) > 0)
                    AND (p_languages IS NULL OR p_languages = \'\' OR FIND_IN_SET(s.original_language, p_languages) > 0)
                    AND (p_genre_ids IS NULL OR p_genre_ids = \'\' OR EXISTS (
                        SELECT 1 FROM series_genre sg
                        WHERE sg.series_id = s.id AND FIND_IN_SET(sg.genre_id, p_genre_ids) > 0
                    ));
            END
        ');
    }

    public function down(): void
    {
        // Re-run 2026_06_06_200001 if a full rollback is required.
    }
};
