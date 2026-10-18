<?php
// FILE: /tests/cases/CoreTest.php
// Core framework: Model identifier validation and tenant scoping, Router name
// resolution, RBAC permission loading.

/** Base Model bound to the leads table (exercises Model, not Lead) */
class TestScopedLeadModel extends Model {
    protected $table = 'leads';
}

/** Create a user and return its id */
function test_create_user($tenantId, $role, $email = null) {
    $pdo = Database::getInstance()->getConnection();
    $stmt = $pdo->prepare("INSERT INTO users (tenant_id, email, password, first_name, last_name, role, status) VALUES (:t, :e, :p, 'Test', 'User', :r, 'active')");
    $stmt->execute(array(
        ':t' => $tenantId,
        ':e' => $email !== null ? $email : 'user_' . bin2hex(random_bytes(5)) . '@example.test',
        ':p' => password_hash('password', PASSWORD_DEFAULT),
        ':r' => $role
    ));
    return (int)$pdo->lastInsertId();
}

function test_role_id($slug) {
    $stmt = Database::getInstance()->getConnection()->prepare("SELECT id FROM roles WHERE slug = :s AND is_system = 1 LIMIT 1");
    $stmt->execute(array(':s' => $slug));
    return (int)$stmt->fetchColumn();
}

function test_router_call($method, $args) {
    $ref = new ReflectionMethod('Router', $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs(null, $args);
}

// ---------------------------------------------------------------------------
// Model
// ---------------------------------------------------------------------------

$suite->add('Model', 'isValidIdentifier accepts column names and rejects SQL', function () {
    assert_true(Model::isValidIdentifier('first_name'));
    assert_true(Model::isValidIdentifier('_x1'));
    assert_false(Model::isValidIdentifier('name; DROP TABLE users'));
    assert_false(Model::isValidIdentifier('1abc'));
    assert_false(Model::isValidIdentifier('a`b'));
    assert_false(Model::isValidIdentifier(''));
    assert_false(Model::isValidIdentifier(str_repeat('a', 65)));
    assert_false(Model::isValidIdentifier(array('id')));
});

$suite->add('Model', 'create()/update() reject unsafe column names', function () {
    $model = new TestScopedLeadModel();
    assert_throws('InvalidArgumentException', function () use ($model) {
        $model->create(array('tenant_id' => 1, 'first_name) VALUES (1); --' => 'x'));
    });
    assert_throws('InvalidArgumentException', function () use ($model) {
        $model->update(1, 1, array('status = \'won\', tenant_id' => 2));
    });
});

$suite->add('Model', 'getById() of another tenant returns false', function () {
    $model = new TestScopedLeadModel();
    $id = (int)$model->create(array('tenant_id' => 1, 'first_name' => 'Scope', 'last_name' => 'Test', 'email' => 'scope@example.test'));
    assert_true($id > 0, 'lead created');
    $own = $model->getById($id, 1);
    assert_equals('Scope', $own['first_name']);
    assert_false($model->getById($id, 2), 'tenant 2 must not read a tenant 1 lead');
});

$suite->add('Model', 'update()/delete() are tenant scoped', function () {
    $model = new TestScopedLeadModel();
    $id = (int)$model->create(array('tenant_id' => 1, 'first_name' => 'Keep', 'last_name' => 'Me'));
    $model->update($id, 2, array('first_name' => 'Hacked'));
    $model->delete($id, 2);
    $row = $model->getById($id, 1);
    assert_true(is_array($row), 'still exists after foreign delete');
    assert_equals('Keep', $row['first_name'], 'not modified by another tenant');
    assert_true($model->delete($id, 1));
    assert_false($model->getById($id, 1));
});

$suite->add('Model', 'getAll()/count() ignore unsafe filter keys and stay scoped', function () {
    $model = new TestScopedLeadModel();
    $all = $model->getAll(2, 1, 100);
    foreach ($all as $row) {
        assert_equals(2, (int)$row['tenant_id']);
    }
    $count = $model->count(2, array('1=1 OR 1' => 'x'));
    assert_equals(count($all), $count, 'unsafe filter key ignored');
    assert_equals(0, $model->count(2, array('status' => 'no-such-status')));
});

$suite->add('Model', 'search() binds each field separately (native prepares)', function () {
    $model = new TestScopedLeadModel();
    $rows = $model->search(1, 'david', array('first_name', 'last_name', 'email', 'bad field'), 1, 10);
    assert_true(count($rows) >= 1, 'demo lead David found');
    foreach ($rows as $row) {
        assert_equals(1, (int)$row['tenant_id']);
    }
});

$suite->add('Model', 'domain models deny cross-tenant getById()', function () {
    $checked = 0;
    foreach (array('Lead' => 'leads', 'Client' => 'clients', 'Property' => 'properties', 'Deal' => 'deals', 'Task' => 'tasks') as $class => $table) {
        if (!class_file_loads('app/models/' . $class . '.php')) {
            continue;
        }
        require_once APP_PATH . '/models/' . $class . '.php';
        $id = (int)Database::getInstance()->getConnection()->query("SELECT id FROM {$table} WHERE tenant_id = 1 ORDER BY id LIMIT 1")->fetchColumn();
        $model = new $class();
        assert_false($model->getById($id, 2), $class . '::getById must be tenant scoped');
        assert_true((bool)$model->getById($id, 1), $class . '::getById own tenant');
        $checked++;
    }
    if ($checked === 0) {
        skip_test('no domain model loads cleanly yet');
    }
}, 'uses other engineers\' models');

// ---------------------------------------------------------------------------
// Router
// ---------------------------------------------------------------------------

$suite->add('Router', 'URL segments map to controller names', function () {
    require_once APP_PATH . '/core/Router.php';
    assert_equals('PropertyGallery', test_router_call('toControllerName', array('property-gallery')));
    assert_equals('SavedSearch', test_router_call('toControllerName', array('saved_search')));
    assert_equals('Webhooks', test_router_call('toControllerName', array('webhooks')));
    assert_equals(null, test_router_call('toControllerName', array('../etc/passwd')));
    assert_equals(null, test_router_call('toControllerName', array('')));
});

$suite->add('Router', 'controller lookup is case-insensitive and rejects unknown names', function () {
    $dir = APP_PATH . '/controllers';
    assert_equals('Webhooks', test_router_call('resolveControllerName', array('webhooks', $dir, 'Controller')));
    assert_equals('Health', test_router_call('resolveControllerName', array('HEALTH', $dir, 'Controller')));
    assert_equals('PropertyGallery', test_router_call('resolveControllerName', array('propertygallery', $dir, 'Controller')));
    assert_equals(null, test_router_call('resolveControllerName', array('nonexistent', $dir, 'Controller')));
    assert_equals(null, test_router_call('resolveControllerName', array('Controller', $dir, 'Controller')), 'base class is not a route');
});

$suite->add('Router', 'URL segments map to method names', function () {
    assert_equals('regenerateSecret', test_router_call('toMethodName', array('regenerate-secret')));
    assert_equals('changePlan', test_router_call('toMethodName', array('changePlan')));
    assert_equals(null, test_router_call('toMethodName', array('x y')));
    assert_equals(null, test_router_call('toMethodName', array('__construct')));
});

$suite->add('Router', 'only public actions declared on the controller are routable', function () {
    require_once APP_PATH . '/controllers/WebhooksController.php';
    $controller = new WebhooksController();
    assert_true(test_router_call('isRoutable', array($controller, 'show')));
    assert_true(test_router_call('isRoutable', array($controller, 'regenerateSecret')));
    assert_false(test_router_call('isRoutable', array($controller, 'view')), 'inherited renderer');
    assert_false(test_router_call('isRoutable', array($controller, 'requireWebhookAccess')), 'private helper');
    assert_false(test_router_call('isRoutable', array($controller, 'redirect')), 'protected base method');
    assert_false(test_router_call('isRoutable', array($controller, '__construct')));
});

// ---------------------------------------------------------------------------
// RBAC
// ---------------------------------------------------------------------------

$suite->add('RBAC', 'user without user_roles falls back to the system role of users.role', function () {
    $userId = test_create_user(1, 'agent');
    $permissions = loadUserPermissions($userId);
    assert_contains('leads.view', $permissions);
    assert_contains('tasks.delete', $permissions);
    assert_not_contains('webhooks.manage', $permissions);
    assert_not_contains('subscription.manage', $permissions);

    $adminId = test_create_user(1, 'tenant_admin');
    $adminPermissions = loadUserPermissions($adminId);
    assert_contains('webhooks.manage', $adminPermissions);
    assert_contains('workflows.manage', $adminPermissions);
    assert_contains('audit.view', $adminPermissions);
});

$suite->add('RBAC', 'explicit user_roles replace the legacy role', function () {
    $userId = test_create_user(1, 'tenant_admin');
    $pdo = Database::getInstance()->getConnection();
    $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (:u, :r)")->execute(array(':u' => $userId, ':r' => test_role_id('viewer')));

    $permissions = loadUserPermissions($userId);
    assert_contains('leads.view', $permissions);
    assert_not_contains('leads.create', $permissions, 'viewer cannot create');
    assert_not_contains('webhooks.manage', $permissions, 'legacy tenant_admin role ignored');
});

$suite->add('RBAC', 'explicit role without permissions grants nothing', function () {
    $pdo = Database::getInstance()->getConnection();
    $pdo->exec("INSERT INTO roles (tenant_id, name, slug, description, is_system) VALUES (1, 'Empty', 'empty_role_" . bin2hex(random_bytes(3)) . "', 'no permissions', 0)");
    $roleId = (int)$pdo->lastInsertId();
    $userId = test_create_user(1, 'tenant_admin');
    $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (:u, :r)")->execute(array(':u' => $userId, ':r' => $roleId));
    assert_equals(array(), loadUserPermissions($userId));
});

$suite->add('RBAC', 'can() checks the session user; platform admins can do everything', function () {
    $backup = isset($_SESSION) ? $_SESSION : null;
    $_SESSION = array();
    assert_false(can('leads.view'), 'anonymous');

    $_SESSION = array('user_id' => 999999, 'user_role' => 'platform_admin');
    assert_true(can('anything.at.all'));

    $agentId = test_create_user(1, 'agent');
    $_SESSION = array('user_id' => $agentId, 'user_role' => 'agent', 'tenant_id' => 1);
    assert_true(can('leads.view'));
    assert_false(can('webhooks.manage'));
    assert_false(can('subscription.view'));

    $_SESSION = $backup === null ? array() : $backup;
});
