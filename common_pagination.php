<?php
// Common pagination renderer extracted to remove duplication in tokens.php and events.php
// Usage: require_once __DIR__.'/common_pagination.php'; then call render_pagination($currentPage,$totalPages,$baseQueryArray)
if(!function_exists('render_pagination')) {
    function render_pagination($currentPage, $totalPages, $baseQuery) {
        $currentPage = max(1,(int)$currentPage);
        $totalPages = max(1,(int)$totalPages);
        if ($totalPages <= 1) { return; }
        $delta = 2; // Number of pages to show around the current page
        $range = [];
        for ($i = 1; $i <= $totalPages; $i++) {
            if ($i == 1 || $i == $totalPages || ($i >= $currentPage - $delta && $i <= $currentPage + $delta)) {
                $range[] = $i;
            }
        }
        $withDots = [];
        $last = 0;
        foreach ($range as $p) {
            if (($p - $last) > 1) {
                $withDots[] = '...';
            }
            $withDots[] = $p;
            $last = $p;
        }
        echo '<nav class="pagination">';
        foreach ($withDots as $p) {
            if ($p === '...') {
                echo '<span class="page-dots">...</span>';
            } else {
                $query = http_build_query(array_merge($baseQuery, ['page' => $p]));
                $activeClass = ($p == $currentPage) ? 'active' : '';
                echo "<a class=\"page {$activeClass}\" href=\"?{$query}\">{$p}</a>";
            }
        }
        echo '</nav>';
    }
}
