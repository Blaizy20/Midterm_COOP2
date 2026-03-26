<?php
require_once __DIR__ . '/../../includes/auth.php';
require_permission('view_dashboard');

header('Content-Type: application/json');

$endpoint = $_GET['endpoint'] ?? '';
$current_tenant = is_system_admin() ? null : require_current_tenant_id();

function analytics_response($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function require_super_admin_analytics() {
    if (!is_system_admin()) {
        analytics_response(['error' => 'Forbidden'], 403);
    }
}

function format_week_label($week_start) {
    $timestamp = strtotime((string)$week_start);
    if (!$timestamp) {
        return (string)$week_start;
    }
    return date('Y-m-d', $timestamp);
}

switch ($endpoint) {
    case 'system_overview':
        if (is_system_admin()) {
            $result = [
                'total_tenants' => fetch_one(q("SELECT COUNT(*) AS count FROM tenants"))['count'] ?? 0,
                'total_users' => fetch_one(q("SELECT COUNT(*) AS count FROM users"))['count'] ?? 0,
                'active_users' => fetch_one(q("SELECT COUNT(*) AS count FROM users WHERE is_active=1"))['count'] ?? 0,
                'inactive_users' => fetch_one(q("SELECT COUNT(*) AS count FROM users WHERE is_active=0"))['count'] ?? 0,
                'total_customers' => fetch_one(q("SELECT COUNT(*) AS count FROM customers WHERE is_active=1"))['count'] ?? 0,
                'total_loans' => fetch_one(q("SELECT COUNT(*) AS count FROM loans"))['count'] ?? 0,
                'active_loans' => fetch_one(q("SELECT COUNT(*) AS count FROM loans WHERE status='ACTIVE'"))['count'] ?? 0,
                'overdue_loans' => fetch_one(q("SELECT COUNT(*) AS count FROM loans WHERE status='OVERDUE'"))['count'] ?? 0,
                'portfolio_value' => fetch_one(q("SELECT IFNULL(SUM(principal_amount), 0) AS total FROM loans WHERE status IN ('ACTIVE','OVERDUE')"))['total'] ?? 0,
                'pending_approvals' => fetch_one(q("SELECT COUNT(*) AS count FROM loans WHERE status IN ('PENDING','CI_REVIEWED')"))['count'] ?? 0
            ];
        } else {
            $result = [
                'total_tenants' => 1,
                'total_users' => fetch_one(q("SELECT COUNT(*) AS count FROM users WHERE tenant_id=?", "i", [$current_tenant]))['count'] ?? 0,
                'active_users' => fetch_one(q("SELECT COUNT(*) AS count FROM users WHERE is_active=1 AND tenant_id=?", "i", [$current_tenant]))['count'] ?? 0,
                'inactive_users' => fetch_one(q("SELECT COUNT(*) AS count FROM users WHERE is_active=0 AND tenant_id=?", "i", [$current_tenant]))['count'] ?? 0,
                'total_customers' => fetch_one(q("SELECT COUNT(*) AS count FROM customers WHERE is_active=1 AND tenant_id=?", "i", [$current_tenant]))['count'] ?? 0,
                'total_loans' => fetch_one(q("SELECT COUNT(*) AS count FROM loans WHERE tenant_id=?", "i", [$current_tenant]))['count'] ?? 0,
                'active_loans' => fetch_one(q("SELECT COUNT(*) AS count FROM loans WHERE status='ACTIVE' AND tenant_id=?", "i", [$current_tenant]))['count'] ?? 0,
                'overdue_loans' => fetch_one(q("SELECT COUNT(*) AS count FROM loans WHERE status='OVERDUE' AND tenant_id=?", "i", [$current_tenant]))['count'] ?? 0,
                'portfolio_value' => fetch_one(q("SELECT IFNULL(SUM(principal_amount), 0) AS total FROM loans WHERE status IN ('ACTIVE','OVERDUE') AND tenant_id=?", "i", [$current_tenant]))['total'] ?? 0,
                'pending_approvals' => fetch_one(q("SELECT COUNT(*) AS count FROM loans WHERE status IN ('PENDING','CI_REVIEWED') AND tenant_id=?", "i", [$current_tenant]))['count'] ?? 0
            ];
        }
        analytics_response($result);

    case 'user_growth':
        if (is_system_admin()) {
            $data = fetch_all(q("SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS count FROM users GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month ASC LIMIT 12"));
        } else {
            $data = fetch_all(q("SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS count FROM users WHERE tenant_id=? GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month ASC LIMIT 12", "i", [$current_tenant]));
        }
        $data = $data ?: [];
        analytics_response(['labels' => array_column($data, 'month'), 'data' => array_column($data, 'count')]);

    case 'loan_status_distribution':
        if (is_system_admin()) {
            $data = fetch_all(q("SELECT status, COUNT(*) AS count FROM loans GROUP BY status"));
        } else {
            $data = fetch_all(q("SELECT status, COUNT(*) AS count FROM loans WHERE tenant_id=? GROUP BY status", "i", [$current_tenant]));
        }
        $data = $data ?: [];
        analytics_response(['labels' => array_column($data, 'status'), 'data' => array_column($data, 'count')]);

    case 'payment_trends':
        if (is_system_admin()) {
            $data = fetch_all(q("SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month, IFNULL(SUM(amount), 0) AS total_amount FROM payments GROUP BY DATE_FORMAT(payment_date, '%Y-%m') ORDER BY month DESC LIMIT 12"));
        } else {
            $data = fetch_all(q("SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month, IFNULL(SUM(amount), 0) AS total_amount FROM payments WHERE tenant_id=? GROUP BY DATE_FORMAT(payment_date, '%Y-%m') ORDER BY month DESC LIMIT 12", "i", [$current_tenant]));
        }
        $data = array_reverse($data ?: []);
        analytics_response(['labels' => array_column($data, 'month'), 'data' => array_column($data, 'total_amount')]);

    case 'sales_trends':
        require_super_admin_analytics();
        $data = fetch_all(q(
            "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month,
                    IFNULL(SUM(amount), 0) AS revenue,
                    COUNT(*) AS transactions
             FROM payments
             GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
             ORDER BY month DESC
             LIMIT 12"
        ));
        $data = array_reverse($data ?: []);
        analytics_response([
            'labels' => array_column($data, 'month'),
            'datasets' => [
                [
                    'label' => 'Revenue',
                    'data' => array_map('floatval', array_column($data, 'revenue')),
                    'borderColor' => '#1d4ed8',
                    'backgroundColor' => 'rgba(29, 78, 216, 0.15)',
                    'borderWidth' => 2,
                    'fill' => true,
                    'tension' => 0.3,
                    'yAxisID' => 'y'
                ],
                [
                    'label' => 'Transactions',
                    'data' => array_map('intval', array_column($data, 'transactions')),
                    'borderColor' => '#0f766e',
                    'backgroundColor' => 'rgba(15, 118, 110, 0.15)',
                    'borderWidth' => 2,
                    'fill' => false,
                    'tension' => 0.3,
                    'yAxisID' => 'y1'
                ]
            ]
        ]);

    case 'sales_trends_daily':
        require_super_admin_analytics();
        $data = fetch_all(q(
            "SELECT DATE(payment_date) AS sales_day,
                    IFNULL(SUM(amount), 0) AS revenue
             FROM payments
             WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
             GROUP BY DATE(payment_date)
             ORDER BY sales_day ASC"
        ));
        $data = $data ?: [];
        analytics_response([
            'labels' => array_column($data, 'sales_day'),
            'data' => array_map('floatval', array_column($data, 'revenue'))
        ]);

    case 'sales_trends_weekly':
        require_super_admin_analytics();
        $data = fetch_all(q(
            "SELECT YEAR(payment_date) AS sales_year,
                    WEEK(payment_date, 1) AS sales_week,
                    MIN(payment_date) AS week_start,
                    IFNULL(SUM(amount), 0) AS revenue
             FROM payments
             WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 11 WEEK)
             GROUP BY YEAR(payment_date), WEEK(payment_date, 1)
             ORDER BY sales_year ASC, sales_week ASC"
        ));
        $data = $data ?: [];
        analytics_response([
            'labels' => array_map('format_week_label', array_column($data, 'week_start')),
            'data' => array_map('floatval', array_column($data, 'revenue'))
        ]);

    case 'sales_trends_monthly':
        require_super_admin_analytics();
        $data = fetch_all(q(
            "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS sales_month,
                    IFNULL(SUM(amount), 0) AS revenue
             FROM payments
             GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
             ORDER BY sales_month DESC
             LIMIT 12"
        ));
        $data = array_reverse($data ?: []);
        analytics_response([
            'labels' => array_column($data, 'sales_month'),
            'data' => array_map('floatval', array_column($data, 'revenue'))
        ]);

    case 'daily_activity':
        if (is_system_admin()) {
            $data = fetch_all(q("SELECT DATE(created_at) AS day, COUNT(*) AS count FROM activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY day ASC"));
        } else {
            $data = fetch_all(q("SELECT DATE(created_at) AS day, COUNT(*) AS count FROM activity_logs WHERE tenant_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY day ASC", "i", [$current_tenant]));
        }
        $data = $data ?: [];
        analytics_response(['labels' => array_column($data, 'day'), 'data' => array_column($data, 'count')]);

    case 'loan_applications_monthly':
        if (is_system_admin()) {
            $data = fetch_all(q("SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS month, COUNT(*) AS count FROM loans GROUP BY DATE_FORMAT(submitted_at, '%Y-%m') ORDER BY month DESC LIMIT 12"));
        } else {
            $data = fetch_all(q("SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS month, COUNT(*) AS count FROM loans WHERE tenant_id=? GROUP BY DATE_FORMAT(submitted_at, '%Y-%m') ORDER BY month DESC LIMIT 12", "i", [$current_tenant]));
        }
        $data = array_reverse($data ?: []);
        analytics_response(['labels' => array_column($data, 'month'), 'data' => array_column($data, 'count')]);

    case 'tenant_activity':
        require_super_admin_analytics();
        $data = fetch_all(q(
            "SELECT COALESCE(t.display_name, t.tenant_name) AS tenant_name,
                    COALESCE(l.loan_count, 0) AS loan_count,
                    COALESCE(c.customer_count, 0) AS customer_count,
                    COALESCE(p.payment_count, 0) AS payment_count
             FROM tenants t
             LEFT JOIN (
                 SELECT tenant_id, COUNT(*) AS loan_count
                 FROM loans
                 GROUP BY tenant_id
             ) l ON l.tenant_id = t.tenant_id
             LEFT JOIN (
                 SELECT tenant_id, COUNT(*) AS customer_count
                 FROM customers
                 GROUP BY tenant_id
             ) c ON c.tenant_id = t.tenant_id
             LEFT JOIN (
                 SELECT tenant_id, COUNT(*) AS payment_count, IFNULL(SUM(amount), 0) AS payment_total
                 FROM payments
                 GROUP BY tenant_id
             ) p ON p.tenant_id = t.tenant_id
             WHERE t.is_active = 1
             ORDER BY COALESCE(p.payment_total, 0) DESC, COALESCE(l.loan_count, 0) DESC
             LIMIT 10"
        ));
        $data = $data ?: [];
        analytics_response([
            'labels' => array_column($data, 'tenant_name'),
            'datasets' => [
                [
                    'label' => 'Loans',
                    'data' => array_map('intval', array_column($data, 'loan_count')),
                    'backgroundColor' => 'rgba(29, 78, 216, 0.72)',
                    'borderColor' => '#1d4ed8',
                    'borderWidth' => 1
                ],
                [
                    'label' => 'Customers',
                    'data' => array_map('intval', array_column($data, 'customer_count')),
                    'backgroundColor' => 'rgba(180, 83, 9, 0.72)',
                    'borderColor' => '#b45309',
                    'borderWidth' => 1
                ],
                [
                    'label' => 'Payments',
                    'data' => array_map('intval', array_column($data, 'payment_count')),
                    'backgroundColor' => 'rgba(15, 118, 110, 0.72)',
                    'borderColor' => '#0f766e',
                    'borderWidth' => 1
                ]
            ]
        ]);

    case 'payment_methods':
        if (is_system_admin()) {
            $data = fetch_all(q("SELECT method, COUNT(*) AS count, IFNULL(SUM(amount), 0) AS total FROM payments GROUP BY method"));
        } else {
            $data = fetch_all(q("SELECT method, COUNT(*) AS count, IFNULL(SUM(amount), 0) AS total FROM payments WHERE tenant_id=? GROUP BY method", "i", [$current_tenant]));
        }
        $data = $data ?: [];
        analytics_response([
            'labels' => array_column($data, 'method'),
            'counts' => array_column($data, 'count'),
            'totals' => array_column($data, 'total')
        ]);

    case 'staff_by_role':
        if (is_system_admin()) {
            $data = fetch_all(q("SELECT role, COUNT(*) AS count FROM users WHERE role <> 'CUSTOMER' GROUP BY role"));
        } else {
            $data = fetch_all(q("SELECT role, COUNT(*) AS count FROM users WHERE role <> 'CUSTOMER' AND tenant_id=? GROUP BY role", "i", [$current_tenant]));
        }
        $data = $data ?: [];
        analytics_response(['labels' => array_column($data, 'role'), 'data' => array_column($data, 'count')]);

    default:
        analytics_response(['error' => 'Invalid endpoint'], 400);
}
?>
