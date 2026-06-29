<?php

namespace App\Support;

/**
 * SQL fragments for catalog_visibility on public list/search endpoints.
 *
 * Browse (empty search): catalog_search + catalog
 * Search (non-empty search): catalog_search + search
 * Always excludes hidden.
 */
class CatalogVisibilitySql
{
    public static function publicListWhere(string $alias, string $searchParam = 'p_search'): string
    {
        return "
                    AND COALESCE({$alias}.catalog_visibility, 'catalog_search') <> 'hidden'
                    AND (
                        (IFNULL(TRIM({$searchParam}), '') = '' AND COALESCE({$alias}.catalog_visibility, 'catalog_search') IN ('catalog_search', 'catalog'))
                        OR (IFNULL(TRIM({$searchParam}), '') <> '' AND COALESCE({$alias}.catalog_visibility, 'catalog_search') IN ('catalog_search', 'search'))
                    )";
    }
}
