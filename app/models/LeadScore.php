<?php
/**
 * Lead Score Model
 *
 * Manages lead scores and scoring history. lead_scores / lead_score_history
 * have no tenant_id: tenant scoping is done by joining leads. Single-lead
 * methods take a lead ID that callers must already have verified.
 */

require_once APP_PATH . '/core/Model.php';

class LeadScore extends Model
{
    protected $table = 'lead_scores';

    /** Grade thresholds (minimum score => grade), highest first */
    private static $gradeThresholds = array(80 => 'A', 60 => 'B', 40 => 'C', 20 => 'D');

    /** All grades from best to worst */
    public static function getGrades()
    {
        return array('A', 'B', 'C', 'D', 'F');
    }

    /**
     * Get score for a lead
     * @param int $leadId Lead ID
     * @return array|false
     */
    public function getByLead($leadId)
    {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE lead_id = :lead_id LIMIT 1");
        $stmt->bindValue(':lead_id', (int)$leadId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Get or create score record for a lead
     * @param int $leadId Lead ID
     * @return array|false
     */
    public function getOrCreate($leadId)
    {
        $score = $this->getByLead($leadId);
        if ($score) {
            return $score;
        }
        // INSERT IGNORE: a concurrent request may have created it (lead_id is UNIQUE)
        $stmt = $this->conn->prepare(
            "INSERT IGNORE INTO {$this->table} (lead_id, score, grade, last_calculated_at)
             VALUES (:lead_id, 0, NULL, NULL)"
        );
        $stmt->bindValue(':lead_id', (int)$leadId, PDO::PARAM_INT);
        $stmt->execute();
        return $this->getByLead($leadId);
    }

    /**
     * Update score for a lead; logs history when score or grade changes
     * (including the first calculation).
     * @param int $leadId Lead ID
     * @param int $newScore Score 0..100
     * @param array|null $breakdown Rule breakdown
     * @param string|null $changedBy Trigger (e.g. 'lead.updated', 'bulk_recalculate')
     * @param string|null $changeReason Human readable reason
     * @return bool
     */
    public function updateScore($leadId, $newScore, $breakdown = null, $changedBy = null, $changeReason = null)
    {
        $currentScore = $this->getOrCreate($leadId);
        if (!$currentScore) {
            return false;
        }

        $newScore = max(0, min(100, (int)$newScore));
        $newGrade = $this->calculateGrade($newScore);
        $isFirst = $currentScore['last_calculated_at'] === null;

        $updated = $this->updateById($currentScore['id'], array(
            'score' => $newScore,
            'grade' => $newGrade,
            'score_breakdown' => json_encode(is_array($breakdown) ? $breakdown : array()),
            'last_calculated_at' => date('Y-m-d H:i:s')
        ));

        if (!$updated) {
            return false;
        }

        if ($isFirst || (int)$currentScore['score'] !== $newScore || $currentScore['grade'] !== $newGrade) {
            $this->logHistory(
                $leadId,
                $isFirst ? null : (int)$currentScore['score'],
                $newScore,
                $isFirst ? null : $currentScore['grade'],
                $newGrade,
                $changedBy,
                $changeReason
            );
        }

        return true;
    }

    /**
     * Calculate letter grade from score (A>=80, B>=60, C>=40, D>=20, else F)
     * @param int $score
     * @return string
     */
    public function calculateGrade($score)
    {
        foreach (self::$gradeThresholds as $min => $grade) {
            if ($score >= $min) {
                return $grade;
            }
        }
        return 'F';
    }

    /**
     * Log score change history
     */
    private function logHistory($leadId, $oldScore, $newScore, $oldGrade, $newGrade, $changedBy = null, $changeReason = null)
    {
        $sql = "INSERT INTO lead_score_history
                (lead_id, old_score, new_score, old_grade, new_grade, changed_by, change_reason)
                VALUES (:lead_id, :old_score, :new_score, :old_grade, :new_grade, :changed_by, :change_reason)";

        $stmt = $this->conn->prepare($sql);
        return $stmt->execute(array(
            ':lead_id' => (int)$leadId,
            ':old_score' => $oldScore,
            ':new_score' => (int)$newScore,
            ':old_grade' => $oldGrade,
            ':new_grade' => $newGrade,
            ':changed_by' => $changedBy !== null ? substr((string)$changedBy, 0, 100) : null,
            ':change_reason' => $changeReason
        ));
    }

    /**
     * Get score history for a lead
     * @param int $leadId Lead ID
     * @param int $limit Max rows
     * @return array
     */
    public function getHistory($leadId, $limit = 50)
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM lead_score_history
             WHERE lead_id = :lead_id
             ORDER BY created_at DESC, id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':lead_id', (int)$leadId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, (int)$limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get top scoring leads for a tenant
     * @param int $tenantId Tenant ID
     * @param int $limit Max rows
     * @param string|null $minGrade Only leads with this grade or better (e.g. 'B' => A and B)
     * @return array
     */
    public function getTopLeads($tenantId, $limit = 10, $minGrade = null)
    {
        $grades = array();
        if ($minGrade !== null && in_array($minGrade, self::getGrades(), true)) {
            $grades = array_slice(self::getGrades(), 0, array_search($minGrade, self::getGrades(), true) + 1);
        }

        $sql = "SELECT ls.*, l.first_name, l.last_name, CONCAT(l.first_name, ' ', l.last_name) AS name,
                       l.email, l.phone, l.source, l.status
                FROM {$this->table} ls
                INNER JOIN leads l ON ls.lead_id = l.id
                WHERE l.tenant_id = :tenant_id";
        if (!empty($grades)) {
            // Grades come from a fixed whitelist, safe to inline
            $sql .= " AND ls.grade IN ('" . implode("','", $grades) . "')";
        }
        $sql .= " ORDER BY ls.score DESC, ls.last_calculated_at DESC LIMIT :limit";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, (int)$limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Leads of a tenant within a score range
     * @param int $tenantId Tenant ID
     * @param int $minScore Inclusive minimum
     * @param int $maxScore Inclusive maximum
     * @param int $limit Max rows
     * @param bool $openOnly Exclude won/lost leads
     * @param bool $lowestFirst Order ascending by score
     * @return array
     */
    public function getLeadsInScoreRange($tenantId, $minScore, $maxScore, $limit = 50, $openOnly = false, $lowestFirst = false)
    {
        $sql = "SELECT ls.*, l.first_name, l.last_name, CONCAT(l.first_name, ' ', l.last_name) AS name,
                       l.email, l.phone, l.source, l.status
                FROM {$this->table} ls
                INNER JOIN leads l ON ls.lead_id = l.id
                WHERE l.tenant_id = :tenant_id
                  AND ls.score BETWEEN :min_score AND :max_score";
        if ($openOnly) {
            $sql .= " AND l.status NOT IN ('won', 'lost')";
        }
        $sql .= $lowestFirst ? " ORDER BY ls.score ASC" : " ORDER BY ls.score DESC";
        $sql .= ", ls.last_calculated_at DESC LIMIT :limit";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':min_score', (int)$minScore, PDO::PARAM_INT);
        $stmt->bindValue(':max_score', (int)$maxScore, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, (int)$limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get score distribution for a tenant: one row per grade (A..F), zero-filled
     * @param int $tenantId Tenant ID
     * @return array
     */
    public function getDistribution($tenantId)
    {
        $sql = "SELECT ls.grade, COUNT(*) as count, AVG(ls.score) as avg_score,
                       MIN(ls.score) as min_score, MAX(ls.score) as max_score
                FROM {$this->table} ls
                INNER JOIN leads l ON ls.lead_id = l.id
                WHERE l.tenant_id = :tenant_id AND ls.grade IS NOT NULL
                GROUP BY ls.grade";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute(array(':tenant_id' => (int)$tenantId));

        $byGrade = array();
        foreach ($stmt->fetchAll() as $row) {
            $byGrade[$row['grade']] = $row;
        }
        $total = 0;
        foreach ($byGrade as $row) {
            $total += (int)$row['count'];
        }

        $out = array();
        foreach (self::getGrades() as $grade) {
            $row = isset($byGrade[$grade]) ? $byGrade[$grade] : null;
            $count = $row ? (int)$row['count'] : 0;
            $out[] = array(
                'grade' => $grade,
                'count' => $count,
                'percent' => $total > 0 ? round($count * 100 / $total, 1) : 0,
                'avg_score' => $row ? round((float)$row['avg_score'], 1) : 0,
                'min_score' => $row ? (int)$row['min_score'] : 0,
                'max_score' => $row ? (int)$row['max_score'] : 0
            );
        }
        return $out;
    }

    /**
     * Get scoring statistics for a tenant
     * @param int $tenantId Tenant ID
     * @return array
     */
    public function getStatistics($tenantId)
    {
        $sql = "SELECT
                    COUNT(ls.id) as total_scored_leads,
                    (SELECT COUNT(*) FROM leads WHERE tenant_id = :lead_tenant) as total_leads,
                    AVG(ls.score) as average_score,
                    MIN(ls.score) as min_score,
                    MAX(ls.score) as max_score,
                    MAX(ls.last_calculated_at) as last_calculated_at,
                    SUM(CASE WHEN ls.grade = 'A' THEN 1 ELSE 0 END) as grade_a,
                    SUM(CASE WHEN ls.grade = 'B' THEN 1 ELSE 0 END) as grade_b,
                    SUM(CASE WHEN ls.grade = 'C' THEN 1 ELSE 0 END) as grade_c,
                    SUM(CASE WHEN ls.grade = 'D' THEN 1 ELSE 0 END) as grade_d,
                    SUM(CASE WHEN ls.grade = 'F' THEN 1 ELSE 0 END) as grade_f
                FROM {$this->table} ls
                INNER JOIN leads l ON ls.lead_id = l.id
                WHERE l.tenant_id = :tenant_id";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute(array(':lead_tenant' => (int)$tenantId, ':tenant_id' => (int)$tenantId));
        $row = $stmt->fetch();

        $stats = array();
        foreach ($row as $key => $value) {
            if ($key === 'average_score') {
                $stats[$key] = $value !== null ? round((float)$value, 1) : 0;
            } elseif ($key === 'last_calculated_at') {
                $stats[$key] = $value;
            } else {
                $stats[$key] = (int)$value;
            }
        }
        return $stats;
    }

    /**
     * Conversion (won) rate by grade for a tenant
     * @param int $tenantId Tenant ID
     * @return array rows: grade, total_leads, won_leads, lost_leads, conversion_rate (A..F, zero-filled)
     */
    public function getConversionRatesByGrade($tenantId)
    {
        $sql = "SELECT ls.grade,
                       COUNT(*) as total_leads,
                       SUM(CASE WHEN l.status = 'won' THEN 1 ELSE 0 END) as won_leads,
                       SUM(CASE WHEN l.status = 'lost' THEN 1 ELSE 0 END) as lost_leads
                FROM {$this->table} ls
                INNER JOIN leads l ON ls.lead_id = l.id
                WHERE l.tenant_id = :tenant_id AND ls.grade IS NOT NULL
                GROUP BY ls.grade";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute(array(':tenant_id' => (int)$tenantId));

        $byGrade = array();
        foreach ($stmt->fetchAll() as $row) {
            $byGrade[$row['grade']] = $row;
        }
        $out = array();
        foreach (self::getGrades() as $grade) {
            $total = isset($byGrade[$grade]) ? (int)$byGrade[$grade]['total_leads'] : 0;
            $won = isset($byGrade[$grade]) ? (int)$byGrade[$grade]['won_leads'] : 0;
            $lost = isset($byGrade[$grade]) ? (int)$byGrade[$grade]['lost_leads'] : 0;
            $out[] = array(
                'grade' => $grade,
                'total_leads' => $total,
                'won_leads' => $won,
                'lost_leads' => $lost,
                'conversion_rate' => $total > 0 ? round($won * 100 / $total, 1) : 0
            );
        }
        return $out;
    }

    /**
     * Recalculate all scores for a tenant
     * @param int $tenantId Tenant ID
     * @return int Number of leads successfully scored
     */
    public function recalculateAll($tenantId)
    {
        require_once APP_PATH . '/helpers/LeadScoringEngine.php';
        $engine = new LeadScoringEngine();
        $result = $engine->bulkRecalculate($tenantId);
        return (int)$result['success'];
    }
}
