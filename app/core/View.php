<?php
// FILE: /app/core/View.php

/**
 * SplashEstate CRM - View Class
 * Helper methods for views
 */

class View {

    /**
     * Escape HTML output
     * @param string $string String to escape
     * @return string Escaped string
     */
    public static function escape($string) {
        if ($string === null) {
            return '';
        }
        return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Format date for display
     * @param string $date Date string
     * @param string $format Output format
     * @return string Formatted date
     */
    public static function formatDate($date, $format = 'Y-m-d H:i') {
        if (empty($date) || $date === '0000-00-00 00:00:00' || $date === '0000-00-00') {
            return '-';
        }
        $timestamp = strtotime($date);
        return $timestamp === false ? '-' : date($format, $timestamp);
    }

    /**
     * Format currency
     * @param float $amount Amount
     * @param string $currency Currency symbol
     * @return string Formatted currency
     */
    public static function formatCurrency($amount, $currency = '$') {
        return $currency . number_format((float)$amount, 2);
    }

    /**
     * Generate pagination HTML
     * @param int $currentPage Current page
     * @param int $totalPages Total pages
     * @param string $baseUrl Base URL for pagination links
     * @return string Pagination HTML
     */
    public static function pagination($currentPage, $totalPages, $baseUrl) {
        $currentPage = (int)$currentPage;
        $totalPages = (int)$totalPages;
        if ($totalPages <= 1) {
            return '';
        }

        // Keep current filters/sort in pagination links
        $query = $_GET;
        unset($query['url'], $query['page']);
        $separator = strpos($baseUrl, '?') === false ? '?' : '&';
        $prefix = $baseUrl . $separator . (empty($query) ? '' : http_build_query($query) . '&') . 'page=';
        $prefix = self::escape($prefix);

        $html = '<div class="pagination">';

        if ($currentPage > 1) {
            $html .= '<a href="' . $prefix . ($currentPage - 1) . '" class="page-link">&laquo; Previous</a>';
        }

        // Windowed page numbers: first, last, and 2 around the current page
        $last = 0;
        for ($i = 1; $i <= $totalPages; $i++) {
            if ($i !== 1 && $i !== $totalPages && abs($i - $currentPage) > 2) {
                continue;
            }
            if ($last && $i - $last > 1) {
                $html .= '<span class="page-link">&hellip;</span>';
            }
            $active = ($i === $currentPage) ? ' active' : '';
            $html .= '<a href="' . $prefix . $i . '" class="page-link' . $active . '">' . $i . '</a>';
            $last = $i;
        }

        if ($currentPage < $totalPages) {
            $html .= '<a href="' . $prefix . ($currentPage + 1) . '" class="page-link">Next &raquo;</a>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Truncate text
     * @param string $text Text to truncate
     * @param int $length Maximum length
     * @param string $suffix Suffix to append
     * @return string Truncated text
     */
    public static function truncate($text, $length = 100, $suffix = '...') {
        $text = (string)$text;
        if (function_exists('mb_strlen')) {
            return mb_strlen($text) <= $length ? $text : mb_substr($text, 0, $length) . $suffix;
        }
        return strlen($text) <= $length ? $text : substr($text, 0, $length) . $suffix;
    }

    /**
     * Get status badge HTML
     * @param string $status Status value
     * @return string HTML badge
     */
    public static function statusBadge($status) {
        $badges = array(
            'new' => 'badge-primary',
            'contacted' => 'badge-info',
            'qualified' => 'badge-success',
            'proposal' => 'badge-warning',
            'negotiation' => 'badge-warning',
            'won' => 'badge-success',
            'lost' => 'badge-danger',
            'active' => 'badge-success',
            'inactive' => 'badge-secondary',
            'pending' => 'badge-warning',
            'in_progress' => 'badge-info',
            'completed' => 'badge-success',
            'cancelled' => 'badge-danger',
            'available' => 'badge-success',
            'sold' => 'badge-secondary',
            'rented' => 'badge-secondary',
            'off_market' => 'badge-secondary',
            'closed_won' => 'badge-success',
            'closed_lost' => 'badge-danger',
            'failed' => 'badge-danger',
            'success' => 'badge-success',
            'paid' => 'badge-success',
            'overdue' => 'badge-danger',
            'urgent' => 'badge-danger',
            'high' => 'badge-warning',
            'medium' => 'badge-info',
            'low' => 'badge-secondary'
        );

        $status = (string)$status;
        $class = isset($badges[$status]) ? $badges[$status] : 'badge-secondary';
        $label = ucwords(str_replace('_', ' ', $status));
        return '<span class="badge ' . $class . '">' . self::escape($label) . '</span>';
    }
}
