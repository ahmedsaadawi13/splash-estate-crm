<?php
// FILE: /app/views/reports/properties.php
// Variables: $distribution (getPropertyTypeDistribution), $cities (getPropertyCityDistribution)
require APP_PATH . '/views/reports/_header.php';

$propTotals = array('listings' => 0, 'total_value' => 0.0, 'available' => 0, 'pending' => 0, 'sold' => 0, 'rented' => 0, 'off_market' => 0);
foreach ($distribution as $typeRow) {
    foreach ($propTotals as $key => $unused) {
        $propTotals[$key] += $typeRow[$key];
    }
}
?>

<div class="stats-grid">
    <div class="stat-card">
        <h3>New Listings</h3>
        <div class="stat-number"><?php echo (int)$propTotals['listings']; ?></div>
        <div class="stat-label"><?php echo (int)$propTotals['available']; ?> available now</div>
    </div>
    <div class="stat-card">
        <h3>Sold / Rented</h3>
        <div class="stat-number"><?php echo (int)($propTotals['sold'] + $propTotals['rented']); ?></div>
        <div class="stat-label"><?php echo (int)$propTotals['pending']; ?> pending</div>
    </div>
    <div class="stat-card">
        <h3>Listed Value</h3>
        <div class="stat-number"><?php echo e(View::formatCurrency($propTotals['total_value'])); ?></div>
        <div class="stat-label">sum of asking prices</div>
    </div>
</div>

<div class="card mt-2">
    <div class="card-header">
        <h3>Listings by Property Type</h3>
        <?php if ($canExport): ?><a href="<?php echo e($reportExportUrl('properties')); ?>" class="btn btn-sm btn-outline">Export CSV</a><?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($distribution)): ?>
            <div class="empty-state"><p>No properties were listed in this period.</p></div>
        <?php else: ?>
        <div class="report-chart is-short mb-2"><canvas id="propertyChart" role="img" aria-label="Listings by type"></canvas></div>
        <div class="table-responsive">
            <table class="table report-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th class="num">Listings</th>
                        <th class="num">Avg Price</th>
                        <th class="num">Total Value</th>
                        <th class="num">Available</th>
                        <th class="num">Pending</th>
                        <th class="num">Sold</th>
                        <th class="num">Rented</th>
                        <th class="num">Off Market</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($distribution as $row): ?>
                    <tr>
                        <td><?php echo e($row['label']); ?></td>
                        <td class="num"><?php echo (int)$row['listings']; ?></td>
                        <td class="num"><?php echo e(View::formatCurrency($row['avg_price'])); ?></td>
                        <td class="num"><?php echo e(View::formatCurrency($row['total_value'])); ?></td>
                        <td class="num"><?php echo (int)$row['available']; ?></td>
                        <td class="num"><?php echo (int)$row['pending']; ?></td>
                        <td class="num"><?php echo (int)$row['sold']; ?></td>
                        <td class="num"><?php echo (int)$row['rented']; ?></td>
                        <td class="num"><?php echo (int)$row['off_market']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total</td>
                        <td class="num"><?php echo (int)$propTotals['listings']; ?></td>
                        <td class="num"><?php echo $propTotals['listings'] > 0 ? e(View::formatCurrency($propTotals['total_value'] / $propTotals['listings'])) : '&ndash;'; ?></td>
                        <td class="num"><?php echo e(View::formatCurrency($propTotals['total_value'])); ?></td>
                        <td class="num"><?php echo (int)$propTotals['available']; ?></td>
                        <td class="num"><?php echo (int)$propTotals['pending']; ?></td>
                        <td class="num"><?php echo (int)$propTotals['sold']; ?></td>
                        <td class="num"><?php echo (int)$propTotals['rented']; ?></td>
                        <td class="num"><?php echo (int)$propTotals['off_market']; ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>Top Cities</h3></div>
    <div class="card-body table-responsive">
        <?php if (empty($cities)): ?>
            <div class="empty-state"><p>No properties were listed in this period.</p></div>
        <?php else: ?>
        <table class="table report-table">
            <thead><tr><th>City</th><th class="num">Listings</th><th class="num">Avg Price</th><th class="num">Sold / Rented</th></tr></thead>
            <tbody>
                <?php foreach ($cities as $city): ?>
                <tr>
                    <td><?php echo e($city['city']); ?></td>
                    <td class="num"><?php echo (int)$city['listings']; ?></td>
                    <td class="num"><?php echo e(View::formatCurrency($city['avg_price'])); ?></td>
                    <td class="num"><?php echo (int)$city['closed']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var types = <?php echo $reportJson($distribution); ?>;
    reportChart('propertyChart', {
        type: 'bar',
        data: {
            labels: types.map(function (t) { return t.label; }),
            datasets: [{ label: 'Listings', data: types.map(function (t) { return t.listings; }),
                backgroundColor: REPORT_COLORS.primary, borderRadius: 4, maxBarThickness: 40 }]
        },
        options: {
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) {
                var t = types[ctx.dataIndex];
                return t.listings + ' listing(s), avg ' + reportMoney(t.avg_price) + ', ' + t.sold + ' sold';
            } } } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: REPORT_COLORS.grid } }, x: { grid: { display: false } } }
        }
    });
})();
</script>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>
