<?php

namespace Pine\Commerce\Http\Controllers\Admin\Concerns;

use Illuminate\Http\Request;

/**
 * Helpers for admin index (list) pages: whitelisted sorting, per-page, search term and safe LIKE patterns.
 *
 *   [$sort, $direction] = $this->sorting($request, ['code', 'created_at'], 'created_at', 'desc');
 *   $rows = $query->orderBy($sort, $direction)->paginate($this->perPage($request))->withQueryString();
 */
trait AdminIndex
{
    /** @return array{0:string, 1:string} */
    protected function sorting(Request $request, array $allowed, string $default, string $defaultDirection = 'asc'): array
    {
        $sort = $request->query('sort');
        $sort = is_string($sort) && in_array($sort, $allowed, true) ? $sort : $default;
        $direction = $request->query('direction');
        $direction = is_string($direction) && in_array($direction, ['asc', 'desc'], true)
            ? $direction
            : ($sort === $default ? $defaultDirection : 'asc');

        return [$sort, $direction];
    }

    protected function perPage(Request $request, int $default = 25): int
    {
        $perPage = (int) $request->query('per_page', $default);

        return in_array($perPage, [25, 50, 100], true) ? $perPage : $default;
    }

    protected function searchTerm(Request $request, string $key = 'q'): string
    {
        $term = $request->query($key);

        return is_string($term) ? mb_substr(trim($term), 0, 100) : '';
    }

    /** "%term%" with LIKE wildcards in the user's input escaped. */
    protected function like(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }

    /** A query-string value restricted to a set of allowed keys (for filter selects). */
    protected function filterValue(Request $request, string $key, array $allowed): ?string
    {
        $value = $request->query($key);

        return is_string($value) && array_key_exists($value, $allowed) ? $value : null;
    }
}
