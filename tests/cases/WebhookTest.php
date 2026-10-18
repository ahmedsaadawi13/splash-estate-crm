<?php
// FILE: /tests/cases/WebhookTest.php
// Outgoing webhooks: event matching, signatures, SSRF protection, delivery
// logging through dispatchEvent(), retries.

require_once APP_PATH . '/models/Webhook.php';
require_once APP_PATH . '/models/WebhookLog.php';
require_once APP_PATH . '/helpers/WebhookService.php';

/**
 * Start (once) a local receiver: /ok -> 200, /fail -> 500, /redirect -> 302, /slow -> sleeps 10s.
 * Requests are appended as JSON lines to the returned log file.
 * @return array array(baseUrl, logFile)
 */
function test_webhook_receiver() {
    static $receiver = null;
    if ($receiver !== null) {
        return $receiver;
    }
    $dir = TEST_TMP_DIR . '/receiver';
    @mkdir($dir, 0777, true);
    $log = $dir . '/requests.log';
    file_put_contents($dir . '/index.php', '<?php
$headers = function_exists("getallheaders") ? getallheaders() : array();
file_put_contents(' . var_export($log, true) . ', json_encode(array("uri" => $_SERVER["REQUEST_URI"], "headers" => $headers, "body" => file_get_contents("php://input"))) . "\n", FILE_APPEND | LOCK_EX);
$uri = $_SERVER["REQUEST_URI"];
if (strpos($uri, "/fail") === 0) { http_response_code(500); echo "boom"; exit; }
if (strpos($uri, "/redirect") === 0) { header("Location: http://169.254.169.254/latest/meta-data/"); http_response_code(302); exit; }
if (strpos($uri, "/slow") === 0) { sleep(10); }
echo "ok";
');
    $port = TestServer::startPhpServer($dir, $dir . '/index.php');
    $receiver = array('http://127.0.0.1:' . $port, $log);
    return $receiver;
}

function test_last_request($log) {
    $lines = file_exists($log) ? file($log, FILE_IGNORE_NEW_LINES) : array();
    return empty($lines) ? null : json_decode(end($lines), true);
}

function test_create_webhook($tenantId, $url, $events, $extra = array()) {
    $model = new Webhook();
    return (int)$model->create(array_merge(array(
        'tenant_id' => $tenantId,
        'name' => 'Test hook ' . bin2hex(random_bytes(3)),
        'url' => $url,
        'secret' => 'whsec_test_' . bin2hex(random_bytes(8)),
        'events' => $events,
        'status' => 'active',
        'retry_enabled' => 1,
        'max_retries' => 3
    ), $extra));
}

function test_clear_webhooks() {
    Database::getInstance()->getConnection()->exec("DELETE FROM webhooks");
}

function test_logs_for($webhookId) {
    $stmt = Database::getInstance()->getConnection()->prepare("SELECT * FROM webhook_logs WHERE webhook_id = :id ORDER BY id ASC");
    $stmt->execute(array(':id' => $webhookId));
    return $stmt->fetchAll();
}

$suite->add('Webhooks', 'getActiveForEvent() matches whole event names, wildcard, status and tenant', function () {
    test_clear_webhooks();
    $exact = test_create_webhook(1, 'https://example.com/a', 'lead.created,deal.won');
    $prefix = test_create_webhook(1, 'https://example.com/b', 'lead.created_x,lead.created.extra');
    $wildcard = test_create_webhook(1, 'https://example.com/c', '*');
    $inactive = test_create_webhook(1, 'https://example.com/d', 'lead.created', array('status' => 'inactive'));
    $otherTenant = test_create_webhook(2, 'https://example.com/e', 'lead.created');
    $spaced = test_create_webhook(1, 'https://example.com/f', 'deal.won, lead.created');

    $model = new Webhook();
    $ids = array_map('intval', array_column($model->getActiveForEvent(1, 'lead.created'), 'id'));
    sort($ids);
    assert_equals(array($exact, $wildcard, $spaced), $ids);
    assert_not_contains($prefix, $ids, 'no prefix/LIKE false positive');
    assert_not_contains($inactive, $ids);
    assert_not_contains($otherTenant, $ids);

    $ids = array_map('intval', array_column($model->getActiveForEvent(1, 'task.deleted'), 'id'));
    assert_equals(array($wildcard), $ids);
    test_clear_webhooks();
});

$suite->add('Webhooks', 'normalizeEvents() keeps known events; * supersedes', function () {
    $model = new Webhook();
    assert_equals('lead.created,deal.won', $model->normalizeEvents(array('lead.created', 'bogus.event', 'deal.won', 'lead.created', array('x'))));
    assert_equals('*', $model->normalizeEvents(array('lead.created', '*')));
    assert_equals('', $model->normalizeEvents('lead.created'));
    assert_equals('', $model->normalizeEvents(array('lead.created_x')));
});

$suite->add('Webhooks', 'secrets are masked for display', function () {
    $masked = Webhook::maskSecret('whsec_0123456789abcdef0123456789abcdef');
    assert_not_contains('0123456789abcdef0123', $masked);
    assert_contains('cdef', $masked);
    assert_equals('', Webhook::maskSecret(null));
});

$suite->add('Webhooks', 'HMAC-SHA256 signature with timestamp verifies and rejects tampering', function () {
    $payload = '{"id":"evt_1","event":"lead.created"}';
    $now = 1700000000;
    $header = WebhookService::signatureHeader($payload, 'whsec_abc', $now);
    assert_equals('t=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $payload, 'whsec_abc'), $header);
    assert_true(WebhookService::verifySignature($payload, $header, 'whsec_abc', 300, $now + 10));
    assert_false(WebhookService::verifySignature($payload . ' ', $header, 'whsec_abc', 300, $now), 'tampered body');
    assert_false(WebhookService::verifySignature($payload, $header, 'whsec_other', 300, $now), 'wrong secret');
    assert_false(WebhookService::verifySignature($payload, $header, 'whsec_abc', 300, $now + 301), 'too old');
    assert_false(WebhookService::verifySignature($payload, 'v1=abc', 'whsec_abc', 300, $now), 'no timestamp');
    assert_false(WebhookService::verifySignature($payload, $header, '', 300, $now), 'empty secret');
});

$suite->add('Webhooks', 'isPublicIp() blocks private, loopback, link-local and metadata ranges', function () {
    foreach (array('127.0.0.1', '10.1.2.3', '172.16.5.4', '172.31.255.255', '192.168.1.1', '169.254.169.254', '100.64.0.1',
                   '0.0.0.0', '224.0.0.1', '::1', '::', 'fe80::1', 'fc00::1', 'fd12:3456::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1',
                   'not-an-ip') as $ip) {
        assert_false(WebhookService::isPublicIp($ip), $ip . ' must be blocked');
    }
    foreach (array('8.8.8.8', '1.1.1.1', '172.32.0.1', '2606:4700:4700::1111') as $ip) {
        assert_true(WebhookService::isPublicIp($ip), $ip . ' is public');
    }
});

$suite->add('Webhooks', 'validateUrl() enforces http(s), no credentials, no internal hosts (production mode)', function () {
    $service = new WebhookService();
    $service->setAllowPrivateNetworks(false);
    $error = null;
    foreach (array('ftp://example.com/x', 'file:///etc/passwd', 'gopher://127.0.0.1:6379/_x', 'http://127.0.0.1/hook', 'http://localhost:8080/',
                   'http://169.254.169.254/latest/meta-data/', 'http://10.0.0.5/', 'http://[::1]/', 'http://user:pass@8.8.8.8/',
                   'http://0x7f000001/', 'javascript:alert(1)', 'http://exa mple.com/') as $url) {
        assert_false($service->validateUrl($url, $error), $url . ' must be rejected');
        assert_true(is_string($error) && $error !== '', 'error message for ' . $url);
    }
    $ok = $service->validateUrl('https://8.8.8.8/hooks', $error);
    assert_equals('8.8.8.8', $ok['ip'], 'resolved IP is pinned');

    $dev = new WebhookService();
    $dev->setAllowPrivateNetworks(true);
    assert_true(is_array($dev->validateUrl('http://127.0.0.1:9/hook', $error)), 'development allows local endpoints');
    assert_false($dev->validateUrl('ftp://127.0.0.1/', $error), 'scheme still enforced in development');
});

$suite->add('Webhooks', 'delivery is signed and carries event id, type, tenant, timestamp, data', function () {
    list($base, $log) = test_webhook_receiver();
    test_clear_webhooks();
    $id = test_create_webhook(1, $base . '/ok', 'lead.created');
    $webhook = (new Webhook())->getById($id, 1);

    $service = new WebhookService();
    assert_true($service->trigger(1, 'lead.created', array('id' => 42, 'first_name' => 'Ada', 'password' => 'secret!')));

    $request = test_last_request($log);
    assert_true(is_array($request), 'receiver got a request');
    $payload = json_decode($request['body'], true);
    assert_equals('lead.created', $payload['event']);
    assert_equals(1, $payload['tenant_id']);
    assert_equals(42, $payload['data']['id']);
    assert_true(strpos($payload['id'], 'evt_') === 0, 'event id');
    assert_true(is_int($payload['timestamp']));
    assert_false(isset($payload['data']['password']), 'secrets are scrubbed from payloads');

    $headers = array_change_key_case($request['headers'], CASE_LOWER);
    assert_equals('lead.created', $headers['x-webhook-event']);
    assert_equals($payload['id'], $headers['x-webhook-delivery']);
    assert_true(WebhookService::verifySignature($request['body'], $headers['x-webhook-signature'], $webhook['secret']), 'signature verifies with the webhook secret');

    $logs = test_logs_for($id);
    assert_equals(1, count($logs));
    assert_equals('success', $logs[0]['status']);
    assert_equals(200, (int)$logs[0]['response_code']);
    assert_true($logs[0]['delivered_at'] !== null);
});

$suite->add('Webhooks', 'dispatchEvent() logs a failed delivery to an unreachable endpoint quickly', function () {
    test_clear_webhooks();
    // Non-routable address: the connect attempt fails or times out (3s connect timeout)
    $id = test_create_webhook(1, 'http://10.255.255.1:81/hook', 'task.deleted');
    $started = microtime(true);
    dispatchEvent(1, 'task.deleted', array('id' => 7, 'title' => 'Gone'));
    $elapsed = microtime(true) - $started;

    assert_true($elapsed < 6, sprintf('request must not hang (took %.1fs)', $elapsed));
    $logs = test_logs_for($id);
    assert_equals(1, count($logs), 'one log row');
    assert_equals('failed', $logs[0]['status']);
    assert_true($logs[0]['error_message'] !== null && $logs[0]['error_message'] !== '', 'error recorded');
    $payload = json_decode($logs[0]['payload'], true);
    assert_equals('task.deleted', $payload['event']);
    assert_equals(1, $payload['tenant_id']);
    assert_equals(7, $payload['data']['id']);
});

$suite->add('Webhooks', 'redirects are not followed; non-2xx responses fail', function () {
    list($base) = test_webhook_receiver();
    test_clear_webhooks();
    $redirect = test_create_webhook(1, $base . '/redirect', 'deal.won');
    $fail = test_create_webhook(1, $base . '/fail', 'deal.won');
    $service = new WebhookService();
    assert_false($service->trigger(1, 'deal.won', array('id' => 1)));

    $redirectLog = test_logs_for($redirect);
    assert_equals('failed', $redirectLog[0]['status']);
    assert_equals(302, (int)$redirectLog[0]['response_code']);
    assert_contains('redirects are not followed', $redirectLog[0]['error_message']);

    $failLog = test_logs_for($fail);
    assert_equals(500, (int)$failLog[0]['response_code']);
    assert_equals('boom', $failLog[0]['response_body']);
});

$suite->add('Webhooks', 'production mode refuses to deliver to internal addresses', function () {
    list($base, $log) = test_webhook_receiver();
    test_clear_webhooks();
    $id = test_create_webhook(1, $base . '/ok', 'client.created');
    $before = file_exists($log) ? count(file($log)) : 0;

    $service = new WebhookService();
    $service->setAllowPrivateNetworks(false);
    assert_false($service->trigger(1, 'client.created', array('id' => 3)));

    $after = file_exists($log) ? count(file($log)) : 0;
    assert_equals($before, $after, 'no request reached the internal endpoint');
    $logs = test_logs_for($id);
    assert_contains('Blocked', $logs[0]['error_message']);
});

$suite->add('Webhooks', 'retryFailed() honours backoff and max_retries and re-sends the same event id', function () {
    list($base, $log) = test_webhook_receiver();
    test_clear_webhooks();
    $pdo = Database::getInstance()->getConnection();
    $id = test_create_webhook(1, $base . '/ok', 'lead.updated', array('max_retries' => 3));
    $service = new WebhookService();
    $payload = $service->buildPayload(1, 'lead.updated', array('id' => 5), 'evt_retry_test');

    $logModel = new WebhookLog();
    $due = (int)$logModel->create(array('webhook_id' => $id, 'event_type' => 'lead.updated', 'payload' => $payload, 'attempt' => 1, 'status' => 'failed'));
    $notDue = (int)$logModel->create(array('webhook_id' => $id, 'event_type' => 'lead.updated', 'payload' => $payload, 'attempt' => 2, 'status' => 'failed'));
    $exhausted = (int)$logModel->create(array('webhook_id' => $id, 'event_type' => 'lead.updated', 'payload' => $payload, 'attempt' => 3, 'status' => 'failed'));
    // attempt 1 is due after 60s, attempt 2 only after 240s
    $pdo->exec("UPDATE webhook_logs SET created_at = DATE_SUB(NOW(), INTERVAL 2 MINUTE) WHERE id IN ({$due}, {$notDue})");
    $pdo->exec("UPDATE webhook_logs SET created_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id = {$exhausted}");

    $result = $service->retryFailed();
    assert_equals(array('total' => 1, 'success' => 1, 'failed' => 0), $result);

    $rows = array();
    foreach (test_logs_for($id) as $row) {
        $rows[(int)$row['id']] = $row;
    }
    assert_equals('retrying', $rows[$due]['status'], 'original attempt superseded');
    assert_equals('failed', $rows[$notDue]['status'], 'backoff not elapsed');
    assert_equals('failed', $rows[$exhausted]['status'], 'max_retries reached');
    $new = end($rows);
    assert_equals(2, (int)$new['attempt']);
    assert_equals('success', $new['status']);

    $request = test_last_request($log);
    $sent = json_decode($request['body'], true);
    assert_equals('evt_retry_test', $sent['id'], 'same event id on retry');

    assert_equals(array('total' => 0, 'success' => 0, 'failed' => 0), $service->retryFailed(), 'nothing left to retry');
});

$suite->add('Webhooks', 'trigger() never throws on odd input', function () {
    test_clear_webhooks();
    test_create_webhook(1, 'http://127.0.0.1:9/closed', '*');
    $service = new WebhookService();
    assert_false($service->trigger(1, 'lead.created', array('bad' => "\xB1\x31", 'nested' => array('x' => INF))));
    assert_false($service->trigger(0, 'lead.created', array()));
    assert_false($service->trigger(1, '', array()));
    test_clear_webhooks();
});
