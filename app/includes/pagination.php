<?php
/**
 * ページング表示用の共通ヘルパー。
 */

function getPageNumber(string $paramName): int
{
    $page = (int) ($_GET[$paramName] ?? 1);
    return max(1, $page);
}

function getTotalPages(int $totalCount, int $perPage): int
{
    if ($perPage <= 0) {
        return 1;
    }

    return max(1, (int) ceil($totalCount / $perPage));
}

function buildPaginationUrl(string $pageParam, int $page, ?string $anchor = null): string
{
    $params = $_GET;

    if ($page <= 1) {
        unset($params[$pageParam]);
    } else {
        $params[$pageParam] = $page;
    }

    $query = http_build_query($params);
    $url = $query === '' ? basename($_SERVER['PHP_SELF']) : basename($_SERVER['PHP_SELF']) . '?' . $query;

    if ($anchor !== null && $anchor !== '') {
        $url .= '#' . ltrim($anchor, '#');
    }

    return $url;
}

function renderPagination(string $pageParam, int $currentPage, int $totalPages, ?string $anchor = null): void
{
    if ($totalPages <= 1) {
        return;
    }
    ?>
    <nav class="pagination" aria-label="ページ切り替え">
        <?php for ($page = 1; $page <= $totalPages; $page++): ?>
        <a
            class="pagination-link <?php echo $page === $currentPage ? 'is-current' : ''; ?>"
            href="<?php echo htmlspecialchars(buildPaginationUrl($pageParam, $page, $anchor)); ?>"
            <?php echo $page === $currentPage ? 'aria-current="page"' : ''; ?>
        >
            <?php echo (int) $page; ?>
        </a>
        <?php endfor; ?>
    </nav>
    <?php
}
