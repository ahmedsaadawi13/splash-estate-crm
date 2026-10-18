<?php
// FILE: /tests/cases/HttpTest.php
// HTTP-level tests against a throw-away "php -S" instance of the app that
// uses the test database. Login-dependent tests rely on AuthController.

/**
 * Start (once) the application server for the test database
 * @return string Base URL
 */
function test_app_server() {
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $dir = TEST_TMP_DIR . '/app';
    @mkdir($dir, 0777, true);
    $router = $dir . '/router.php';
    $public = ROOT_PATH . '/public';
    $port = 0;
    // Port is only known after start; the router reads it from the request instead
    file_put_contents($router, '<?php
define("DB_NAME", ' . var_export(DB_NAME, true) . ');
define("APP_ENV", "development");
define("APP_DEBUG", true);
define("MAIL_DRIVER", "log");
define("STORAGE_PATH", ' . var_export(STORAGE_PATH, true) . ');
define("UPLOAD_PATH", ' . var_export(UPLOAD_PATH, true) . ');
define("STRIPE_WEBHOOK_SECRET", "whsec_http_test");
define("STRIPE_SECRET_KEY", "");
define("BASE_URL", "http://" . $_SERVER["HTTP_HOST"]);
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$file = ' . var_export($public, true) . ' . $path;
if ($path !== "/" && is_file($file)) { return false; }
$_GET["url"] = ltrim($path, "/");
require ' . var_export($public . '/index.php', true) . ';
');
    $port = TestServer::startPhpServer($public, $router);
    $base = 'http://127.0.0.1:' . $port;
    return $base;
}

class TestHttpClient {
    private $base;
    private $jar;

    public function __construct($base) {
        $this->base = $base;
        $this->jar = tempnam(TEST_TMP_DIR, 'jar');
    }

    /**
     * @return array status, body, headers (lower-case keys), location
     */
    public function request($method, $path, $body = null, $headers = array()) {
        $ch = curl_init($this->base . '/' . ltrim($path, '/'));
        $responseHeaders = array();
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            }
        ));
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
        }
        $responseBody = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return array(
            'status' => $status,
            'body' => (string)$responseBody,
            'headers' => $responseHeaders,
            'location' => isset($responseHeaders['location']) ? $responseHeaders['location'] : ''
        );
    }

    public function get($path) {
        return $this->request('GET', $path);
    }

    public function post($path, $fields = array(), $headers = array()) {
        return $this->request('POST', $path, $fields, $headers);
    }

    /** CSRF token from the page's meta tag or hidden field */
    public function csrfToken($path = 'dashboard/index') {
        $page = $this->get($path);
        if (preg_match('/name="csrf-token" content="([^"]+)"/', $page['body'], $m) || preg_match('/name="csrf_token" value="([^"]+)"/', $page['body'], $m)) {
            return html_entity_decode($m[1]);
        }
        return '';
    }

    /** Log in with the demo password; returns true on success */
    public function login($email) {
        $token = $this->csrfToken('auth/login');
        $response = $this->post('auth/login', array('email' => $email, 'password' => 'password', 'csrf_token' => $token));
        if ($response['status'] !== 302 || strpos($response['location'], 'auth/login') !== false) {
            return false;
        }
        $check = $this->get('dashboard/index');
        return $check['status'] === 200;
    }
}

function test_logged_in_client($email) {
    $client = new TestHttpClient(test_app_server());
    if (!$client->login($email)) {
        skip_test('login as ' . $email . ' failed (AuthController/demo data)');
    }
    return $client;
}

$suite->add('HTTP', 'public /health exposes no sensitive details', function () {
    $client = new TestHttpClient(test_app_server());
    $response = $client->get('health');
    assert_equals(200, $response['status']);
    $data = json_decode($response['body'], true);
    assert_equals(array('status', 'timestamp'), array_keys($data));
    assert_equals('healthy', $data['status']);
    foreach (array(DB_HOST, DB_NAME, PHP_VERSION, ROOT_PATH) as $secret) {
        assert_not_contains($secret, $response['body']);
    }
    assert_equals(200, $client->get('health/alive')['status']);
    assert_equals(200, $client->get('health/ready')['status']);
});

$suite->add('HTTP', '/health/detailed requires a platform admin', function () {
    $client = new TestHttpClient(test_app_server());
    assert_equals(403, $client->get('health/detailed')['status'], 'anonymous');

    $admin = test_logged_in_client('admin@splashestate.com');
    $response = $admin->get('health/detailed');
    assert_equals(200, $response['status']);
    $data = json_decode($response['body'], true);
    assert_true($data['checks']['database']['healthy']);
    assert_not_contains(DB_PASS !== '' ? DB_PASS : 'no-password-set', $response['body']);
}, 'depends on AuthController');

$suite->add('HTTP', 'Stripe webhook endpoint verifies signatures and de-duplicates events', function () {
    $client = new TestHttpClient(test_app_server());
    $pdo = Database::getInstance()->getConnection();
    $pdo->exec("UPDATE tenants SET stripe_customer_id = 'cus_http_1', stripe_subscription_id = NULL WHERE id = 2");

    $periodEnd = time() + 30 * 86400;
    $payload = json_encode(array(
        'id' => 'evt_http_' . bin2hex(random_bytes(4)),
        'type' => 'customer.subscription.updated',
        'data' => array('object' => array(
            'id' => 'sub_http_1', 'customer' => 'cus_http_1', 'status' => 'active', 'cancel_at_period_end' => true,
            'current_period_end' => $periodEnd,
            'items' => array('data' => array(array('id' => 'si_1', 'price' => array('id' => 'price_professional_monthly'))))
        ))
    ));
    $sign = function ($body, $timestamp) {
        return 'Stripe-Signature: t=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, 'whsec_http_test');
    };

    assert_equals(405, $client->get('subscription/webhook')['status']);
    assert_equals(400, $client->post('subscription/webhook', $payload, array('Content-Type: application/json', 'Stripe-Signature: t=1,v1=00'))['status'], 'bad signature');
    assert_equals(400, $client->post('subscription/webhook', $payload, array('Content-Type: application/json', $sign($payload, time() - 600)))['status'], 'stale signature');

    $first = $client->post('subscription/webhook', $payload, array('Content-Type: application/json', $sign($payload, time())));
    assert_equals(200, $first['status'], $first['body']);
    assert_equals(array('received' => true), json_decode($first['body'], true));

    $second = $client->post('subscription/webhook', $payload, array('Content-Type: application/json', $sign($payload, time())));
    assert_equals(200, $second['status']);
    assert_equals(true, json_decode($second['body'], true)['duplicate']);

    $active = $pdo->query("SELECT * FROM tenant_subscriptions WHERE tenant_id = 2 AND status = 'active'")->fetchAll();
    assert_equals(1, count($active));
    assert_equals(2, (int)$active[0]['plan_id'], 'plan mapped from the Stripe price');
    assert_equals(date('Y-m-d', $periodEnd), $active[0]['end_date']);
    assert_equals('sub_http_1', $pdo->query("SELECT stripe_subscription_id FROM tenants WHERE id = 2")->fetchColumn());
});

$suite->add('HTTP', 'webhook UI: CSRF required, secret shown once, tenant isolation', function () {
    $pdo = Database::getInstance()->getConnection();
    $pdo->exec("DELETE FROM webhooks");
    $john = test_logged_in_client('john.smith@sunsetrealty.com');

    $fields = array('name' => 'HTTP test hook', 'url' => 'http://127.0.0.1:9/hook', 'events' => array('lead.created'), 'max_retries' => 3, 'retry_enabled' => 1);
    $john->post('webhooks/create', $fields);
    assert_equals(0, (int)$pdo->query("SELECT COUNT(*) FROM webhooks")->fetchColumn(), 'no CSRF token -> nothing created');

    $fields['csrf_token'] = $john->csrfToken('webhooks/create');
    $created = $john->post('webhooks/create', $fields);
    assert_equals(302, $created['status']);
    $hook = $pdo->query("SELECT * FROM webhooks ORDER BY id DESC LIMIT 1")->fetch();
    assert_equals(1, (int)$hook['tenant_id']);
    assert_contains('webhooks/view/' . $hook['id'], $created['location']);

    $first = $john->get('webhooks/view/' . $hook['id']);
    assert_equals(200, $first['status']);
    assert_contains($hook['secret'], $first['body'], 'secret revealed once');
    $second = $john->get('webhooks/view/' . $hook['id']);
    assert_not_contains($hook['secret'], $second['body'], 'masked afterwards');

    assert_equals(302, $john->get('webhooks/delete/' . $hook['id'])['status'], 'GET delete is refused');
    assert_equals(1, (int)$pdo->query("SELECT COUNT(*) FROM webhooks WHERE id = " . (int)$hook['id'])->fetchColumn());

    $lisa = test_logged_in_client('lisa.chen@metroproperties.com');
    $view = $lisa->get('webhooks/view/' . $hook['id']);
    assert_equals(302, $view['status'], 'other tenant is redirected');
    assert_not_contains($hook['url'], $lisa->get('webhooks/logs?webhook_id=' . $hook['id'])['body']);
    $token = $lisa->csrfToken('webhooks/index');
    $lisa->post('webhooks/delete/' . $hook['id'], array('csrf_token' => $token));
    $lisa->post('webhooks/edit/' . $hook['id'], array('csrf_token' => $token, 'name' => 'pwned', 'url' => 'https://8.8.8.8/', 'events' => array('*')));
    $after = $pdo->query("SELECT * FROM webhooks WHERE id = " . (int)$hook['id'])->fetch();
    assert_true(is_array($after), 'not deleted by another tenant');
    assert_equals('HTTP test hook', $after['name'], 'not edited by another tenant');

    $sarah = test_logged_in_client('sarah.johnson@sunsetrealty.com');
    assert_equals(302, $sarah->get('webhooks/index')['status'], 'agents lack webhooks.manage');
}, 'depends on AuthController');

$suite->add('HTTP', 'subscription changes require CSRF; demo mode records locally', function () {
    $john = test_logged_in_client('john.smith@sunsetrealty.com');
    $json = array('Content-Type: application/json', 'Accept: application/json', 'X-Requested-With: XMLHttpRequest');

    $current = (new Subscription())->getActiveSubscription(1);
    $target = ($current && (int)$current['plan_id'] === 3) ? 2 : 3;
    $body = json_encode(array('plan_id' => $target));

    $noToken = $john->post('subscription/change-plan', $body, $json);
    assert_equals(419, $noToken['status']);

    $token = $john->csrfToken('subscription/index');
    $ok = $john->post('subscription/change-plan', $body, array_merge($json, array('X-CSRF-Token: ' . $token)));
    assert_equals(200, $ok['status'], $ok['body']);
    $data = json_decode($ok['body'], true);
    assert_true($data['success']);
    assert_true($data['demo']);
    assert_contains('Demo mode', $data['message']);

    $sub = (new Subscription())->getActiveSubscription(1);
    assert_equals($target, (int)$sub['plan_id']);

    $again = $john->post('subscription/change-plan', $body, array_merge($json, array('X-CSRF-Token: ' . $token)));
    assert_equals(400, $again['status'], 'already on that plan');

    $page = $john->get('subscription/index');
    assert_equals(200, $page['status']);
    assert_contains('Demo mode', $page['body']);
    assert_equals(200, $john->get('subscription/invoices')['status']);
}, 'depends on AuthController');
