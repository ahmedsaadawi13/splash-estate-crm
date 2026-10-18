<?php
// FILE: /tests/cases/ApiTest.php
// REST API (/api/leads) over HTTP: API-key auth, tenant isolation, creation
// (which also fires the lead.created webhook). Uses the app server from HttpTest.

function test_api_key($tenantId) {
    $stmt = Database::getInstance()->getConnection()->prepare("SELECT api_key FROM tenants WHERE id = :id");
    $stmt->execute(array(':id' => (int)$tenantId));
    return (string)$stmt->fetchColumn();
}

$suite->add('API', 'requests without a valid API key are rejected', function () {
    $client = new TestHttpClient(test_app_server());
    assert_equals(401, $client->get('api/leads')['status'], 'no key');
    assert_equals(401, $client->request('GET', 'api/leads', null, array('X-API-Key: nope'))['status'], 'unknown key');
    assert_equals(400, $client->get('api/leads?api_key=' . urlencode(test_api_key(1)))['status'], 'key in URL refused');
    assert_equals(404, $client->request('GET', 'api/nonexistent', null, array('X-API-Key: ' . test_api_key(1)))['status']);
}, 'depends on LeadsApiController');

$suite->add('API', 'leads are tenant scoped', function () {
    $client = new TestHttpClient(test_app_server());
    $headers = array('X-API-Key: ' . test_api_key(1));

    $list = $client->request('GET', 'api/leads?per_page=100', null, $headers);
    assert_equals(200, $list['status'], $list['body']);
    $data = json_decode($list['body'], true);
    $ids = array_column($data['data']['leads'], 'id');
    $tenantTwoIds = array_map('intval', Database::getInstance()->getConnection()->query("SELECT id FROM leads WHERE tenant_id = 2")->fetchAll(PDO::FETCH_COLUMN));
    assert_true(count($ids) > 0, 'tenant 1 sees its leads');
    assert_equals(array(), array_values(array_intersect($ids, $tenantTwoIds)), 'no tenant 2 leads in the list');

    $foreign = $client->request('GET', 'api/leads/show/' . $tenantTwoIds[0], null, $headers);
    assert_equals(404, $foreign['status'], 'tenant 2 lead is invisible to tenant 1');
}, 'depends on LeadsApiController');

$suite->add('API', 'creating a lead validates input and fires the lead.created webhook', function () {
    list($base, $log) = test_webhook_receiver();
    $pdo = Database::getInstance()->getConnection();
    $pdo->exec("DELETE FROM webhooks");
    // Give tenant 1 a current subscription so the lead quota allows creation
    (new Subscription())->changePlan(1, 3, 'active', null, 1);
    $hookId = test_create_webhook(1, $base . '/ok', 'lead.created');

    $client = new TestHttpClient(test_app_server());
    $headers = array('X-API-Key: ' . test_api_key(1), 'Content-Type: application/json');

    $invalid = $client->request('POST', 'api/leads', '{"first_name":""}', $headers);
    assert_equals(422, $invalid['status'], $invalid['body']);

    $created = $client->request('POST', 'api/leads', json_encode(array('first_name' => 'Api', 'last_name' => 'Hook', 'email' => 'api.hook@example.test')), $headers);
    assert_true(in_array($created['status'], array(200, 201), true), 'created: ' . $created['body']);

    $logs = test_logs_for($hookId);
    assert_equals(1, count($logs), 'webhook delivery logged');
    assert_equals('success', $logs[0]['status']);
    $payload = json_decode($logs[0]['payload'], true);
    assert_equals('lead.created', $payload['event']);
    assert_equals('Api', $payload['data']['first_name']);
    $pdo->exec("DELETE FROM webhooks");
}, 'depends on LeadsApiController');
