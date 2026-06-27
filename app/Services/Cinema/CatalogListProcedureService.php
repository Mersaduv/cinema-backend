<?php

namespace App\Services\Cinema;

use Illuminate\Support\Facades\DB;
use PDO;

class CatalogListProcedureService
{
    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int, summary: array<string, mixed>}
     */
    public function adminMoviesList(
        ?string $search,
        ?string $publishStatus,
        ?string $processingStatus,
        int $page,
        int $perPage,
    ): array {
        $stmt = $this->executeProcedure('sp_GetAdminMoviesList', [
            $search ?: null,
            $publishStatus ?: null,
            $processingStatus ?: null,
            $page,
            $perPage,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stmt->nextRowset();
        $totalRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0];
        $stmt->nextRowset();
        $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stmt->closeCursor();

        return [
            'rows' => $rows,
            'total' => (int) ($totalRow['total'] ?? 0),
            'summary' => [
                'total' => (int) ($summary['total'] ?? 0),
                'drafts' => (int) ($summary['drafts'] ?? 0),
                'published' => (int) ($summary['published'] ?? 0),
                'processing' => (int) ($summary['processing'] ?? 0),
                'ready' => (int) ($summary['ready'] ?? 0),
            ],
        ];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int, summary: array<string, mixed>}
     */
    public function adminSeriesList(
        ?string $search,
        ?string $publishStatus,
        ?string $status,
        int $page,
        int $perPage,
    ): array {
        $stmt = $this->executeProcedure('sp_GetAdminSeriesList', [
            $search ?: null,
            $publishStatus ?: null,
            $status ?: null,
            $page,
            $perPage,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stmt->nextRowset();
        $totalRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0];
        $stmt->nextRowset();
        $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stmt->closeCursor();

        return [
            'rows' => $rows,
            'total' => (int) ($totalRow['total'] ?? 0),
            'summary' => [
                'total' => (int) ($summary['total'] ?? 0),
                'drafts' => (int) ($summary['drafts'] ?? 0),
                'scheduled' => (int) ($summary['scheduled'] ?? 0),
                'published' => (int) ($summary['published'] ?? 0),
                'featured' => (int) ($summary['featured'] ?? 0),
            ],
        ];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function publicMoviesCatalog(
        ?string $search,
        string $genreIds,
        string $countries,
        string $languages,
        ?int $yearFrom,
        ?int $yearTo,
        ?float $minRating,
        string $sort,
        int $page,
        int $perPage,
    ): array {
        $stmt = $this->executeProcedure('sp_GetPublicMoviesCatalog', [
            $search ?: null,
            $genreIds !== '' ? $genreIds : null,
            $countries !== '' ? $countries : null,
            $languages !== '' ? $languages : null,
            $yearFrom && $yearFrom > 0 ? $yearFrom : null,
            $yearTo && $yearTo > 0 ? $yearTo : null,
            $minRating && $minRating > 0 ? $minRating : null,
            $sort !== '' ? $sort : 'newest',
            $page,
            $perPage,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stmt->nextRowset();
        $totalRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0];
        $stmt->closeCursor();

        return [
            'rows' => $rows,
            'total' => (int) ($totalRow['total'] ?? 0),
        ];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function publicSeriesCatalog(
        ?string $search,
        string $genreIds,
        string $countries,
        string $languages,
        ?int $yearFrom,
        ?int $yearTo,
        ?float $minRating,
        string $sort,
        int $page,
        int $perPage,
    ): array {
        $stmt = $this->executeProcedure('sp_GetPublicSeriesCatalog', [
            $search ?: null,
            $genreIds !== '' ? $genreIds : null,
            $countries !== '' ? $countries : null,
            $languages !== '' ? $languages : null,
            $yearFrom && $yearFrom > 0 ? $yearFrom : null,
            $yearTo && $yearTo > 0 ? $yearTo : null,
            $minRating && $minRating > 0 ? $minRating : null,
            $sort !== '' ? $sort : 'newest',
            $page,
            $perPage,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stmt->nextRowset();
        $totalRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0];
        $stmt->closeCursor();

        return [
            'rows' => $rows,
            'total' => (int) ($totalRow['total'] ?? 0),
        ];
    }

    /**
     * @param  array<int, mixed>  $params
     */
    private function executeProcedure(string $name, array $params): \PDOStatement
    {
        $pdo = DB::connection()->getPdo();

        // Align session collation with catalog text columns (utf8mb4_unicode_ci).
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec("SET collation_connection = 'utf8mb4_unicode_ci'");

        $placeholders = implode(', ', array_fill(0, count($params), '?'));
        $stmt = $pdo->prepare("CALL {$name}({$placeholders})");
        $stmt->execute($params);

        return $stmt;
    }
}
