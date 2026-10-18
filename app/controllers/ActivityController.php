<?php
// FILE: /app/controllers/ActivityController.php

/**
 * SplashEstate CRM - Audit log viewer (activity_logs)
 * Requires audit.view. Tenant users see their tenant's log; platform admins
 * without a tenant see every tenant's log.
 */

class ActivityController extends Controller {

    const PER_PAGE = 50;

    /** entity_type => URL prefix for the detail page */
    private static $entityLinks = array(
        'lead' => 'leads/view/',
        'client' => 'clients/view/',
        'property' => 'properties/view/',
        'deal' => 'deals/view/',
        'task' => 'tasks/view/',
        'webhook' => 'webhooks/view/',
        'workflow' => 'workflows/edit/',
        'user' => 'users/edit/',
        'role' => 'roles/view/',
        'scoring_rule' => 'scoring/edit/'
    );

    private $db;

    public function __construct() {
        $this->db = (new Database())->connect();
    }

    /**
     * Paginated, filterable audit log
     */
    public function index() {
        $this->requirePermission('audit.view');
        $tenantId = $this->getTenantId();
        if (empty($tenantId)) {
            if ($this->getUserRole() !== 'platform_admin') {
                $this->setFlash('error', 'No organization is associated with your account.');
                $this->redirect('dashboard/index');
            }
            $tenantId = null;
        } else {
            $tenantId = (int)$tenantId;
        }

        $filters = $this->readFilters();
        $where = array();
        $params = array();
        if ($tenantId !== null) {
            $where[] = 'al.tenant_id = :tenant_id';
            $params[':tenant_id'] = $tenantId;
        }
        if ($filters['user_id'] !== '') {
            $where[] = 'al.user_id = :user_id';
            $params[':user_id'] = (int)$filters['user_id'];
        }
        if ($filters['action'] !== '') {
            $where[] = 'al.action = :action';
            $params[':action'] = $filters['action'];
        }
        if ($filters['entity_type'] !== '') {
            $where[] = 'al.entity_type = :entity_type';
            $params[':entity_type'] = $filters['entity_type'];
        }
        if ($filters['date_from'] !== '') {
            $where[] = 'al.created_at >= :date_from';
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if ($filters['date_to'] !== '') {
            $where[] = 'al.created_at <= :date_to';
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM activity_logs al" . $whereSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $totalPages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $page = min($page, $totalPages);
        $offset = ($page - 1) * self::PER_PAGE;

        $sql = "SELECT al.*, u.first_name, u.last_name, u.email, t.name AS tenant_name
                FROM activity_logs al
                LEFT JOIN users u ON u.id = al.user_id
                LEFT JOIN tenants t ON t.id = al.tenant_id"
             . $whereSql
             . " ORDER BY al.created_at DESC, al.id DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', self::PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll();

        foreach ($logs as &$log) {
            $log['link'] = $this->entityLink($log['entity_type'], $log['entity_id']);
        }
        unset($log);

        // Keep active filters in pagination links
        $query = array_filter($filters, function ($v) { return $v !== ''; });
        $baseUrl = url('activity/index');

        $this->view('activity/index', array(
            'title' => 'Activity Log',
            'logs' => $logs,
            'filters' => $filters,
            'users' => $this->distinctUsers($tenantId),
            'actions' => $this->distinctValues('action', $tenantId),
            'entityTypes' => $this->distinctValues('entity_type', $tenantId),
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'baseUrl' => $baseUrl,
            'query' => $query,
            'isPlatform' => $tenantId === null
        ));
    }

    /**
     * Sanitised filter values from the query string
     * @return array
     */
    private function readFilters() {
        $get = function ($key) {
            return isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : '';
        };
        $filters = array(
            'user_id' => ctype_digit($get('user_id')) ? $get('user_id') : '',
            'action' => substr($get('action'), 0, 100),
            'entity_type' => substr($get('entity_type'), 0, 50),
            'date_from' => $get('date_from'),
            'date_to' => $get('date_to')
        );
        foreach (array('date_from', 'date_to') as $key) {
            $d = DateTime::createFromFormat('Y-m-d', $filters[$key]);
            if (!$d || $d->format('Y-m-d') !== $filters[$key]) {
                $filters[$key] = '';
            }
        }
        return $filters;
    }

    /**
     * Link to the logged entity when it has a detail page
     * @param string|null $type
     * @param int|null $id
     * @return string|null
     */
    private function entityLink($type, $id) {
        if ($type === null || empty($id) || !isset(self::$entityLinks[$type])) {
            return null;
        }
        return url(self::$entityLinks[$type] . (int)$id);
    }

    /**
     * Users appearing in the log (for the user filter)
     * @param int|null $tenantId
     * @return array
     */
    private function distinctUsers($tenantId) {
        $sql = "SELECT u.id, u.first_name, u.last_name FROM users u";
        if ($tenantId !== null) {
            $sql .= " WHERE u.tenant_id = :tenant_id";
        }
        $sql .= " ORDER BY u.first_name, u.last_name";
        $stmt = $this->db->prepare($sql);
        if ($tenantId !== null) {
            $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Distinct values of a column for filter dropdowns
     * @param string $column 'action' or 'entity_type'
     * @param int|null $tenantId
     * @return array
     */
    private function distinctValues($column, $tenantId) {
        if (!in_array($column, array('action', 'entity_type'), true)) {
            return array();
        }
        $sql = "SELECT DISTINCT `{$column}` FROM activity_logs WHERE `{$column}` IS NOT NULL AND `{$column}` <> ''";
        if ($tenantId !== null) {
            $sql .= " AND tenant_id = :tenant_id";
        }
        $sql .= " ORDER BY `{$column}`";
        $stmt = $this->db->prepare($sql);
        if ($tenantId !== null) {
            $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
