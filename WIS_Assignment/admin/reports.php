<?php
// CSV export must run before any HTML is emitted, so only db.php/auth.php
// (no header.php) are loaded until we know this isn't an export request.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$group_by = $_GET['group_by'] ?? 'date';
if (!in_array($group_by, ['date', 'product', 'category', 'payment_method'], true)) {
    $group_by = 'date';
}

$base_params = array_filter([
    'date_from' => $date_from !== '' ? $date_from : null,
    'date_to' => $date_to !== '' ? $date_to : null,
    'group_by' => $group_by !== 'date' ? $group_by : null,
]);
$base_qs = http_build_query($base_params);
$has_active_filters = ($date_from !== '' || $date_to !== '');

// Shared date-range filter, applied to every query below (raw export and
// every group_by breakdown alike).
$date_where = [];
$date_params = [];
if ($date_from !== '') {
    $date_where[] = "DATE(o.order_date) >= ?";
    $date_params[] = $date_from;
}
if ($date_to !== '') {
    $date_where[] = "DATE(o.order_date) <= ?";
    $date_params[] = $date_to;
}
$date_where_sql = !empty($date_where) ? " AND " . implode(" AND ", $date_where) : "";

// --- CSV Export: raw order list matching the active date filter ---
if (($_GET['export'] ?? '') === 'csv') {
    $sql = "SELECT o.id, o.order_date, o.status, o.total_price, o.customer_name,
                   u.username,
                   (SELECT pay.payment_method FROM payments pay WHERE pay.order_id = o.id ORDER BY pay.id DESC LIMIT 1) as payment_method
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.id
            WHERE 1=1" . $date_where_sql . "
            ORDER BY o.order_date DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($date_params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sales_report_' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Order ID', 'Customer', 'Date', 'Status', 'Payment Method', 'Total (RM)']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'],
            $r['username'] ?? $r['customer_name'],
            date('Y-m-d H:i', strtotime($r['order_date'])),
            $r['status'],
            $r['payment_method'] ? ucfirst(str_replace('_', ' ', $r['payment_method'])) : 'N/A',
            number_format($r['total_price'], 2),
        ]);
    }
    fclose($out);
    exit;
}

// --- HTML report page ---
require_once __DIR__ . '/../includes/header.php';

// Overall totals for the active date range (excludes Cancelled orders, same definition as the dashboard).
$totals_stmt = $pdo->prepare(
    "SELECT COUNT(*) as order_count, COALESCE(SUM(o.total_price), 0) as revenue
     FROM orders o
     WHERE o.status != 'Cancelled'" . $date_where_sql
);
$totals_stmt->execute($date_params);
$totals = $totals_stmt->fetch();

// Breakdown table for the selected group_by mode.
switch ($group_by) {
    case 'product':
        $sql = "SELECT p.name as label, SUM(oi.quantity) as qty, SUM(oi.price * oi.quantity) as revenue, COUNT(DISTINCT o.id) as order_count
                FROM orders o
                JOIN order_items oi ON oi.order_id = o.id
                JOIN products p ON p.id = oi.product_id
                WHERE o.status != 'Cancelled'" . $date_where_sql . "
                GROUP BY p.id, p.name
                ORDER BY revenue DESC";
        $columns = ['Product', 'Orders', 'Qty Sold', 'Revenue'];
        break;
    case 'category':
        $sql = "SELECT c.name as label, SUM(oi.quantity) as qty, SUM(oi.price * oi.quantity) as revenue, COUNT(DISTINCT o.id) as order_count
                FROM orders o
                JOIN order_items oi ON oi.order_id = o.id
                JOIN products p ON p.id = oi.product_id
                LEFT JOIN categories c ON c.id = p.category_id
                WHERE o.status != 'Cancelled'" . $date_where_sql . "
                GROUP BY c.id, c.name
                ORDER BY revenue DESC";
        $columns = ['Category', 'Orders', 'Qty Sold', 'Revenue'];
        break;
    case 'payment_method':
        $sql = "SELECT pay.payment_method as label, COUNT(DISTINCT o.id) as order_count, SUM(pay.amount) as revenue
                FROM orders o
                JOIN payments pay ON pay.order_id = o.id
                WHERE o.status != 'Cancelled' AND pay.payment_status = 'completed'" . $date_where_sql . "
                GROUP BY pay.payment_method
                ORDER BY revenue DESC";
        $columns = ['Payment Method', 'Orders', 'Revenue'];
        break;
    case 'date':
    default:
        $sql = "SELECT DATE(o.order_date) as label, COUNT(*) as order_count, SUM(o.total_price) as revenue
                FROM orders o
                WHERE o.status != 'Cancelled'" . $date_where_sql . "
                GROUP BY DATE(o.order_date)
                ORDER BY label DESC";
        $columns = ['Date', 'Orders', 'Revenue'];
        break;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($date_params);
$breakdown = $stmt->fetchAll();

$group_labels = [
    'date' => 'By Date',
    'product' => 'By Product',
    'category' => 'By Category',
    'payment_method' => 'By Payment Method',
];
?>

<div class="page-header-actions">
    <h1>Sales Reports</h1>
</div>

<!-- Date Range Filter -->
<form action="reports.php" method="GET" style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem;">
    <?php if ($group_by !== 'date'): ?>
        <input type="hidden" name="group_by" value="<?= htmlspecialchars($group_by) ?>">
    <?php endif; ?>
    <label style="font-size: 0.85rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.3rem;">
        From <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>" style="padding: 0.4rem;">
    </label>
    <label style="font-size: 0.85rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.3rem;">
        To <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>" style="padding: 0.4rem;">
    </label>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($has_active_filters): ?>
        <a href="reports.php<?= $group_by !== 'date' ? '?group_by=' . htmlspecialchars($group_by) : '' ?>" class="btn btn-sm" style="color: var(--text-muted);">Clear</a>
    <?php endif; ?>
    <a href="reports.php?<?= $base_qs !== '' ? $base_qs . '&' : '' ?>export=csv" class="btn btn-accent btn-sm" style="margin-left: auto;">Export Orders as CSV</a>
</form>

<!-- Range Totals -->
<div class="stat-grid" style="margin-bottom: 1.5rem;">
    <div class="stat-card">
        <span class="title">Orders in Range</span>
        <span class="value"><?= intval($totals['order_count']) ?></span>
    </div>
    <div class="stat-card">
        <span class="title">Revenue in Range</span>
        <span class="value" style="color: var(--success);">RM<?= number_format($totals['revenue'], 2) ?></span>
    </div>
</div>

<!-- Breakdown Mode Tabs -->
<div style="margin-bottom: 1.5rem;">
    <ul class="filter-list" style="display: flex; gap: 1rem; flex-wrap: wrap;">
        <?php foreach ($group_labels as $mode => $mode_label): ?>
            <?php
                $tab_params = $base_params;
                if ($mode !== 'date') {
                    $tab_params['group_by'] = $mode;
                } else {
                    unset($tab_params['group_by']);
                }
                $tab_qs = http_build_query($tab_params);
            ?>
            <li>
                <a href="reports.php<?= $tab_qs !== '' ? '?' . $tab_qs : '' ?>" class="btn btn-secondary btn-sm status-tab <?= ($group_by === $mode) ? 'btn-accent' : '' ?>">
                    <?= $mode_label ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<section class="admin-panel">
    <h3>Sales <?= $group_labels[$group_by] ?></h3>
    <?php if (empty($breakdown)): ?>
        <p style="color: var(--text-muted); padding: 1rem 0;">No sales data found for the selected range.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table" style="font-size: 0.9rem;">
                <thead>
                    <tr>
                        <?php foreach ($columns as $col): ?>
                            <th><?= htmlspecialchars($col) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($breakdown as $row): ?>
                        <tr>
                            <td>
                                <?php if ($group_by === 'payment_method'): ?>
                                    <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $row['label'] ?? 'N/A'))) ?>
                                <?php elseif ($group_by === 'date'): ?>
                                    <?= date('d M Y', strtotime($row['label'])) ?>
                                <?php else: ?>
                                    <?= htmlspecialchars($row['label'] ?? 'Uncategorized') ?>
                                <?php endif; ?>
                            </td>
                            <td><?= intval($row['order_count']) ?></td>
                            <?php if (isset($row['qty'])): ?>
                                <td><?= intval($row['qty']) ?></td>
                            <?php endif; ?>
                            <td style="font-weight: 600; color: var(--primary-dark);">RM<?= number_format($row['revenue'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
