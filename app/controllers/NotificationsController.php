<?php
// FILE: /app/controllers/NotificationsController.php

/**
 * SplashEstate CRM - Notifications Controller
 * The logged-in user's in-app notifications.
 *
 * Routes:
 *   GET  notifications/index           list (unread first, paginated; ?filter=unread)
 *   POST notifications/markRead/{id}   mark read (open=1 also redirects to the notification's link)
 *   POST notifications/markAllRead     mark all read
 *   POST notifications/delete/{id}     delete one
 *   POST notifications/clearRead       delete all read notifications
 *   GET  notifications/unreadCount     JSON {success, count}
 */

require_once APP_PATH . '/models/Notification.php';

class NotificationsController extends Controller {

    /** @var Notification */
    private $notificationModel;

    public function __construct() {
        $this->requireLogin();
        $this->notificationModel = new Notification();
    }

    /**
     * List notifications
     */
    public function index() {
        $userId = $this->getUserId();
        $tenantId = $this->getTenantId();
        $unreadOnly = isset($_GET['filter']) && $_GET['filter'] === 'unread';
        $perPage = 20;

        $total = $this->notificationModel->countForUser($tenantId, $userId, $unreadOnly);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = isset($_GET['page']) ? min(max(1, (int)$_GET['page']), $totalPages) : 1;

        $this->view('notifications/index', array(
            'title' => 'Notifications',
            'notifications' => $this->notificationModel->getForUser($tenantId, $userId, $page, $perPage, $unreadOnly),
            'unreadCount' => $this->notificationModel->countForUser($tenantId, $userId, true),
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
            'unreadOnly' => $unreadOnly
        ));
    }

    /**
     * Mark one notification as read; with open=1, continue to its link
     * @param int $id Notification ID
     */
    public function markRead($id = null) {
        $this->requirePostWithCsrf('notifications/index');
        $notification = $this->notificationModel->findForUser((int)$id, $this->getTenantId(), $this->getUserId());
        if (!$notification) {
            if ($this->wantsJson()) {
                $this->jsonResponse(array('success' => false, 'message' => 'Notification not found'), 404);
            }
            $this->setFlash('error', 'Notification not found.');
            $this->redirect('notifications/index');
        }

        $this->notificationModel->markRead($notification['id'], $this->getTenantId(), $this->getUserId());

        if ($this->wantsJson()) {
            $this->jsonResponse(array('success' => true, 'unread' => $this->notificationModel->countForUser($this->getTenantId(), $this->getUserId(), true)));
        }
        if (!empty($_POST['open']) && $this->isSafeLink($notification['link'])) {
            $this->redirect(ltrim($notification['link'], '/'));
        }
        $this->redirect('notifications/index');
    }

    /**
     * Mark all notifications as read
     */
    public function markAllRead() {
        $this->requirePostWithCsrf('notifications/index');
        $changed = $this->notificationModel->markAllRead($this->getTenantId(), $this->getUserId());
        if ($this->wantsJson()) {
            $this->jsonResponse(array('success' => true, 'updated' => $changed, 'unread' => 0));
        }
        $this->setFlash('success', $changed > 0 ? 'All notifications marked as read.' : 'No unread notifications.');
        $this->redirect('notifications/index');
    }

    /**
     * Delete a notification
     * @param int $id Notification ID
     */
    public function delete($id = null) {
        $this->requirePostWithCsrf('notifications/index');
        $deleted = $this->notificationModel->deleteForUser((int)$id, $this->getTenantId(), $this->getUserId());
        if ($this->wantsJson()) {
            $this->jsonResponse(array('success' => $deleted), $deleted ? 200 : 404);
        }
        $this->setFlash($deleted ? 'success' : 'error', $deleted ? 'Notification deleted.' : 'Notification not found.');
        $this->redirect('notifications/index');
    }

    /**
     * Delete every read notification
     */
    public function clearRead() {
        $this->requirePostWithCsrf('notifications/index');
        $deleted = $this->notificationModel->deleteReadForUser($this->getTenantId(), $this->getUserId());
        if ($this->wantsJson()) {
            $this->jsonResponse(array('success' => true, 'deleted' => $deleted));
        }
        $this->setFlash('success', $deleted . ' read notification(s) deleted.');
        $this->redirect('notifications/index');
    }

    /**
     * Unread count as JSON (for polling the header badge)
     */
    public function unreadCount() {
        $this->jsonResponse(array(
            'success' => true,
            'count' => $this->notificationModel->countForUser($this->getTenantId(), $this->getUserId(), true)
        ));
    }

    /**
     * Only relative in-app paths may be followed (prevents open redirects)
     * @param string|null $link Link
     * @return bool
     */
    private function isSafeLink($link) {
        $link = (string)$link;
        return $link !== '' && preg_match('#^/?[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-\.]+)*(\?[A-Za-z0-9_\-=&%\.]*)?$#', $link) === 1;
    }
}
