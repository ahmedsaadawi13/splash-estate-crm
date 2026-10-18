<!-- FILE: /app/views/layouts/header.php -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($title) ? View::escape($title) . ' - ' : ''; ?>SplashEstate CRM</title>
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <script>
        var BASE_URL = <?php echo json_encode(BASE_URL); ?>;
        var CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;
        // Attach the CSRF token to every same-origin, state-changing fetch()
        (function () {
            if (!window.fetch) { return; }
            var originalFetch = window.fetch;
            window.fetch = function (input, init) {
                init = init || {};
                var method = (init.method || 'GET').toUpperCase();
                var url = typeof input === 'string' ? input : (input && input.url) || '';
                var sameOrigin = url.indexOf('http') !== 0 || url.indexOf(window.location.origin) === 0;
                if (method !== 'GET' && method !== 'HEAD' && sameOrigin) {
                    var headers = new Headers(init.headers || {});
                    if (!headers.has('X-CSRF-Token')) { headers.set('X-CSRF-Token', CSRF_TOKEN); }
                    if (!headers.has('X-Requested-With')) { headers.set('X-Requested-With', 'XMLHttpRequest'); }
                    init.headers = headers;
                }
                return originalFetch.call(this, input, init);
            };
        })();
    </script>
</head>
<body>
    <?php if (isset($_SESSION['user_id'])): ?>
    <nav class="navbar">
        <div class="container">
            <div class="navbar-brand">
                <a href="<?php echo BASE_URL; ?>/dashboard/index">SplashEstate CRM</a>
            </div>
            <ul class="navbar-menu">
                <li><a href="<?php echo BASE_URL; ?>/dashboard/index">Dashboard</a></li>
                <li><a href="<?php echo BASE_URL; ?>/leads/index">Leads</a></li>
                <li><a href="<?php echo BASE_URL; ?>/clients/index">Clients</a></li>
                <li><a href="<?php echo BASE_URL; ?>/properties/index">Properties</a></li>
                <li><a href="<?php echo BASE_URL; ?>/deals/index">Deals</a></li>
                <li><a href="<?php echo BASE_URL; ?>/tasks/index">Tasks</a></li>
                <li><a href="<?php echo BASE_URL; ?>/calendar/index">Calendar</a></li>
                <?php if (can('reports.view')): ?>
                    <li><a href="<?php echo BASE_URL; ?>/reports/index">Reports</a></li>
                <?php endif; ?>
                <?php if (can('workflows.manage')): ?>
                    <li><a href="<?php echo BASE_URL; ?>/workflows/index">Workflows</a></li>
                <?php endif; ?>
                <?php if (can('scoring.manage')): ?>
                    <li><a href="<?php echo BASE_URL; ?>/scoring/index">Lead Scoring</a></li>
                <?php endif; ?>
                <?php if (can('webhooks.manage')): ?>
                    <li><a href="<?php echo BASE_URL; ?>/webhooks/index">Webhooks</a></li>
                <?php endif; ?>
                <?php if (can('roles.manage')): ?>
                    <li><a href="<?php echo BASE_URL; ?>/roles/index">Roles</a></li>
                <?php endif; ?>
                <?php if (can('audit.view')): ?>
                    <li><a href="<?php echo BASE_URL; ?>/activity/index">Activity Log</a></li>
                <?php endif; ?>
                <?php if (can('subscription.view')): ?>
                    <li><a href="<?php echo BASE_URL; ?>/subscription/index">Subscription</a></li>
                <?php endif; ?>
                <li class="navbar-user">
                    <?php $unreadCount = unreadNotificationCount(); ?>
                    <a href="<?php echo BASE_URL; ?>/notifications/index" title="Notifications">Notifications<?php if ($unreadCount > 0): ?><span class="nav-badge"><?php echo (int)$unreadCount; ?></span><?php endif; ?></a>
                    <span><?php echo View::escape(isset($_SESSION['user_name']) ? $_SESSION['user_name'] : ''); ?></span>
                    <a href="<?php echo BASE_URL; ?>/auth/logout">Logout</a>
                </li>
            </ul>
        </div>
    </nav>
    <?php endif; ?>

    <div class="container">
        <?php if (isset($_SESSION['flash'])): ?>
            <?php $flash = $_SESSION['flash']; unset($_SESSION['flash']); ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo View::escape($flash['message']); ?>
            </div>
        <?php endif; ?>
