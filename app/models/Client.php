<?php
// FILE: /app/models/Client.php

/**
 * SplashEstate CRM - Client Model
 * Handles client management
 */

class Client extends Model {

    protected $table = 'clients';

    /** Allowed values for clients.client_type */
    const TYPES = array('buyer', 'seller', 'landlord', 'tenant');

    /** Allowed values for clients.status */
    const STATUSES = array('active', 'inactive');

    /**
     * Build WHERE clause for list/count queries
     * @param int $tenantId Tenant ID
     * @param array $filters client_type, status, search
     * @param array $params (out) bound parameters
     * @return string
     */
    private function buildWhere($tenantId, $filters, &$params) {
        $where = array('c.tenant_id = :tenant_id');
        $params = array(':tenant_id' => (int)$tenantId);

        if (!empty($filters['client_type'])) {
            $where[] = 'c.client_type = :client_type';
            $params[':client_type'] = $filters['client_type'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'c.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            // Native prepares: each placeholder may only be used once
            $term = '%' . $filters['search'] . '%';
            $where[] = "(c.first_name LIKE :s1 OR c.last_name LIKE :s2 OR c.email LIKE :s3 OR c.phone LIKE :s4 OR c.city LIKE :s5"
                     . " OR CONCAT(c.first_name, ' ', IFNULL(c.last_name, '')) LIKE :s6)";
            for ($i = 1; $i <= 6; $i++) {
                $params[':s' . $i] = $term;
            }
        }
        return ' WHERE ' . implode(' AND ', $where);
    }

    /**
     * Get clients with lead info
     * @param int $tenantId Tenant ID
     * @param int $page Page number
     * @param int $perPage Records per page
     * @param array $filters Filters
     * @return array Clients
     */
    public function getClientsWithInfo($tenantId, $page = 1, $perPage = 20, $filters = array()) {
        $page = max(1, (int)$page);
        $perPage = max(1, (int)$perPage);
        $offset = ($page - 1) * $perPage;

        $params = array();
        $sql = "SELECT c.*, l.source AS lead_source
                FROM {$this->table} c
                LEFT JOIN leads l ON c.lead_id = l.id AND l.tenant_id = c.tenant_id"
             . $this->buildWhere($tenantId, $filters, $params)
             . " ORDER BY c.created_at DESC, c.id DESC LIMIT :limit OFFSET :offset";

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
     * Count clients (overrides Model::count so the 'search' filter works)
     * @param int $tenantId Tenant ID
     * @param array $filters Filters
     * @return int
     */
    public function count($tenantId, $filters = array()) {
        $params = array();
        $sql = "SELECT COUNT(*) FROM {$this->table} c" . $this->buildWhere($tenantId, $filters, $params);
        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get a client with the originating lead's name/source
     * @param int $id Client ID
     * @param int $tenantId Tenant ID
     * @return array|false
     */
    public function getClientWithInfo($id, $tenantId) {
        $sql = "SELECT c.*, l.source AS lead_source,
                       CONCAT(l.first_name, ' ', IFNULL(l.last_name, '')) AS lead_name
                FROM {$this->table} c
                LEFT JOIN leads l ON c.lead_id = l.id AND l.tenant_id = c.tenant_id
                WHERE c.id = :id AND c.tenant_id = :tenant_id
                LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Deals linked to a client
     * @param int $clientId Client ID
     * @param int $tenantId Tenant ID
     * @return array
     */
    public function getDeals($clientId, $tenantId) {
        $sql = "SELECT d.id, d.title, d.stage, d.deal_value, d.expected_close_date, p.title AS property_title
                FROM deals d
                LEFT JOIN properties p ON p.id = d.property_id AND p.tenant_id = d.tenant_id
                WHERE d.client_id = :client_id AND d.tenant_id = :tenant_id
                ORDER BY d.created_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':client_id', (int)$clientId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Properties owned by a client
     * @param int $clientId Client ID
     * @param int $tenantId Tenant ID
     * @return array
     */
    public function getOwnedProperties($clientId, $tenantId) {
        $sql = "SELECT id, title, city, price, status FROM properties
                WHERE owner_id = :client_id AND tenant_id = :tenant_id
                ORDER BY created_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':client_id', (int)$clientId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Create client
     * @param array $data Client data
     * @return int|false Client ID or false
     */
    public function createClient($data) {
        $sql = "INSERT INTO {$this->table}
                (tenant_id, lead_id, first_name, last_name, email, phone, phone_secondary, address, city, state, zip, client_type, status, notes, created_at)
                VALUES (:tenant_id, :lead_id, :first_name, :last_name, :email, :phone, :phone_secondary, :address, :city, :state, :zip, :client_type, :status, :notes, NOW())";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$data['tenant_id'], PDO::PARAM_INT);
        $leadId = !empty($data['lead_id']) ? (int)$data['lead_id'] : null;
        $stmt->bindValue(':lead_id', $leadId, $leadId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':first_name', $data['first_name']);
        foreach (array('last_name', 'email', 'phone', 'phone_secondary', 'address', 'city', 'state', 'zip', 'notes') as $field) {
            $value = isset($data[$field]) && $data[$field] !== '' ? $data[$field] : null;
            $stmt->bindValue(':' . $field, $value);
        }
        $stmt->bindValue(':client_type', !empty($data['client_type']) ? $data['client_type'] : 'buyer');
        $stmt->bindValue(':status', !empty($data['status']) ? $data['status'] : 'active');

        if ($stmt->execute()) {
            return (int)$this->conn->lastInsertId();
        }
        return false;
    }

    /**
     * Minimal list of a tenant's clients for dropdowns
     * @param int $tenantId Tenant ID
     * @return array id, first_name, last_name
     */
    public function getOptions($tenantId) {
        $stmt = $this->conn->prepare("SELECT id, first_name, last_name FROM {$this->table} WHERE tenant_id = :tenant_id ORDER BY first_name, last_name");
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
