<?php
// FILE: /app/helpers/BulkOperationService.php

/**
 * SplashEstate CRM - Bulk Operation Service
 * Bulk delete / status change / assignment / email / lead conversion.
 *
 * Every operation:
 *   - accepts at most MAX_IDS integer ids
 *   - loads the records WHERE tenant_id = current tenant first, so ids that
 *     belong to another tenant are silently counted as "not found"
 *   - dispatches the usual per-record events (scoring, workflows, webhooks)
 *   - writes an activity log entry per changed record
 * Permission checks are done by BulkOperationsController.
 */

class BulkOperationService {

    const MAX_IDS = 500;
    const MAX_EMAILS_PER_REQUEST = 50;
    const MAX_EMAILS_PER_HOUR = 200;

    /** @var PDO */
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Entity configuration (only these tables/columns are ever used in SQL)
     * @param string $entityType
     * @return array|null
     */
    public static function entityConfig($entityType) {
        $config = array(
            'leads' => array(
                'table' => 'leads', 'singular' => 'lead', 'perm' => 'leads',
                'status_field' => 'status',
                'statuses' => array('new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost'),
                'assign_field' => 'assigned_to', 'email' => true
            ),
            'clients' => array(
                'table' => 'clients', 'singular' => 'client', 'perm' => 'clients',
                'status_field' => 'status', 'statuses' => array('active', 'inactive'),
                'assign_field' => null, 'email' => true
            ),
            'properties' => array(
                'table' => 'properties', 'singular' => 'property', 'perm' => 'properties',
                'status_field' => 'status', 'statuses' => array('available', 'pending', 'sold', 'rented', 'off_market'),
                'assign_field' => 'listing_agent_id', 'email' => false
            ),
            'deals' => array(
                'table' => 'deals', 'singular' => 'deal', 'perm' => 'deals',
                'status_field' => 'stage',
                'statuses' => array('lead', 'qualified', 'viewing', 'offer', 'negotiation', 'contract', 'closed_won', 'closed_lost'),
                'assign_field' => 'assigned_to', 'email' => false
            ),
            'tasks' => array(
                'table' => 'tasks', 'singular' => 'task', 'perm' => 'tasks',
                'status_field' => 'status', 'statuses' => array('pending', 'in_progress', 'completed', 'cancelled'),
                'assign_field' => 'assigned_to', 'email' => false
            )
        );
        return isset($config[$entityType]) ? $config[$entityType] : null;
    }

    /**
     * Normalise an id list: positive unique ints, capped
     * @param mixed $ids
     * @return array|false Ids, or false when more than MAX_IDS were sent
     */
    public static function normalizeIds($ids) {
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }
        if (!is_array($ids)) {
            return array();
        }
        $clean = array();
        foreach ($ids as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit(trim($id)))) && (int)$id > 0) {
                $clean[(int)$id] = (int)$id;
            }
        }
        if (count($clean) > self::MAX_IDS) {
            return false;
        }
        return array_values($clean);
    }

    /**
     * Load the tenant's records among $ids
     * @param string $table Whitelisted table
     * @param array $ids
     * @param int $tenantId
     * @return array id => row
     */
    private function loadRecords($table, $ids, $tenantId) {
        if (empty($ids)) {
            return array();
        }
        $placeholders = array();
        $params = array(':tenant_id' => (int)$tenantId);
        foreach (array_values($ids) as $i => $id) {
            $placeholders[] = ':id' . $i;
            $params[':id' . $i] = (int)$id;
        }
        $stmt = $this->db->prepare("SELECT * FROM `{$table}` WHERE tenant_id = :tenant_id AND id IN (" . implode(', ', $placeholders) . ")");
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int)$row['id']] = $row;
        }
        return $rows;
    }

    /**
     * @param string $table
     * @param int $id
     * @param int $tenantId
     * @return array|false
     */
    private function loadOne($table, $id, $tenantId) {
        $stmt = $this->db->prepare("SELECT * FROM `{$table}` WHERE id = :id AND tenant_id = :tenant_id LIMIT 1");
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Bulk delete
     * @param string $entityType
     * @param array $ids
     * @param int $tenantId
     * @param int $userId
     * @return array
     */
    public function bulkDelete($entityType, $ids, $tenantId, $userId) {
        $cfg = self::entityConfig($entityType);
        $records = $this->loadRecords($cfg['table'], $ids, $tenantId);

        $deleted = 0;
        $failed = 0;
        $stmt = $this->db->prepare("DELETE FROM `{$cfg['table']}` WHERE id = :id AND tenant_id = :tenant_id");
        foreach ($records as $id => $row) {
            try {
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
                $stmt->execute();
                if ($stmt->rowCount() > 0) {
                    $deleted++;
                    logActivity($userId, $tenantId, 'bulk_deleted', $cfg['singular'], $id);
                    dispatchEvent($tenantId, $cfg['singular'] . '.deleted', $row);
                } else {
                    $failed++;
                }
            } catch (PDOException $e) {
                error_log('Bulk delete error: ' . $e->getMessage());
                $failed++;
            }
        }

        return array(
            'success' => true,
            'deleted' => $deleted,
            'failed' => $failed,
            'not_found' => count($ids) - count($records),
            'total' => count($ids)
        );
    }

    /**
     * Bulk status (or deal stage) change
     * @param string $entityType
     * @param array $ids
     * @param string $status Must be one of the entity's statuses
     * @param int $tenantId
     * @param int $userId
     * @return array
     */
    public function bulkUpdateStatus($entityType, $ids, $status, $tenantId, $userId) {
        $cfg = self::entityConfig($entityType);
        if (!in_array($status, $cfg['statuses'], true)) {
            return array('success' => false, 'error' => 'Invalid status');
        }
        $field = $cfg['status_field'];
        $records = $this->loadRecords($cfg['table'], $ids, $tenantId);

        $set = "`{$field}` = :value";
        if ($entityType === 'tasks') {
            $set .= $status === 'completed'
                ? ", completed_at = COALESCE(completed_at, NOW())"
                : ", completed_at = NULL";
        } elseif ($entityType === 'deals' && ($status === 'closed_won' || $status === 'closed_lost')) {
            $set .= ", actual_close_date = COALESCE(actual_close_date, CURDATE())";
        }
        $stmt = $this->db->prepare("UPDATE `{$cfg['table']}` SET {$set} WHERE id = :id AND tenant_id = :tenant_id");

        $updated = 0;
        $unchanged = 0;
        $failed = 0;
        foreach ($records as $id => $row) {
            if ((string)$row[$field] === $status) {
                $unchanged++;
                continue;
            }
            try {
                $stmt->bindValue(':value', $status);
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
                $stmt->execute();
                $updated++;
                logActivity($userId, $tenantId, 'bulk_status_changed', $cfg['singular'], $id);
                $this->dispatchStatusEvents($entityType, $tenantId, $row, $id, $status);
            } catch (PDOException $e) {
                error_log('Bulk status update error: ' . $e->getMessage());
                $failed++;
            }
        }

        return array(
            'success' => true,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'failed' => $failed,
            'not_found' => count($ids) - count($records),
            'total' => count($ids)
        );
    }

    /**
     * Dispatch the events for a status/stage change of one record
     */
    private function dispatchStatusEvents($entityType, $tenantId, $oldRow, $id, $newStatus) {
        $cfg = self::entityConfig($entityType);
        $fresh = $this->loadOne($cfg['table'], $id, $tenantId);
        if (!$fresh) {
            return;
        }
        switch ($entityType) {
            case 'leads':
                $fresh['old_status'] = $oldRow['status'];
                dispatchEvent($tenantId, 'lead.status_changed', $fresh);
                dispatchEvent($tenantId, 'lead.updated', $fresh);
                break;
            case 'deals':
                $fresh['old_stage'] = $oldRow['stage'];
                dispatchEvent($tenantId, 'deal.stage_changed', $fresh);
                if ($newStatus === 'closed_won') {
                    dispatchEvent($tenantId, 'deal.won', $fresh);
                } elseif ($newStatus === 'closed_lost') {
                    dispatchEvent($tenantId, 'deal.lost', $fresh);
                }
                dispatchEvent($tenantId, 'deal.updated', $fresh);
                break;
            case 'tasks':
                $fresh['old_status'] = $oldRow['status'];
                dispatchEvent($tenantId, 'task.updated', $fresh);
                if ($newStatus === 'completed') {
                    dispatchEvent($tenantId, 'task.completed', $fresh);
                }
                break;
            default:
                $fresh['old_status'] = $oldRow['status'];
                dispatchEvent($tenantId, $cfg['singular'] . '.updated', $fresh);
        }
    }

    /**
     * Whether a user is an active member of the tenant
     * @param int $userId
     * @param int $tenantId
     * @return array|false User row (id, first_name, last_name)
     */
    public function findTenantUser($userId, $tenantId) {
        $stmt = $this->db->prepare(
            "SELECT id, first_name, last_name, email FROM users
             WHERE id = :id AND tenant_id = :tenant_id AND status = 'active' LIMIT 1"
        );
        $stmt->bindValue(':id', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Bulk assign to a user of the same tenant (caller verifies the assignee)
     * @param string $entityType
     * @param array $ids
     * @param int $assigneeId
     * @param int $tenantId
     * @param int $userId Acting user
     * @return array
     */
    public function bulkAssign($entityType, $ids, $assigneeId, $tenantId, $userId) {
        $cfg = self::entityConfig($entityType);
        if (empty($cfg['assign_field'])) {
            return array('success' => false, 'error' => 'This record type cannot be assigned');
        }
        $field = $cfg['assign_field'];
        $records = $this->loadRecords($cfg['table'], $ids, $tenantId);
        $stmt = $this->db->prepare("UPDATE `{$cfg['table']}` SET `{$field}` = :value WHERE id = :id AND tenant_id = :tenant_id");

        $updated = 0;
        $unchanged = 0;
        $failed = 0;
        foreach ($records as $id => $row) {
            if ((int)$row[$field] === (int)$assigneeId) {
                $unchanged++;
                continue;
            }
            try {
                $stmt->bindValue(':value', (int)$assigneeId, PDO::PARAM_INT);
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
                $stmt->execute();
                $updated++;
                logActivity($userId, $tenantId, 'bulk_assigned', $cfg['singular'], $id);
                $fresh = $this->loadOne($cfg['table'], $id, $tenantId);
                if ($fresh) {
                    $fresh['old_' . $field] = $row[$field];
                    dispatchEvent($tenantId, $cfg['singular'] . '.updated', $fresh);
                }
            } catch (PDOException $e) {
                error_log('Bulk assign error: ' . $e->getMessage());
                $failed++;
            }
        }

        if ($updated > 0 && (int)$assigneeId !== (int)$userId) {
            createNotification(
                $tenantId,
                $assigneeId,
                $updated . ' ' . ($updated === 1 ? $cfg['singular'] : $entityType) . ' assigned to you',
                'Records were bulk-assigned to you.',
                $entityType . '/index',
                $cfg['singular'] === 'task' ? 'task' : ($cfg['singular'] === 'deal' ? 'deal' : 'lead')
            );
        }

        return array(
            'success' => true,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'failed' => $failed,
            'not_found' => count($ids) - count($records),
            'total' => count($ids)
        );
    }

    /**
     * Emails the user has sent through bulk email during the last hour
     * @param int $userId
     * @param int $tenantId
     * @return int
     */
    public function emailsSentLastHour($userId, $tenantId) {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM activity_logs
             WHERE user_id = :user_id AND tenant_id = :tenant_id AND action = 'bulk_email_sent'
               AND created_at >= (NOW() - INTERVAL 1 HOUR)"
        );
        $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Bulk email leads/clients. The message is plain text; {first_name} and
     * {last_name} placeholders are replaced per recipient.
     * @param string $entityType leads|clients
     * @param array $ids
     * @param string $subject
     * @param string $message
     * @param int $tenantId
     * @param int $userId
     * @return array
     */
    public function bulkEmail($entityType, $ids, $subject, $message, $tenantId, $userId) {
        $cfg = self::entityConfig($entityType);
        if (empty($cfg['email'])) {
            return array('success' => false, 'error' => 'Email is not available for this record type');
        }
        if (count($ids) > self::MAX_EMAILS_PER_REQUEST) {
            return array('success' => false, 'error' => 'You can email at most ' . self::MAX_EMAILS_PER_REQUEST . ' recipients at a time');
        }
        $remaining = self::MAX_EMAILS_PER_HOUR - $this->emailsSentLastHour($userId, $tenantId);
        if ($remaining <= 0) {
            return array('success' => false, 'error' => 'Hourly bulk email limit reached (' . self::MAX_EMAILS_PER_HOUR . '). Please try again later.', 'rate_limited' => true);
        }

        $subject = trim(preg_replace('/[\r\n]+/', ' ', (string)$subject));
        $records = $this->loadRecords($cfg['table'], $ids, $tenantId);

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $seen = array();
        foreach ($records as $id => $row) {
            $email = isset($row['email']) ? trim((string)$row['email']) : '';
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[strtolower($email)])) {
                $skipped++;
                continue;
            }
            if ($sent >= $remaining) {
                $skipped++;
                continue;
            }
            $seen[strtolower($email)] = true;

            $replacements = array(
                '{first_name}' => (string)$row['first_name'],
                '{last_name}' => (string)$row['last_name']
            );
            $personalSubject = strtr($subject, $replacements);
            $body = '<div style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.5;">'
                . nl2br(htmlspecialchars(strtr((string)$message, $replacements), ENT_QUOTES, 'UTF-8'))
                . '</div>';

            if (sendEmail($email, $personalSubject, $body)) {
                $sent++;
                logActivity($userId, $tenantId, 'bulk_email_sent', $cfg['singular'], $id);
            } else {
                $failed++;
            }
        }

        return array(
            'success' => true,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'not_found' => count($ids) - count($records),
            'total' => count($ids)
        );
    }

    /**
     * Convert leads to clients. Leads that already have a client are skipped.
     * @param array $ids Lead ids
     * @param int $tenantId
     * @param int $userId
     * @return array
     */
    public function bulkConvertLeads($ids, $tenantId, $userId) {
        $records = $this->loadRecords('leads', $ids, $tenantId);

        $existsStmt = $this->db->prepare("SELECT id FROM clients WHERE tenant_id = :tenant_id AND lead_id = :lead_id LIMIT 1");
        $insertStmt = $this->db->prepare(
            "INSERT INTO clients (tenant_id, lead_id, first_name, last_name, email, phone, client_type, status, notes)
             VALUES (:tenant_id, :lead_id, :first_name, :last_name, :email, :phone, :client_type, 'active', :notes)"
        );
        $statusStmt = $this->db->prepare("UPDATE leads SET status = 'won' WHERE id = :id AND tenant_id = :tenant_id");

        $typeMap = array('buy' => 'buyer', 'sell' => 'seller', 'rent' => 'tenant', 'lease' => 'tenant');

        $converted = 0;
        $skipped = 0;
        $failed = 0;
        $clientIds = array();
        foreach ($records as $id => $lead) {
            $existsStmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
            $existsStmt->bindValue(':lead_id', $id, PDO::PARAM_INT);
            $existsStmt->execute();
            if ($existsStmt->fetchColumn()) {
                $skipped++;
                continue;
            }

            try {
                $this->db->beginTransaction();
                $insertStmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
                $insertStmt->bindValue(':lead_id', $id, PDO::PARAM_INT);
                $insertStmt->bindValue(':first_name', $lead['first_name']);
                $insertStmt->bindValue(':last_name', $lead['last_name'] !== null ? $lead['last_name'] : '');
                $insertStmt->bindValue(':email', $lead['email']);
                $insertStmt->bindValue(':phone', $lead['phone']);
                $insertStmt->bindValue(':client_type', isset($typeMap[$lead['interest_type']]) ? $typeMap[$lead['interest_type']] : 'buyer');
                $insertStmt->bindValue(':notes', $lead['notes']);
                $insertStmt->execute();
                $clientId = (int)$this->db->lastInsertId();

                $statusStmt->bindValue(':id', $id, PDO::PARAM_INT);
                $statusStmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
                $statusStmt->execute();
                $this->db->commit();
            } catch (PDOException $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                error_log('Bulk convert error: ' . $e->getMessage());
                $failed++;
                continue;
            }

            $converted++;
            $clientIds[] = $clientId;
            logActivity($userId, $tenantId, 'converted', 'lead', $id);

            $client = $this->loadOne('clients', $clientId, $tenantId);
            if ($client) {
                dispatchEvent($tenantId, 'client.created', $client);
            }
            if ($lead['status'] !== 'won') {
                $fresh = $this->loadOne('leads', $id, $tenantId);
                if ($fresh) {
                    $fresh['old_status'] = $lead['status'];
                    dispatchEvent($tenantId, 'lead.status_changed', $fresh);
                    dispatchEvent($tenantId, 'lead.updated', $fresh);
                }
            }
        }

        return array(
            'success' => true,
            'converted' => $converted,
            'skipped' => $skipped,
            'failed' => $failed,
            'not_found' => count($ids) - count($records),
            'total' => count($ids),
            'client_ids' => $clientIds
        );
    }
}
