<?php
// FILE: /app/views/leads/_pagination.php
// Filter-preserving pagination (shared by leads, clients and tasks lists).
// Expects: $currentPage (int), $totalPages (int), $paginationPath (e.g. 'leads/index'),
//          $paginationQuery (array of query params to keep)
$currentPage = isset($currentPage) ? (int)$currentPage : 1;
$totalPages = isset($totalPages) ? (int)$totalPages : 1;
if ($totalPages > 1):
    $pageUrl = function ($p) use ($paginationPath, $paginationQuery) {
        $q = is_array($paginationQuery) ? $paginationQuery : array();
        $q['page'] = $p;
        return url($paginationPath) . '?' . http_build_query($q);
    };
    $start = max(1, $currentPage - 3);
    $end = min($totalPages, $currentPage + 3);
?>
<div class="pagination">
    <?php if ($currentPage > 1): ?>
        <a href="<?php echo e($pageUrl($currentPage - 1)); ?>" class="page-link">&laquo; Previous</a>
    <?php endif; ?>
    <?php if ($start > 1): ?>
        <a href="<?php echo e($pageUrl(1)); ?>" class="page-link">1</a>
        <?php if ($start > 2): ?><span class="page-link">&hellip;</span><?php endif; ?>
    <?php endif; ?>
    <?php for ($p = $start; $p <= $end; $p++): ?>
        <a href="<?php echo e($pageUrl($p)); ?>" class="page-link<?php echo $p === $currentPage ? ' active' : ''; ?>"><?php echo $p; ?></a>
    <?php endfor; ?>
    <?php if ($end < $totalPages): ?>
        <?php if ($end < $totalPages - 1): ?><span class="page-link">&hellip;</span><?php endif; ?>
        <a href="<?php echo e($pageUrl($totalPages)); ?>" class="page-link"><?php echo $totalPages; ?></a>
    <?php endif; ?>
    <?php if ($currentPage < $totalPages): ?>
        <a href="<?php echo e($pageUrl($currentPage + 1)); ?>" class="page-link">Next &raquo;</a>
    <?php endif; ?>
</div>
<?php endif; ?>
