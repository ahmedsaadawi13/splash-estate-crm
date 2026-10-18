<?php
/**
 * Lead Scoring Engine
 *
 * Calculates and manages lead scores based on configurable, tenant-scoped rules.
 * A lead's score is the sum of the points of every matching active rule
 * (rules may award negative points), clamped to 0..100, then graded
 * A >= 80, B >= 60, C >= 40, D >= 20, otherwise F.
 *
 * Methods that take only a lead ID trust the caller: they are used by
 * dispatchEvent() after the lead was loaded through a tenant-scoped query.
 * Controllers should pass a tenant ID to testRules() to enforce isolation.
 */

require_once APP_PATH . '/models/LeadScoringRule.php';
require_once APP_PATH . '/models/LeadScore.php';
require_once APP_PATH . '/models/Lead.php';

class LeadScoringEngine
{
    private $ruleModel;
    private $scoreModel;
    private $leadModel;

    /** Per-instance cache of active rules by tenant (bulk recalculation) */
    private $rulesCache = array();

    public function __construct()
    {
        $this->ruleModel = new LeadScoringRule();
        $this->scoreModel = new LeadScore();
        $this->leadModel = new Lead();
    }

    /**
     * Calculate and store the score for a lead
     *
     * @param int $leadId Lead ID
     * @param string|null $triggeredBy What triggered the calculation
     * @return bool Success status
     */
    public function calculateScore($leadId, $triggeredBy = 'auto')
    {
        $lead = $this->leadModel->findById((int)$leadId);
        if (!$lead) {
            return false;
        }

        $rules = $this->getActiveRules($lead['tenant_id']);
        if (empty($rules)) {
            return $this->scoreModel->updateScore($lead['id'], 0, array(), $triggeredBy, 'No active scoring rules');
        }

        $result = $this->scoreLead($lead, $rules);

        $reason = sprintf('Matched %d of %d rules (raw %d points)', count($result['breakdown']), count($rules), $result['raw_score']);
        return $this->scoreModel->updateScore($lead['id'], $result['score'], $result['breakdown'], $triggeredBy, $reason);
    }

    /**
     * Evaluate rules for a lead without saving
     * @param array $lead Lead row
     * @param array $rules Active rules
     * @return array score, raw_score, breakdown (matched rules)
     */
    private function scoreLead($lead, $rules)
    {
        $raw = 0;
        $breakdown = array();
        foreach ($rules as $rule) {
            if ($this->ruleModel->evaluate($rule, $lead)) {
                $points = (int)$rule['points'];
                $raw += $points;
                $breakdown[] = array(
                    'rule_id' => (int)$rule['id'],
                    'rule_name' => $rule['name'],
                    'rule_type' => $rule['rule_type'],
                    'field' => $rule['field_name'],
                    'points' => $points,
                    'matched' => true
                );
            }
        }
        return array(
            'score' => max(0, min(100, $raw)),
            'raw_score' => $raw,
            'breakdown' => $breakdown
        );
    }

    /**
     * @param int $tenantId
     * @return array Active rules of a tenant
     */
    private function getActiveRules($tenantId)
    {
        $key = (int)$tenantId;
        if (!isset($this->rulesCache[$key])) {
            $this->rulesCache[$key] = $this->ruleModel->getByTenant($key, 'active');
        }
        return $this->rulesCache[$key];
    }

    /**
     * Recalculate score when lead is updated
     *
     * @param int $leadId Lead ID
     * @param array $changedFields Fields that were changed
     * @return bool Success status
     */
    public function onLeadUpdate($leadId, $changedFields = array())
    {
        return $this->calculateScore($leadId, 'lead_update');
    }

    /**
     * Calculate initial score when lead is created
     *
     * @param int $leadId Lead ID
     * @return bool Success status
     */
    public function onLeadCreate($leadId)
    {
        return $this->calculateScore($leadId, 'lead_create');
    }

    /**
     * Get score for a lead with details
     *
     * @param int $leadId Lead ID
     * @return array|null Score row plus 'breakdown' (array), or null when never scored
     */
    public function getLeadScore($leadId)
    {
        $score = $this->scoreModel->getByLead($leadId);
        if (!$score || $score['grade'] === null) {
            return null;
        }

        $breakdown = !empty($score['score_breakdown']) ? json_decode($score['score_breakdown'], true) : array();
        $score['breakdown'] = is_array($breakdown) ? $breakdown : array();
        $score['score'] = (int)$score['score'];

        return $score;
    }

    /**
     * Get score history for a lead
     *
     * @param int $leadId Lead ID
     * @param int $limit Number of records to return
     * @return array Score history
     */
    public function getScoreHistory($leadId, $limit = 50)
    {
        return $this->scoreModel->getHistory($leadId, $limit);
    }

    /**
     * Get high-value leads (Grade A or B)
     *
     * @param int $tenantId Tenant ID
     * @param int $limit Number of leads to return
     * @return array High-value leads
     */
    public function getHighValueLeads($tenantId, $limit = 20)
    {
        return $this->scoreModel->getTopLeads($tenantId, $limit, 'B');
    }

    /**
     * Get qualified leads (minimum score)
     *
     * @param int $tenantId Tenant ID
     * @param int $minScore Minimum score threshold
     * @param int $limit Number of leads to return
     * @return array Qualified leads
     */
    public function getQualifiedLeads($tenantId, $minScore = 60, $limit = 50)
    {
        return $this->scoreModel->getLeadsInScoreRange($tenantId, $minScore, 100, $limit);
    }

    /**
     * Get open leads needing attention (low scores)
     *
     * @param int $tenantId Tenant ID
     * @param int $maxScore Maximum score threshold
     * @param int $limit Number of leads to return
     * @return array Leads needing attention
     */
    public function getNurturingLeads($tenantId, $maxScore = 40, $limit = 50)
    {
        return $this->scoreModel->getLeadsInScoreRange($tenantId, 0, $maxScore, $limit, true, true);
    }

    /**
     * Get scoring performance metrics
     *
     * @param int $tenantId Tenant ID
     * @return array statistics, distribution, rule_stats, conversion_rates
     */
    public function getPerformanceMetrics($tenantId)
    {
        return array(
            'statistics' => $this->scoreModel->getStatistics($tenantId),
            'distribution' => $this->scoreModel->getDistribution($tenantId),
            'rule_stats' => $this->ruleModel->getStatistics($tenantId),
            'conversion_rates' => $this->scoreModel->getConversionRatesByGrade($tenantId)
        );
    }

    /**
     * Bulk recalculate scores for all leads in a tenant
     *
     * @param int $tenantId Tenant ID
     * @return array total, success, failed, errors
     */
    public function bulkRecalculate($tenantId)
    {
        $stmt = $this->leadModel->getConnection()->prepare("SELECT id FROM leads WHERE tenant_id = :tenant_id ORDER BY id");
        $stmt->bindValue(':tenant_id', (int)$tenantId, PDO::PARAM_INT);
        $stmt->execute();
        $leadIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Fresh rules for this run
        unset($this->rulesCache[(int)$tenantId]);

        $success = 0;
        $failed = 0;
        $errors = array();

        foreach ($leadIds as $leadId) {
            try {
                if ($this->calculateScore((int)$leadId, 'bulk_recalculate')) {
                    $success++;
                } else {
                    $failed++;
                    $errors[] = "Lead #{$leadId}: calculation returned false";
                }
            } catch (Throwable $e) {
                $failed++;
                $errors[] = "Lead #{$leadId}: " . $e->getMessage();
                error_log("Lead scoring failed for lead #{$leadId}: " . $e->getMessage());
            }
        }

        return array(
            'total' => count($leadIds),
            'success' => $success,
            'failed' => $failed,
            'errors' => $errors
        );
    }

    /**
     * Test active scoring rules against a lead (nothing is saved)
     *
     * @param int $leadId Lead ID
     * @param int|null $tenantId When given, the lead must belong to this tenant
     * @return array|null lead, rules (rule, matched, points_awarded, reason), raw_score, total_score, grade
     */
    public function testRules($leadId, $tenantId = null)
    {
        $lead = $tenantId !== null
            ? $this->leadModel->getById((int)$leadId, (int)$tenantId)
            : $this->leadModel->findById((int)$leadId);
        if (!$lead) {
            return null;
        }

        $rules = $this->ruleModel->getByTenant($lead['tenant_id'], 'active');

        $results = array();
        $raw = 0;
        foreach ($rules as $rule) {
            $matches = $this->ruleModel->evaluate($rule, $lead);
            $points = $matches ? (int)$rule['points'] : 0;
            $raw += $points;
            $results[] = array(
                'rule' => $rule,
                'matched' => $matches,
                'points_awarded' => $points,
                'reason' => $this->getMatchReason($rule, $lead, $matches)
            );
        }

        $total = max(0, min(100, $raw));
        return array(
            'lead' => $lead,
            'rules' => $results,
            'raw_score' => $raw,
            'total_score' => $total,
            'grade' => $this->scoreModel->calculateGrade($total)
        );
    }

    /**
     * Get explanation for why a rule matched or didn't match
     *
     * @param array $rule Scoring rule
     * @param array $lead Lead data
     * @param bool $matched Whether the rule matched
     * @return string Explanation
     */
    private function getMatchReason($rule, $lead, $matched)
    {
        $field = $rule['field_name'];
        $operators = LeadScoringRule::getOperators();
        $operator = isset($operators[$rule['operator']]) ? strtolower(preg_replace('/\s*\(.*\)$/', '', $operators[$rule['operator']])) : $rule['operator'];
        $actual = isset($lead[$field]) && trim((string)$lead[$field]) !== '' ? '"' . $lead[$field] . '"' : '(empty)';

        $condition = $operator;
        if (!in_array($rule['operator'], array('is_empty', 'is_not_empty'), true)) {
            $condition .= ' "' . (string)$rule['field_value'] . '"';
        }

        if (!array_key_exists($field, $lead)) {
            return "Lead has no field '{$field}'";
        }

        return $matched
            ? "{$field} {$actual} {$condition}"
            : "{$field} {$actual} does not satisfy: {$condition}";
    }

    /**
     * Get recommended actions based on lead score
     *
     * @param int $leadId Lead ID
     * @return array Recommended actions
     */
    public function getRecommendedActions($leadId)
    {
        $score = $this->getLeadScore($leadId);
        if (!$score) {
            return array();
        }

        $recommendations = array();

        switch ($score['grade']) {
            case 'A':
                $recommendations[] = array(
                    'priority' => 'high',
                    'action' => 'immediate_contact',
                    'message' => 'High-value lead - Contact immediately'
                );
                $recommendations[] = array(
                    'priority' => 'high',
                    'action' => 'assign_senior',
                    'message' => 'Assign to senior agent or sales manager'
                );
                break;

            case 'B':
                $recommendations[] = array(
                    'priority' => 'medium',
                    'action' => 'schedule_call',
                    'message' => 'Good prospect - Schedule call within 24 hours'
                );
                break;

            case 'C':
                $recommendations[] = array(
                    'priority' => 'medium',
                    'action' => 'nurture',
                    'message' => 'Add to nurture campaign'
                );
                $recommendations[] = array(
                    'priority' => 'low',
                    'action' => 'send_info',
                    'message' => 'Send property information and follow up in 3 days'
                );
                break;

            case 'D':
            case 'F':
                $recommendations[] = array(
                    'priority' => 'low',
                    'action' => 'auto_nurture',
                    'message' => 'Add to automated nurture sequence'
                );
                $recommendations[] = array(
                    'priority' => 'low',
                    'action' => 'qualify',
                    'message' => 'Gather more information to improve score'
                );
                break;
        }

        return $recommendations;
    }
}
