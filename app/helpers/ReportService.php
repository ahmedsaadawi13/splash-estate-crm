<?php
// FILE: /app/helpers/ReportService.php

/**
 * SplashEstate CRM - Report Service
 * Tenant-scoped reporting and analytics queries.
 *
 * Every public report method takes ($tenantId, $startDate, $endDate) where the
 * dates are validated Y-m-d strings (see normalizeDateRange()). All queries are
 * scoped by tenant_id and use unique placeholder names (native prepares).
 */

class ReportService {

    /** @var PDO */
    private $db;

    /** Lead pipeline order (lost is terminal and reported separately) */
    private static $leadStages = array('new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won');
    private static $leadStatuses = array('new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost');
    private static $dealStages = array('lead', 'qualified', 'viewing', 'offer', 'negotiation', 'contract', 'closed_won', 'closed_lost');
    private static $grades = array('A', 'B', 'C', 'D', 'F');

    /** SQL expression for the date a won deal closed */
    const DEAL_CLOSE_DATE = 'COALESCE(d.actual_close_date, DATE(d.updated_at), DATE(d.created_at))';

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Validate a report date range. Invalid/missing dates fall back to the
     * default window ending today; reversed ranges are swapped; ranges are
     * capped at 5 years.
     * @param mixed $startDate
     * @param mixed $endDate
     * @param int $defaultDays Default window length
     * @return array array($start, $end) as Y-m-d
     */
    public static function normalizeDateRange($startDate, $endDate, $defaultDays = 90) {
        $end = self::parseDate($endDate);
        if ($end === null) {
            $end = new DateTime('today');
        }
        $start = self::parseDate($startDate);
        if ($start === null) {
            $start = clone $end;
            $start->modify('-' . (int)$defaultDays . ' days');
        }
        if ($start > $end) {
            $tmp = $start;
            $start = $end;
            $end = $tmp;
        }
        $limit = clone $end;
        $limit->modify('-5 years');
        if ($start < $limit) {
            $start = $limit;
        }
        return array($start->format('Y-m-d'), $end->format('Y-m-d'));
    }

    /**
     * @param mixed $value
     * @return DateTime|null
     */
    private static function parseDate($value) {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $parts = explode('-', $value);
        if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
            return null;
        }
        return new DateTime($value);
    }

    /**
     * Run a query and return all rows (errors are logged, never shown)
     * @param string $sql
     * @param array $params
     * @param string $context
     * @return array
     */
    private function fetchAll($sql, $params, $context) {
        try {
            $stmt = $this->db->prepare($sql);
            foreach ($params as $name => $value) {
                $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Report error ({$context}): " . $e->getMessage());
            return array();
        }
    }

    /**
     * @param float $part
     * @param float $whole
     * @return float Percentage rounded to 1 decimal
     */
    private static function pct($part, $whole) {
        return $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
    }

    // ------------------------------------------------------------------
    // Sales funnel
    // ------------------------------------------------------------------

    /**
     * Lead sales funnel for leads created in the range.
     * "reached" = leads currently at this stage or further along (lost leads
     * are excluded because the stage they dropped out at is not recorded);
     * "stage_conversion" = reached / reached of the previous stage.
     * @return array array('stages' => [...], 'total' => n, 'won' => n, 'lost' => n, 'win_rate' => %, 'close_rate' => %)
     */
    public function getSalesFunnel($tenantId, $startDate, $endDate) {
        $rows = $this->fetchAll(
            "SELECT status, COUNT(*) AS cnt
             FROM leads
             WHERE tenant_id = :tenant_id AND created_at >= :start_date AND created_at <= :end_date
             GROUP BY status",
            array(':tenant_id' => (int)$tenantId, ':start_date' => $startDate . ' 00:00:00', ':end_date' => $endDate . ' 23:59:59'),
            'funnel'
        );

        $counts = array_fill_keys(self::$leadStatuses, 0);
        foreach ($rows as $row) {
            if ($row['status'] !== null && isset($counts[$row['status']])) {
                $counts[$row['status']] = (int)$row['cnt'];
            }
        }
        $total = array_sum($counts);

        $stages = array();
        $previousReached = null;
        $n = count(self::$leadStages);
        for ($i = 0; $i < $n; $i++) {
            $status = self::$leadStages[$i];
            $reached = 0;
            for ($j = $i; $j < $n; $j++) {
                $reached += $counts[self::$leadStages[$j]];
            }
            $stages[] = array(
                'status' => $status,
                'label' => ucfirst($status),
                'count' => $counts[$status],
                'percent_of_total' => self::pct($counts[$status], $total),
                'reached' => $reached,
                'stage_conversion' => $previousReached === null ? 100.0 : self::pct($reached, $previousReached)
            );
            $previousReached = $reached;
        }
        $stages[] = array(
            'status' => 'lost',
            'label' => 'Lost',
            'count' => $counts['lost'],
            'percent_of_total' => self::pct($counts['lost'], $total),
            'reached' => null,
            'stage_conversion' => null
        );

        $closed = $counts['won'] + $counts['lost'];
        return array(
            'stages' => $stages,
            'total' => $total,
            'won' => $counts['won'],
            'lost' => $counts['lost'],
            'open' => $total - $closed,
            'win_rate' => self::pct($counts['won'], $closed),
            'conversion_rate' => self::pct($counts['won'], $total)
        );
    }

    // ------------------------------------------------------------------
    // Deal pipeline
    // ------------------------------------------------------------------

    /**
     * Deals created in the range, grouped by stage (all stages, zero-filled).
     * weighted_value = SUM(deal_value * probability / 100)
     * @return array array('stages' => [...], 'totals' => [...])
     */
    public function getDealPipeline($tenantId, $startDate, $endDate) {
        $rows = $this->fetchAll(
            "SELECT d.stage,
                    COUNT(*) AS deal_count,
                    COALESCE(SUM(d.deal_value), 0) AS total_value,
                    COALESCE(SUM(COALESCE(d.deal_value, 0) * COALESCE(d.probability, 0) / 100), 0) AS weighted_value,
                    COALESCE(AVG(d.probability), 0) AS avg_probability
             FROM deals d
             WHERE d.tenant_id = :tenant_id AND d.created_at >= :start_date AND d.created_at <= :end_date
             GROUP BY d.stage",
            array(':tenant_id' => (int)$tenantId, ':start_date' => $startDate . ' 00:00:00', ':end_date' => $endDate . ' 23:59:59'),
            'pipeline'
        );

        $byStage = array();
        foreach ($rows as $row) {
            $byStage[$row['stage']] = $row;
        }

        $stages = array();
        $totals = array('deal_count' => 0, 'total_value' => 0.0, 'weighted_value' => 0.0,
            'open_count' => 0, 'open_value' => 0.0, 'open_weighted_value' => 0.0);
        foreach (self::$dealStages as $stage) {
            $row = isset($byStage[$stage]) ? $byStage[$stage] : null;
            $item = array(
                'stage' => $stage,
                'label' => ucwords(str_replace('_', ' ', $stage)),
                'deal_count' => $row ? (int)$row['deal_count'] : 0,
                'total_value' => $row ? round((float)$row['total_value'], 2) : 0.0,
                'weighted_value' => $row ? round((float)$row['weighted_value'], 2) : 0.0,
                'avg_probability' => $row ? round((float)$row['avg_probability'], 1) : 0.0
            );
            $stages[] = $item;
            $totals['deal_count'] += $item['deal_count'];
            $totals['total_value'] += $item['total_value'];
            $totals['weighted_value'] += $item['weighted_value'];
            if ($stage !== 'closed_won' && $stage !== 'closed_lost') {
                $totals['open_count'] += $item['deal_count'];
                $totals['open_value'] += $item['total_value'];
                $totals['open_weighted_value'] += $item['weighted_value'];
            }
        }

        return array('stages' => $stages, 'totals' => $totals);
    }

    // ------------------------------------------------------------------
    // Revenue
    // ------------------------------------------------------------------

    /**
     * Won-deal revenue and commission per month (by close date), zero-filled
     * for every month in the range.
     * @return array array('months' => [...], 'totals' => [...])
     */
    public function getRevenueByMonth($tenantId, $startDate, $endDate) {
        $closeDate = self::DEAL_CLOSE_DATE;
        $rows = $this->fetchAll(
            "SELECT DATE_FORMAT({$closeDate}, '%Y-%m') AS month,
                    COUNT(*) AS deal_count,
                    COALESCE(SUM(d.deal_value), 0) AS revenue,
                    COALESCE(SUM(d.commission), 0) AS commission
             FROM deals d
             WHERE d.tenant_id = :tenant_id
               AND d.stage = 'closed_won'
               AND {$closeDate} >= :start_date
               AND {$closeDate} <= :end_date
             GROUP BY month
             ORDER BY month ASC",
            array(':tenant_id' => (int)$tenantId, ':start_date' => $startDate, ':end_date' => $endDate),
            'revenue'
        );

        $byMonth = array();
        foreach ($rows as $row) {
            $byMonth[$row['month']] = $row;
        }

        $months = array();
        $totals = array('deal_count' => 0, 'revenue' => 0.0, 'commission' => 0.0);
        $cursor = new DateTime(substr($startDate, 0, 7) . '-01');
        $last = new DateTime(substr($endDate, 0, 7) . '-01');
        $guard = 0;
        while ($cursor <= $last && $guard++ < 120) {
            $key = $cursor->format('Y-m');
            $row = isset($byMonth[$key]) ? $byMonth[$key] : null;
            $item = array(
                'month' => $key,
                'label' => $cursor->format('M Y'),
                'deal_count' => $row ? (int)$row['deal_count'] : 0,
                'revenue' => $row ? round((float)$row['revenue'], 2) : 0.0,
                'commission' => $row ? round((float)$row['commission'], 2) : 0.0
            );
            $item['avg_deal_value'] = $item['deal_count'] > 0 ? round($item['revenue'] / $item['deal_count'], 2) : 0.0;
            $months[] = $item;
            $totals['deal_count'] += $item['deal_count'];
            $totals['revenue'] += $item['revenue'];
            $totals['commission'] += $item['commission'];
            $cursor->modify('+1 month');
        }

        return array('months' => $months, 'totals' => $totals);
    }

    // ------------------------------------------------------------------
    // Agent leaderboard
    // ------------------------------------------------------------------

    /**
     * Per-user performance. Each metric comes from its own aggregated derived
     * table, so joining several tables cannot multiply the sums.
     *   leads          = leads assigned to the user, created in range
     *   leads_won      = of those, status won
     *   deals_won      = closed_won deals assigned to the user, closed in range
     *   revenue/commission = sum over those won deals
     *   open_pipeline  = value of the user's open deals (current snapshot)
     *   tasks_completed = tasks assigned to the user completed in range
     * @return array Rows ordered by revenue, deals won, leads won
     */
    public function getAgentLeaderboard($tenantId, $startDate, $endDate) {
        $closeDate = self::DEAL_CLOSE_DATE;
        $startTs = $startDate . ' 00:00:00';
        $endTs = $endDate . ' 23:59:59';

        $rows = $this->fetchAll(
            "SELECT u.id,
                    CONCAT(u.first_name, ' ', u.last_name) AS agent_name,
                    u.role,
                    u.status,
                    COALESCE(lq.leads, 0) AS leads,
                    COALESCE(lq.leads_won, 0) AS leads_won,
                    COALESCE(dq.deals_won, 0) AS deals_won,
                    COALESCE(dq.revenue, 0) AS revenue,
                    COALESCE(dq.commission, 0) AS commission,
                    COALESCE(oq.open_deals, 0) AS open_deals,
                    COALESCE(oq.open_value, 0) AS open_value,
                    COALESCE(tq.tasks_completed, 0) AS tasks_completed
             FROM users u
             LEFT JOIN (
                 SELECT assigned_to, COUNT(*) AS leads, SUM(status = 'won') AS leads_won
                 FROM leads
                 WHERE tenant_id = :t_leads AND created_at >= :l_start AND created_at <= :l_end
                 GROUP BY assigned_to
             ) lq ON lq.assigned_to = u.id
             LEFT JOIN (
                 SELECT d.assigned_to, COUNT(*) AS deals_won, SUM(d.deal_value) AS revenue, SUM(d.commission) AS commission
                 FROM deals d
                 WHERE d.tenant_id = :t_deals AND d.stage = 'closed_won'
                   AND {$closeDate} >= :d_start AND {$closeDate} <= :d_end
                 GROUP BY d.assigned_to
             ) dq ON dq.assigned_to = u.id
             LEFT JOIN (
                 SELECT assigned_to, COUNT(*) AS open_deals, SUM(deal_value) AS open_value
                 FROM deals
                 WHERE tenant_id = :t_open AND stage NOT IN ('closed_won', 'closed_lost')
                 GROUP BY assigned_to
             ) oq ON oq.assigned_to = u.id
             LEFT JOIN (
                 SELECT assigned_to, COUNT(*) AS tasks_completed
                 FROM tasks
                 WHERE tenant_id = :t_tasks AND status = 'completed'
                   AND COALESCE(completed_at, updated_at) >= :k_start AND COALESCE(completed_at, updated_at) <= :k_end
                 GROUP BY assigned_to
             ) tq ON tq.assigned_to = u.id
             WHERE u.tenant_id = :t_users AND u.role IN ('agent', 'tenant_admin')
             ORDER BY revenue DESC, deals_won DESC, leads_won DESC, agent_name ASC",
            array(
                ':t_leads' => (int)$tenantId, ':l_start' => $startTs, ':l_end' => $endTs,
                ':t_deals' => (int)$tenantId, ':d_start' => $startDate, ':d_end' => $endDate,
                ':t_open' => (int)$tenantId,
                ':t_tasks' => (int)$tenantId, ':k_start' => $startTs, ':k_end' => $endTs,
                ':t_users' => (int)$tenantId
            ),
            'leaderboard'
        );

        $rank = 0;
        foreach ($rows as &$row) {
            $row['rank'] = ++$rank;
            $row['leads'] = (int)$row['leads'];
            $row['leads_won'] = (int)$row['leads_won'];
            $row['deals_won'] = (int)$row['deals_won'];
            $row['open_deals'] = (int)$row['open_deals'];
            $row['tasks_completed'] = (int)$row['tasks_completed'];
            $row['revenue'] = round((float)$row['revenue'], 2);
            $row['commission'] = round((float)$row['commission'], 2);
            $row['open_value'] = round((float)$row['open_value'], 2);
            $row['lead_conversion'] = self::pct($row['leads_won'], $row['leads']);
        }
        unset($row);

        return $rows;
    }

    // ------------------------------------------------------------------
    // Lead sources
    // ------------------------------------------------------------------

    /**
     * Lead source effectiveness for leads created in the range
     * @return array Rows: source, leads, won, lost, open, conversion_rate, win_rate, share
     */
    public function getLeadSourceEffectiveness($tenantId, $startDate, $endDate) {
        $rows = $this->fetchAll(
            "SELECT COALESCE(NULLIF(TRIM(source), ''), 'Unknown') AS source_name,
                    COUNT(*) AS leads,
                    SUM(status = 'won') AS won,
                    SUM(status = 'lost') AS lost
             FROM leads
             WHERE tenant_id = :tenant_id AND created_at >= :start_date AND created_at <= :end_date
             GROUP BY source_name
             ORDER BY leads DESC, source_name ASC",
            array(':tenant_id' => (int)$tenantId, ':start_date' => $startDate . ' 00:00:00', ':end_date' => $endDate . ' 23:59:59'),
            'sources'
        );

        $total = 0;
        foreach ($rows as $row) {
            $total += (int)$row['leads'];
        }

        $result = array();
        foreach ($rows as $row) {
            $leads = (int)$row['leads'];
            $won = (int)$row['won'];
            $lost = (int)$row['lost'];
            $result[] = array(
                'source' => $row['source_name'],
                'leads' => $leads,
                'won' => $won,
                'lost' => $lost,
                'open' => $leads - $won - $lost,
                'share' => self::pct($leads, $total),
                'conversion_rate' => self::pct($won, $leads),
                'win_rate' => self::pct($won, $won + $lost)
            );
        }
        return $result;
    }

    // ------------------------------------------------------------------
    // Lead score / grade distribution
    // ------------------------------------------------------------------

    /**
     * Grade distribution of leads created in the range (lead_scores has no
     * tenant_id, so it is scoped through the join to leads).
     * @return array array('grades' => [...], 'scored' => n, 'unscored' => n, 'avg_score' => x)
     */
    public function getLeadGradeDistribution($tenantId, $startDate, $endDate) {
        $rows = $this->fetchAll(
            "SELECT COALESCE(ls.grade, 'unscored') AS grade_key,
                    COUNT(*) AS leads,
                    AVG(ls.score) AS avg_score,
                    MIN(ls.score) AS min_score,
                    MAX(ls.score) AS max_score,
                    SUM(l.status = 'won') AS won
             FROM leads l
             LEFT JOIN lead_scores ls ON ls.lead_id = l.id
             WHERE l.tenant_id = :tenant_id AND l.created_at >= :start_date AND l.created_at <= :end_date
             GROUP BY grade_key",
            array(':tenant_id' => (int)$tenantId, ':start_date' => $startDate . ' 00:00:00', ':end_date' => $endDate . ' 23:59:59'),
            'grades'
        );

        $byGrade = array();
        $total = 0;
        foreach ($rows as $row) {
            $byGrade[$row['grade_key']] = $row;
            $total += (int)$row['leads'];
        }

        $grades = array();
        $scored = 0;
        $scoreSum = 0.0;
        foreach (self::$grades as $grade) {
            $row = isset($byGrade[$grade]) ? $byGrade[$grade] : null;
            $count = $row ? (int)$row['leads'] : 0;
            $won = $row ? (int)$row['won'] : 0;
            $grades[] = array(
                'grade' => $grade,
                'leads' => $count,
                'share' => self::pct($count, $total),
                'avg_score' => $row ? round((float)$row['avg_score'], 1) : null,
                'min_score' => $row ? (int)$row['min_score'] : null,
                'max_score' => $row ? (int)$row['max_score'] : null,
                'won' => $won,
                'conversion_rate' => self::pct($won, $count)
            );
            $scored += $count;
            if ($row) {
                $scoreSum += (float)$row['avg_score'] * $count;
            }
        }
        $unscored = isset($byGrade['unscored']) ? (int)$byGrade['unscored']['leads'] : 0;

        return array(
            'grades' => $grades,
            'scored' => $scored,
            'unscored' => $unscored,
            'total' => $total,
            'avg_score' => $scored > 0 ? round($scoreSum / $scored, 1) : null
        );
    }

    // ------------------------------------------------------------------
    // Properties
    // ------------------------------------------------------------------

    /**
     * Property listings created in the range, by type
     * @return array Rows: property_type, listings, avg_price, total_value, available, pending, sold, rented, off_market
     */
    public function getPropertyTypeDistribution($tenantId, $startDate, $endDate) {
        $rows = $this->fetchAll(
            "SELECT COALESCE(property_type, 'unspecified') AS property_type,
                    COUNT(*) AS listings,
                    COALESCE(AVG(price), 0) AS avg_price,
                    COALESCE(SUM(price), 0) AS total_value,
                    SUM(status = 'available') AS available,
                    SUM(status = 'pending') AS pending,
                    SUM(status = 'sold') AS sold,
                    SUM(status = 'rented') AS rented,
                    SUM(status = 'off_market') AS off_market
             FROM properties
             WHERE tenant_id = :tenant_id AND created_at >= :start_date AND created_at <= :end_date
             GROUP BY property_type
             ORDER BY listings DESC",
            array(':tenant_id' => (int)$tenantId, ':start_date' => $startDate . ' 00:00:00', ':end_date' => $endDate . ' 23:59:59'),
            'property types'
        );

        foreach ($rows as &$row) {
            foreach (array('listings', 'available', 'pending', 'sold', 'rented', 'off_market') as $k) {
                $row[$k] = (int)$row[$k];
            }
            $row['avg_price'] = round((float)$row['avg_price'], 2);
            $row['total_value'] = round((float)$row['total_value'], 2);
            $row['label'] = ucfirst($row['property_type']);
        }
        unset($row);
        return $rows;
    }

    /**
     * Property listings created in the range, by city (top 10)
     * @return array
     */
    public function getPropertyCityDistribution($tenantId, $startDate, $endDate) {
        return $this->fetchAll(
            "SELECT city, COUNT(*) AS listings, COALESCE(AVG(price), 0) AS avg_price,
                    SUM(status IN ('sold', 'rented')) AS closed
             FROM properties
             WHERE tenant_id = :tenant_id AND created_at >= :start_date AND created_at <= :end_date
             GROUP BY city
             ORDER BY listings DESC, city ASC
             LIMIT 10",
            array(':tenant_id' => (int)$tenantId, ':start_date' => $startDate . ' 00:00:00', ':end_date' => $endDate . ' 23:59:59'),
            'property cities'
        );
    }

    // ------------------------------------------------------------------
    // Tasks
    // ------------------------------------------------------------------

    /**
     * Task counts by status for tasks created in the range, plus overdue count
     * @return array array('by_status' => [status => n], 'overdue' => n, 'total' => n)
     */
    public function getTaskMetrics($tenantId, $startDate, $endDate) {
        $rows = $this->fetchAll(
            "SELECT status, COUNT(*) AS cnt,
                    SUM(status IN ('pending', 'in_progress') AND due_date IS NOT NULL AND due_date < NOW()) AS overdue
             FROM tasks
             WHERE tenant_id = :tenant_id AND created_at >= :start_date AND created_at <= :end_date
             GROUP BY status",
            array(':tenant_id' => (int)$tenantId, ':start_date' => $startDate . ' 00:00:00', ':end_date' => $endDate . ' 23:59:59'),
            'tasks'
        );

        $byStatus = array('pending' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0);
        $overdue = 0;
        foreach ($rows as $row) {
            if (isset($byStatus[$row['status']])) {
                $byStatus[$row['status']] = (int)$row['cnt'];
            }
            $overdue += (int)$row['overdue'];
        }
        return array('by_status' => $byStatus, 'overdue' => $overdue, 'total' => array_sum($byStatus));
    }

    // ------------------------------------------------------------------
    // Overview
    // ------------------------------------------------------------------

    /**
     * Everything the overview page needs
     * @return array
     */
    public function getOverview($tenantId, $startDate, $endDate) {
        $funnel = $this->getSalesFunnel($tenantId, $startDate, $endDate);
        $pipeline = $this->getDealPipeline($tenantId, $startDate, $endDate);
        $revenue = $this->getRevenueByMonth($tenantId, $startDate, $endDate);
        $tasks = $this->getTaskMetrics($tenantId, $startDate, $endDate);

        return array(
            'kpis' => array(
                'new_leads' => $funnel['total'],
                'leads_won' => $funnel['won'],
                'conversion_rate' => $funnel['conversion_rate'],
                'open_pipeline' => $pipeline['totals']['open_value'],
                'weighted_pipeline' => $pipeline['totals']['open_weighted_value'],
                'revenue' => $revenue['totals']['revenue'],
                'commission' => $revenue['totals']['commission'],
                'deals_won' => $revenue['totals']['deal_count'],
                'tasks_completed' => $tasks['by_status']['completed'],
                'tasks_overdue' => $tasks['overdue']
            ),
            'funnel' => $funnel,
            'pipeline' => $pipeline,
            'revenue' => $revenue,
            'agents' => array_slice($this->getAgentLeaderboard($tenantId, $startDate, $endDate), 0, 5),
            'sources' => $this->getLeadSourceEffectiveness($tenantId, $startDate, $endDate),
            'grades' => $this->getLeadGradeDistribution($tenantId, $startDate, $endDate),
            'tasks' => $tasks
        );
    }
}
