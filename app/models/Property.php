<?php
// FILE: /app/models/Property.php

/**
 * SplashEstate CRM - Property Model
 * Handles property listings
 */

class Property extends Model {

    protected $table = 'properties';

    /** Allowed enum values (mirror database.sql) */
    const TYPES = array('residential', 'commercial', 'land');
    const LISTING_TYPES = array('sale', 'rent', 'lease');
    const STATUSES = array('available', 'pending', 'sold', 'rented', 'off_market');

    /**
     * Build the WHERE clause shared by the list and count queries.
     * Supported filters: property_type, listing_type, status, city, min_price,
     * max_price, bedrooms (minimum), owner_id, search.
     * @param int $tenantId Tenant ID
     * @param array $filters Filters
     * @param array $params (out) bound parameters
     * @return string SQL fragment starting with "WHERE"
     */
    private function buildWhere($tenantId, $filters, &$params) {
        $where = "WHERE p.tenant_id = :tenant_id";
        $params = array(':tenant_id' => (int)$tenantId);

        if (!empty($filters['property_type']) && in_array($filters['property_type'], self::TYPES, true)) {
            $where .= " AND p.property_type = :property_type";
            $params[':property_type'] = $filters['property_type'];
        }
        if (!empty($filters['listing_type']) && in_array($filters['listing_type'], self::LISTING_TYPES, true)) {
            $where .= " AND p.listing_type = :listing_type";
            $params[':listing_type'] = $filters['listing_type'];
        }
        if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $where .= " AND p.status = :status";
            $params[':status'] = $filters['status'];
        }
        if (isset($filters['city']) && trim((string)$filters['city']) !== '') {
            $where .= " AND p.city = :city";
            $params[':city'] = trim((string)$filters['city']);
        }
        if (isset($filters['min_price']) && is_numeric($filters['min_price'])) {
            $where .= " AND p.price >= :min_price";
            $params[':min_price'] = (float)$filters['min_price'];
        }
        if (isset($filters['max_price']) && is_numeric($filters['max_price'])) {
            $where .= " AND p.price <= :max_price";
            $params[':max_price'] = (float)$filters['max_price'];
        }
        if (isset($filters['bedrooms']) && is_numeric($filters['bedrooms'])) {
            $where .= " AND p.bedrooms >= :bedrooms";
            $params[':bedrooms'] = (int)$filters['bedrooms'];
        }
        if (!empty($filters['owner_id'])) {
            $where .= " AND p.owner_id = :owner_id";
            $params[':owner_id'] = (int)$filters['owner_id'];
        }
        if (isset($filters['search']) && trim((string)$filters['search']) !== '') {
            // Native prepares: every placeholder must be unique
            $where .= " AND (p.title LIKE :search1 OR p.address LIKE :search2 OR p.city LIKE :search3 OR p.zip LIKE :search4)";
            $term = '%' . trim((string)$filters['search']) . '%';
            $params[':search1'] = $term;
            $params[':search2'] = $term;
            $params[':search3'] = $term;
            $params[':search4'] = $term;
        }

        return $where;
    }

    /**
     * Get properties with agent/owner names and primary image id
     * @param int $tenantId Tenant ID
     * @param int $page Page number
     * @param int $perPage Records per page
     * @param array $filters Filters (see buildWhere())
     * @return array Properties
     */
    public function getPropertiesWithInfo($tenantId, $page = 1, $perPage = 20, $filters = array()) {
        $page = max(1, (int)$page);
        $perPage = max(1, (int)$perPage);
        $offset = ($page - 1) * $perPage;

        $params = array();
        $where = $this->buildWhere($tenantId, $filters, $params);

        $sql = "SELECT p.*,
                CONCAT(u.first_name, ' ', u.last_name) AS agent_name,
                CONCAT(c.first_name, ' ', COALESCE(c.last_name, '')) AS owner_name,
                (SELECT pi.id FROM property_images pi
                  WHERE pi.property_id = p.id AND pi.tenant_id = p.tenant_id
                  ORDER BY pi.is_primary DESC, pi.display_order ASC, pi.id ASC LIMIT 1) AS primary_image_id
                FROM {$this->table} p
                LEFT JOIN users u ON p.listing_agent_id = u.id AND u.tenant_id = p.tenant_id
                LEFT JOIN clients c ON p.owner_id = c.id AND c.tenant_id = p.tenant_id
                {$where}
                ORDER BY p.created_at DESC, p.id DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Count properties matching the same filters as getPropertiesWithInfo()
     * @param int $tenantId Tenant ID
     * @param array $filters Filters
     * @return int
     */
    public function count($tenantId, $filters = array()) {
        $params = array();
        $where = $this->buildWhere($tenantId, $filters, $params);
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM {$this->table} p {$where}");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get one property with agent and owner details
     * @param int $id Property ID
     * @param int $tenantId Tenant ID
     * @return array|false
     */
    public function getPropertyWithInfo($id, $tenantId) {
        $sql = "SELECT p.*,
                CONCAT(u.first_name, ' ', u.last_name) AS agent_name,
                u.email AS agent_email, u.phone AS agent_phone,
                CONCAT(c.first_name, ' ', COALESCE(c.last_name, '')) AS owner_name,
                c.email AS owner_email, c.phone AS owner_phone
                FROM {$this->table} p
                LEFT JOIN users u ON p.listing_agent_id = u.id AND u.tenant_id = p.tenant_id
                LEFT JOIN clients c ON p.owner_id = c.id AND c.tenant_id = p.tenant_id
                WHERE p.id = :id AND p.tenant_id = :tenant_id
                LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Distinct cities used by a tenant's listings (for the filter dropdown)
     * @param int $tenantId Tenant ID
     * @return array City names
     */
    public function getCities($tenantId) {
        $stmt = $this->conn->prepare(
            "SELECT DISTINCT city FROM {$this->table} WHERE tenant_id = :tenant_id AND city <> '' ORDER BY city ASC"
        );
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Lightweight id/title list for dropdowns
     * @param int $tenantId Tenant ID
     * @return array
     */
    public function getOptions($tenantId) {
        $stmt = $this->conn->prepare(
            "SELECT id, title, address, city, price, listing_type, status
             FROM {$this->table} WHERE tenant_id = :tenant_id ORDER BY title ASC"
        );
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Find open leads (same tenant) whose interest and budget match a listing.
     * sale -> buy; rent/lease -> rent or lease. A lead matches when its budget
     * range contains the listing price (an open-ended side counts as a match);
     * leads with no budget at all are excluded. Won/lost leads are skipped.
     * @param array $property Property row (needs id, tenant_id, listing_type, price)
     * @param int $tenantId Tenant ID
     * @param int $limit Max results
     * @return array Lead rows with assigned_name
     */
    public function getMatchingLeads($property, $tenantId, $limit = 20) {
        if (empty($property) || (int)$property['tenant_id'] !== (int)$tenantId) {
            return array();
        }

        $interests = $property['listing_type'] === 'sale' ? array('buy') : array('rent', 'lease');
        $price = (float)$property['price'];

        $placeholders = array();
        foreach ($interests as $i => $interest) {
            $placeholders[] = ':interest' . $i;
        }

        $sql = "SELECT l.id, l.first_name, l.last_name, l.email, l.phone, l.status, l.interest_type,
                       l.budget_min, l.budget_max,
                       CONCAT(u.first_name, ' ', u.last_name) AS assigned_name
                FROM leads l
                LEFT JOIN users u ON l.assigned_to = u.id
                WHERE l.tenant_id = :tenant_id
                  AND l.status NOT IN ('won', 'lost')
                  AND l.interest_type IN (" . implode(', ', $placeholders) . ")
                  AND (l.budget_min IS NOT NULL OR l.budget_max IS NOT NULL)
                  AND (l.budget_min IS NULL OR l.budget_min <= :price1)
                  AND (l.budget_max IS NULL OR l.budget_max >= :price2)
                ORDER BY ABS(COALESCE(l.budget_max, l.budget_min) - :price3) ASC, l.created_at DESC
                LIMIT :limit";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        foreach ($interests as $i => $interest) {
            $stmt->bindValue(':interest' . $i, $interest);
        }
        $stmt->bindValue(':price1', $price);
        $stmt->bindValue(':price2', $price);
        $stmt->bindValue(':price3', $price);
        $stmt->bindValue(':limit', max(1, (int)$limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Create property
     * @param array $data Property data (must include tenant_id)
     * @return int|bool Property ID or false
     */
    public function createProperty($data) {
        $columns = array('tenant_id', 'owner_id', 'listing_agent_id', 'title', 'description', 'property_type',
            'sub_type', 'listing_type', 'price', 'bedrooms', 'bathrooms', 'square_feet', 'lot_size', 'year_built',
            'address', 'city', 'state', 'zip', 'latitude', 'longitude', 'status');

        $row = array();
        foreach ($columns as $column) {
            $row[$column] = array_key_exists($column, $data) ? $data[$column] : null;
        }
        if ($row['status'] === null) {
            $row['status'] = 'available';
        }
        if ($row['property_type'] === null) {
            $row['property_type'] = 'residential';
        }
        if ($row['listing_type'] === null) {
            $row['listing_type'] = 'sale';
        }

        $id = $this->create($row);
        return $id ? (int)$id : false;
    }
}
