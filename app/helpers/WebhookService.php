<?php
// FILE: /app/helpers/WebhookService.php

/**
 * SplashEstate CRM - Webhook Service
 * Delivers application events to tenant-configured HTTP endpoints.
 *
 * Called synchronously for every application event (dispatchEvent()), so it is
 * built to be fast and safe:
 *  - short connect/total timeouts and an overall time budget per event
 *  - never throws; every failure is logged in webhook_logs / error_log
 *  - SSRF protection: only http/https, no redirects, and (outside development)
 *    hosts resolving to private/loopback/link-local/reserved/metadata addresses
 *    are rejected; the vetted IP is pinned for the request (no DNS rebinding)
 *
 * Request format (POST, JSON):
 *   {"id":"evt_…","event":"lead.created","tenant_id":1,"created_at":"2026-10-18T10:00:00+00:00",
 *    "timestamp":1760781600,"data":{…}}
 * Headers:
 *   X-Webhook-Event: lead.created
 *   X-Webhook-Delivery: evt_…            (stable across retries — use it to de-duplicate)
 *   X-Webhook-Timestamp: 1760781600      (time of this attempt)
 *   X-Webhook-Signature: t=1760781600,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>
 * Receivers verify with WebhookService::verifySignature($rawBody, $header, $secret).
 */

class WebhookService {

    const CONNECT_TIMEOUT = 3;      // seconds
    const TOTAL_TIMEOUT = 5;        // seconds per request
    const EVENT_TIME_BUDGET = 8;    // seconds for all endpoints of one event
    const MAX_RESPONSE_BYTES = 65536;
    const SIGNATURE_TOLERANCE = 300;

    private $webhookModel;
    private $logModel;
    private $allowPrivateNetworks;

    /** Keys never sent to third parties even if present in event data */
    private static $sensitiveKeys = array('password', 'password_hash', 'api_key', 'secret', 'token', 'token_hash', 'remember_token');

    public function __construct() {
        require_once APP_PATH . '/models/Webhook.php';
        require_once APP_PATH . '/models/WebhookLog.php';

        $this->webhookModel = new Webhook();
        $this->logModel = new WebhookLog();
        $this->allowPrivateNetworks = defined('APP_ENV') && APP_ENV === 'development';
    }

    /**
     * Allow/deny endpoints on private networks (default: allowed only when APP_ENV=development)
     * @param bool $allow
     */
    public function setAllowPrivateNetworks($allow) {
        $this->allowPrivateNetworks = (bool)$allow;
    }

    /**
     * Deliver an event to every active webhook of the tenant subscribed to it.
     * Never throws.
     * @param int $tenantId Tenant ID
     * @param string $eventType Event type (e.g. 'lead.created')
     * @param array $data Event data
     * @return bool True if every delivery succeeded (or there was nothing to deliver)
     */
    public function trigger($tenantId, $eventType, $data) {
        try {
            if (empty($tenantId) || !is_string($eventType) || $eventType === '') {
                return false;
            }

            $webhooks = $this->webhookModel->getActiveForEvent((int)$tenantId, $eventType);
            if (empty($webhooks)) {
                return true;
            }

            $payloadJson = $this->buildPayload((int)$tenantId, $eventType, $data);
            if ($payloadJson === false) {
                error_log("Webhook payload for {$eventType} could not be JSON-encoded");
                return false;
            }

            $started = microtime(true);
            $success = true;

            foreach ($webhooks as $webhook) {
                if (microtime(true) - $started > self::EVENT_TIME_BUDGET) {
                    // Out of time: record the delivery as failed so retryFailed() sends it later
                    $this->logModel->create(array(
                        'webhook_id' => $webhook['id'],
                        'event_type' => $eventType,
                        'payload' => $payloadJson,
                        'attempt' => 1,
                        'status' => 'failed',
                        'error_message' => 'Deferred: request time budget exhausted, will be retried'
                    ));
                    $success = false;
                    continue;
                }
                if (!$this->deliver($webhook, $eventType, $payloadJson, 1)) {
                    $success = false;
                }
            }

            return $success;
        } catch (Throwable $e) {
            error_log('Webhook trigger failed for ' . $eventType . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a "webhook.test" event to one webhook (logged like any delivery)
     * @param array $webhook Webhook row
     * @return array success, status_code, error, duration_ms
     */
    public function sendTest($webhook) {
        try {
            $payloadJson = $this->buildPayload((int)$webhook['tenant_id'], 'webhook.test', array(
                'message' => 'This is a test event from SplashEstate CRM',
                'webhook_id' => (int)$webhook['id']
            ));
            return $this->deliver($webhook, 'webhook.test', $payloadJson, 1, true);
        } catch (Throwable $e) {
            error_log('Webhook test failed: ' . $e->getMessage());
            return array('success' => false, 'status_code' => 0, 'error' => 'Internal error', 'duration_ms' => 0);
        }
    }

    /**
     * Build the JSON payload for an event
     * @param int $tenantId Tenant ID
     * @param string $eventType Event type
     * @param mixed $data Event data
     * @param string|null $eventId Event ID (generated when null)
     * @return string|false JSON
     */
    public function buildPayload($tenantId, $eventType, $data, $eventId = null) {
        $now = time();
        $payload = array(
            'id' => $eventId !== null ? (string)$eventId : 'evt_' . bin2hex(random_bytes(12)),
            'event' => (string)$eventType,
            'tenant_id' => (int)$tenantId,
            'created_at' => gmdate('c', $now),
            'timestamp' => $now,
            'data' => self::scrub(is_array($data) ? $data : array())
        );
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * Deliver a payload to one webhook and log the attempt.
     * @param array $webhook Webhook row (id, url, secret)
     * @param string $eventType Event type
     * @param string $payloadJson JSON payload
     * @param int $attempt Attempt number
     * @param bool $returnDetails Return the result array instead of a bool
     * @return bool|array
     */
    private function deliver($webhook, $eventType, $payloadJson, $attempt, $returnDetails = false) {
        $logId = $this->logModel->create(array(
            'webhook_id' => $webhook['id'],
            'event_type' => $eventType,
            'payload' => $payloadJson,
            'attempt' => (int)$attempt,
            'status' => 'pending'
        ));

        $decoded = json_decode($payloadJson, true);
        $eventId = isset($decoded['id']) ? $decoded['id'] : '';
        $result = $this->post($webhook['url'], $payloadJson, isset($webhook['secret']) ? $webhook['secret'] : '', $eventType, $eventId);

        if ($logId) {
            $update = array(
                'status' => $result['success'] ? 'success' : 'failed',
                'response_code' => $result['status_code'] > 0 ? $result['status_code'] : null,
                'response_body' => $result['body'] !== '' ? self::truncateUtf8($result['body'], 5000) : null,
                'error_message' => $result['success'] ? null : self::truncateUtf8($result['error'], 1000)
            );
            if ($result['success']) {
                $update['delivered_at'] = date('Y-m-d H:i:s');
            }
            try {
                $this->logModel->updateById($logId, $update);
            } catch (Throwable $e) {
                error_log('Webhook log update failed: ' . $e->getMessage());
            }
        }

        if ($returnDetails) {
            unset($result['body']);
            return $result;
        }
        return $result['success'];
    }

    /**
     * Retry failed deliveries that are due (exponential backoff, max_retries per webhook).
     * Called from cron.
     * @return array total, success, failed
     */
    public function retryFailed() {
        $results = array('total' => 0, 'success' => 0, 'failed' => 0);

        try {
            $failedLogs = $this->logModel->getFailedForRetry();
        } catch (Throwable $e) {
            error_log('Webhook retry query failed: ' . $e->getMessage());
            return $results;
        }

        foreach ($failedLogs as $log) {
            try {
                if (!$this->logModel->claimForRetry($log['id'])) {
                    continue; // claimed by a concurrent run
                }
                $results['total']++;

                $webhook = array(
                    'id' => $log['webhook_id'],
                    'url' => $log['url'],
                    'secret' => $log['secret']
                );
                // Re-send the original payload: same event id so receivers can de-duplicate
                $payloadJson = (string)$log['payload'];
                if ($payloadJson === '' || json_decode($payloadJson, true) === null) {
                    $payloadJson = $this->buildPayload((int)$log['tenant_id'], $log['event_type'], array());
                }

                if ($this->deliver($webhook, $log['event_type'], $payloadJson, (int)$log['attempt'] + 1)) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                }
            } catch (Throwable $e) {
                $results['failed']++;
                error_log('Webhook retry failed for log ' . $log['id'] . ': ' . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * POST a signed payload to an arbitrary URL with the same SSRF protection
     * (for workflow "send webhook" actions). Not logged in webhook_logs.
     * @param string $url Endpoint
     * @param string $eventType Event type
     * @param int $tenantId Tenant ID
     * @param array $data Event data
     * @param string $secret Optional signing secret
     * @return bool
     */
    public function sendToUrl($url, $eventType, $tenantId, $data, $secret = '') {
        try {
            $payloadJson = $this->buildPayload((int)$tenantId, $eventType, $data);
            $decoded = json_decode($payloadJson, true);
            $result = $this->post($url, $payloadJson, (string)$secret, $eventType, $decoded['id']);
            if (!$result['success']) {
                error_log('Webhook to URL failed: ' . $result['error']);
            }
            return $result['success'];
        } catch (Throwable $e) {
            error_log('Webhook to URL failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate a webhook URL and resolve the IP to connect to.
     * @param string $url URL
     * @param string|null $error Set to a human readable reason on failure
     * @return array|false array('host' => ..., 'port' => ..., 'ip' => ...|null) or false
     */
    public function validateUrl($url, &$error = null) {
        $error = null;
        $url = (string)$url;

        if ($url === '' || strlen($url) > 512 || filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('/[\s\x00-\x1F\x7F]/', $url)) {
            $error = 'Please enter a valid URL';
            return false;
        }

        $parts = parse_url($url);
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        if ($scheme !== 'http' && $scheme !== 'https') {
            $error = 'Only http:// and https:// URLs are allowed';
            return false;
        }
        if (empty($parts['host'])) {
            $error = 'The URL must include a host name';
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            $error = 'Credentials in the URL are not allowed; use the signing secret instead';
            return false;
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);

        if ($this->allowPrivateNetworks) {
            return array('host' => $host, 'port' => $port, 'ip' => null);
        }

        $ips = self::resolveHost($host);
        if (empty($ips)) {
            $error = 'The host name could not be resolved';
            return false;
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                $error = 'Webhook URLs may not point to private, loopback or link-local addresses';
                return false;
            }
        }

        return array('host' => $host, 'port' => $port, 'ip' => $ips[0]);
    }

    /**
     * Whether an IP address is publicly routable (not private, loopback,
     * link-local, CGNAT, reserved, multicast or cloud metadata)
     * @param string $ip IPv4 or IPv6 address
     * @return bool
     */
    public static function isPublicIp($ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);
            // IPv4-mapped / IPv4-compatible (::ffff:a.b.c.d, ::a.b.c.d): check the embedded IPv4
            if (substr($packed, 0, 10) === str_repeat("\0", 10) && (substr($packed, 10, 2) === "\xff\xff" || substr($packed, 10, 2) === "\0\0")) {
                $v4 = inet_ntop(substr($packed, 12));
                return $v4 !== false && self::isPublicIp($v4);
            }
            $first = ord($packed[0]);
            $second = ord($packed[1]);
            if ($packed === str_repeat("\0", 15) . "\1" || $packed === str_repeat("\0", 16)) {
                return false; // ::1, ::
            }
            if (($first & 0xfe) === 0xfc) {
                return false; // fc00::/7 unique local
            }
            if ($first === 0xfe && ($second & 0xc0) === 0x80) {
                return false; // fe80::/10 link-local
            }
            if ($first === 0xff) {
                return false; // multicast
            }
            if (substr($packed, 0, 2) === "\x20\x02" || substr($packed, 0, 4) === "\x20\x01\x0d\xb8" || substr($packed, 0, 2) === "\x00\x64") {
                return false; // 6to4, documentation, NAT64 can embed internal addresses
            }
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false; // 10/8, 172.16/12, 192.168/16, 0/8, 127/8, 169.254/16, 240/4
        }
        $long = ip2long($ip);
        $blocked = array(
            array('100.64.0.0', 10),   // carrier-grade NAT
            array('192.0.0.0', 24),    // IETF protocol assignments
            array('192.0.2.0', 24),    // documentation
            array('198.18.0.0', 15),   // benchmarking
            array('198.51.100.0', 24), // documentation
            array('203.0.113.0', 24),  // documentation
            array('224.0.0.0', 4),     // multicast
            array('169.254.0.0', 16),  // link-local / cloud metadata (169.254.169.254)
            array('127.0.0.0', 8),
            array('0.0.0.0', 8)
        );
        foreach ($blocked as $range) {
            $mask = -1 << (32 - $range[1]);
            if (($long & $mask) === (ip2long($range[0]) & $mask)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Resolve a host name (or IP literal) to its IPv4/IPv6 addresses
     * @param string $host Host
     * @return array IPs
     */
    private static function resolveHost($host) {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return array($host);
        }
        $ips = array();
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }
        return array_values(array_unique($ips));
    }

    /**
     * Compute the signature header value for a payload
     * @param string $payload Raw JSON body
     * @param string $secret Signing secret
     * @param int $timestamp Unix timestamp
     * @return string "t=<timestamp>,v1=<hex hmac>"
     */
    public static function signatureHeader($payload, $secret, $timestamp) {
        return 't=' . (int)$timestamp . ',v1=' . hash_hmac('sha256', (int)$timestamp . '.' . $payload, (string)$secret);
    }

    /**
     * Verify an X-Webhook-Signature header (for receivers and tests)
     * @param string $payload Raw request body
     * @param string $header Signature header ("t=…,v1=…")
     * @param string $secret Signing secret
     * @param int $tolerance Maximum age in seconds (0 = do not check)
     * @param int|null $now Current time (for tests)
     * @return bool Valid
     */
    public static function verifySignature($payload, $header, $secret, $tolerance = self::SIGNATURE_TOLERANCE, $now = null) {
        if (!is_string($header) || $header === '' || (string)$secret === '') {
            return false;
        }
        $timestamp = null;
        $signatures = array();
        foreach (explode(',', $header) as $element) {
            $pair = explode('=', trim($element), 2);
            if (count($pair) !== 2) {
                continue;
            }
            if ($pair[0] === 't' && ctype_digit($pair[1])) {
                $timestamp = (int)$pair[1];
            } elseif ($pair[0] === 'v1') {
                $signatures[] = $pair[1];
            }
        }
        if ($timestamp === null || empty($signatures)) {
            return false;
        }
        $now = $now === null ? time() : (int)$now;
        if ($tolerance > 0 && abs($now - $timestamp) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, (string)$secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }
        return false;
    }

    /**
     * POST a payload with SSRF checks and short timeouts. Never throws.
     * @return array success, status_code, body, error, duration_ms
     */
    private function post($url, $payloadJson, $secret, $eventType, $eventId) {
        $result = array('success' => false, 'status_code' => 0, 'body' => '', 'error' => '', 'duration_ms' => 0);

        $error = null;
        $target = $this->validateUrl($url, $error);
        if ($target === false) {
            $result['error'] = 'Blocked: ' . $error;
            return $result;
        }
        if (!function_exists('curl_init')) {
            $result['error'] = 'The PHP curl extension is not available';
            return $result;
        }

        $timestamp = time();
        $headers = array(
            'Content-Type: application/json',
            'User-Agent: SplashEstate-Webhook/1.0',
            'X-Webhook-Event: ' . preg_replace('/[^A-Za-z0-9._*-]/', '', $eventType),
            'X-Webhook-Delivery: ' . preg_replace('/[^A-Za-z0-9_]/', '', (string)$eventId),
            'X-Webhook-Timestamp: ' . $timestamp,
            'Expect:'
        );
        if ((string)$secret !== '') {
            $headers[] = 'X-Webhook-Signature: ' . self::signatureHeader($payloadJson, $secret, $timestamp);
        }

        $body = '';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TOTAL_TIMEOUT);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        if ($target['ip'] !== null) {
            // Pin the vetted address so a second DNS lookup cannot point elsewhere
            $ip = strpos($target['ip'], ':') !== false ? '[' . $target['ip'] . ']' : $target['ip'];
            curl_setopt($ch, CURLOPT_RESOLVE, array($target['host'] . ':' . $target['port'] . ':' . $ip));
        }
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($handle, $chunk) use (&$body) {
            $remaining = self::MAX_RESPONSE_BYTES - strlen($body);
            if ($remaining > 0) {
                $body .= substr($chunk, 0, $remaining);
            }
            return strlen($chunk); // keep reading (discarding) so the request completes
        });

        $started = microtime(true);
        curl_exec($ch);
        $result['duration_ms'] = (int)round((microtime(true) - $started) * 1000);
        $result['status_code'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $result['body'] = $body;
        if ($curlError !== '') {
            $result['error'] = $curlError;
        } elseif ($result['status_code'] >= 200 && $result['status_code'] < 300) {
            $result['success'] = true;
        } elseif ($result['status_code'] >= 300 && $result['status_code'] < 400) {
            $result['error'] = 'HTTP ' . $result['status_code'] . ' (redirects are not followed)';
        } else {
            $result['error'] = 'HTTP ' . $result['status_code'];
        }

        return $result;
    }

    /**
     * Remove credentials and secrets from event data before sending it out
     */
    private static function scrub($data) {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::$sensitiveKeys, true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = self::scrub($value);
            }
        }
        return $data;
    }

    /**
     * Truncate a string to at most $max bytes without splitting a UTF-8 character
     */
    private static function truncateUtf8($text, $max) {
        $text = (string)$text;
        if (function_exists('mb_convert_encoding')) {
            // Replace invalid byte sequences (utf8mb4 columns reject them)
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        if (strlen($text) <= $max) {
            return $text;
        }
        return function_exists('mb_strcut') ? mb_strcut($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
    }
}
