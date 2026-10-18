<?php
// FILE: /app/controllers/AuthController.php

/**
 * SplashEstate CRM - Authentication Controller
 * Handles login (with brute-force protection), registration, logout and password reset
 */

class AuthController extends Controller {

    /** Lockout policy: failures allowed per window */
    const MAX_FAILURES_PER_EMAIL = 5;
    const MAX_FAILURES_PER_IP = 20;
    const LOCKOUT_MINUTES = 15;

    /** Password reset token lifetime (seconds) and per-user issue limit per hour */
    const RESET_TTL = 3600;
    const MAX_RESETS_PER_HOUR = 3;

    const MIN_PASSWORD_LENGTH = 8;

    private $userModel;

    public function __construct() {
        $this->userModel = $this->model('User');
    }

    /**
     * Login form / submit
     */
    public function login() {
        if ($this->isLoggedIn()) {
            $this->redirect('dashboard/index');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->processLogin();
            return;
        }

        $this->view('auth/login', array('title' => 'Login', 'email' => '', 'errors' => array()));
    }

    /**
     * Process login form
     */
    private function processLogin() {
        $this->requirePostWithCsrf('auth/login');

        $email = $this->postString('email');
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $ip = $this->clientIp();

        $errors = array();
        if ($email === '') {
            $errors['email'] = 'Email is required';
        } elseif (!$this->validateEmail($email)) {
            $errors['email'] = 'Invalid email format';
        }
        if ($password === '') {
            $errors['password'] = 'Password is required';
        }
        if (!empty($errors)) {
            $this->renderLogin($email, $errors);
            return;
        }

        // Brute-force protection: refuse before checking the password
        if ($this->userModel->countRecentFailuresByEmail($email, self::LOCKOUT_MINUTES) >= self::MAX_FAILURES_PER_EMAIL
            || $this->userModel->countRecentFailuresByIp($ip, self::LOCKOUT_MINUTES) >= self::MAX_FAILURES_PER_IP) {
            http_response_code(429);
            $this->renderLogin($email, array(
                'general' => 'Too many failed login attempts. Please wait ' . self::LOCKOUT_MINUTES . ' minutes and try again, or reset your password.'
            ));
            return;
        }

        $user = $this->userModel->authenticate($email, $password);

        if (!$user) {
            $this->userModel->recordLoginAttempt($email, $ip, false);
            $this->renderLogin($email, array('general' => 'Invalid email or password'));
            return;
        }

        if ($user['status'] !== 'active') {
            $this->renderLogin($email, array('general' => 'Your account is inactive. Please contact your administrator.'));
            return;
        }

        if (!empty($user['tenant_id'])) {
            $tenant = $this->model('Tenant')->findTenant($user['tenant_id']);
            if (!$tenant || $tenant['status'] !== 'active') {
                $this->renderLogin($email, array('general' => 'Your agency account is not active. Please contact support.'));
                return;
            }
        }

        $this->userModel->recordLoginAttempt($email, $ip, true);
        $this->userModel->updateLastLogin($user['id']);
        if (mt_rand(1, 100) === 1) {
            $this->userModel->purgeLoginAttempts(30);
        }

        $this->startUserSession($user['id'], $user['tenant_id'], $user['email'], $user['first_name'] . ' ' . $user['last_name'], $user['role']);
        logActivity($user['id'], $user['tenant_id'], 'login', 'user', $user['id']);

        $this->redirect('dashboard/index');
    }

    /**
     * Render login form
     */
    private function renderLogin($email, $errors) {
        $this->view('auth/login', array('title' => 'Login', 'email' => $email, 'errors' => $errors));
    }

    /**
     * Log out (POST with CSRF preferred; GET kept for the nav link)
     */
    public function logout() {
        // POST + CSRF only, so other sites can't log users out with a link/image
        $this->requirePostWithCsrf('dashboard/index');

        $_SESSION = array();
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();

        $this->redirect('auth/login');
    }

    /**
     * Registration form (tenant signup)
     */
    public function register() {
        if ($this->isLoggedIn()) {
            $this->redirect('dashboard/index');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->processRegistration();
            return;
        }

        $this->view('auth/register', array('title' => 'Register', 'errors' => array(), 'formData' => array()));
    }

    /**
     * Process registration form
     */
    private function processRegistration() {
        $this->requirePostWithCsrf('auth/register');

        $formData = array(
            'company_name' => $this->postString('company_name'),
            'email' => strtolower($this->postString('email')),
            'first_name' => $this->postString('first_name'),
            'last_name' => $this->postString('last_name'),
            'phone' => $this->postString('phone'),
            'password' => isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '',
            'confirm_password' => isset($_POST['confirm_password']) && is_string($_POST['confirm_password']) ? $_POST['confirm_password'] : ''
        );

        $errors = validateRequired($formData, array('company_name', 'email', 'first_name', 'last_name', 'password', 'confirm_password'));

        foreach (array('company_name' => 255, 'email' => 255, 'first_name' => 100, 'last_name' => 100, 'phone' => 50) as $field => $max) {
            if (strlen($formData[$field]) > $max) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . " must be at most {$max} characters";
            }
        }
        if (empty($errors['email']) && !$this->validateEmail($formData['email'])) {
            $errors['email'] = 'Invalid email format';
        }
        if (empty($errors['email']) && $this->userModel->emailExists($formData['email'])) {
            $errors['email'] = 'Email already registered';
        }
        $passwordError = $this->validatePassword($formData['password'], $formData['confirm_password']);
        if (empty($errors['password']) && $passwordError !== null) {
            $errors[$passwordError[0]] = $passwordError[1];
        }

        // Never echo passwords back into the form
        $safeFormData = $formData;
        unset($safeFormData['password'], $safeFormData['confirm_password']);

        if (!empty($errors)) {
            $this->view('auth/register', array('title' => 'Register', 'errors' => $errors, 'formData' => $safeFormData));
            return;
        }

        $tenantModel = $this->model('Tenant');
        $subscriptionModel = $this->model('Subscription');
        $conn = $this->userModel->getConnection();

        try {
            $conn->beginTransaction();

            $tenantId = $tenantModel->create(array(
                'name' => $formData['company_name'],
                'slug' => $this->generateSlug($formData['company_name'], $tenantModel),
                'email' => $formData['email'],
                'phone' => $formData['phone'] !== '' ? $formData['phone'] : null,
                'api_key' => generateApiKey(),
                'status' => 'active'
            ));
            if (!$tenantId) {
                throw new RuntimeException('Tenant insert failed');
            }

            $userId = $this->userModel->createUser(array(
                'tenant_id' => $tenantId,
                'email' => $formData['email'],
                'password' => $formData['password'],
                'first_name' => $formData['first_name'],
                'last_name' => $formData['last_name'],
                'phone' => $formData['phone'] !== '' ? $formData['phone'] : null,
                'role' => 'tenant_admin',
                'status' => 'active'
            ));
            if (!$userId) {
                throw new RuntimeException('User insert failed');
            }

            if (!$subscriptionModel->createTrialSubscription($tenantId)) {
                error_log('Registration: no free-trial plan found for tenant ' . $tenantId);
            }

            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log('Registration failed: ' . $e->getMessage());
            $this->view('auth/register', array(
                'title' => 'Register',
                'errors' => array('general' => 'Registration failed. Please try again.'),
                'formData' => $safeFormData
            ));
            return;
        }

        $this->startUserSession($userId, $tenantId, $formData['email'], $formData['first_name'] . ' ' . $formData['last_name'], 'tenant_admin');
        $this->userModel->updateLastLogin($userId);
        logActivity($userId, $tenantId, 'registered', 'tenant', $tenantId);

        $this->setFlash('success', 'Registration successful! Welcome to SplashEstate CRM.');
        $this->redirect('dashboard/index');
    }

    /**
     * Forgot password: always shows the same message so account existence is not revealed
     */
    public function forgot() {
        $data = array('title' => 'Forgot Password', 'message' => '', 'messageType' => 'info', 'email' => '');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requirePostWithCsrf('auth/forgot');
            $email = strtolower($this->postString('email'));
            $data['email'] = $email;

            if (!$this->validateEmail($email)) {
                $data['message'] = 'Please enter a valid email address.';
                $data['messageType'] = 'error';
            } else {
                $this->sendResetLink($email);
                $data['message'] = 'If an account exists with this email, you will receive password reset instructions shortly.';
                $data['email'] = '';
            }
        }

        $this->view('auth/forgot', $data);
    }

    /**
     * Issue and email a reset token (silently does nothing for unknown/inactive users)
     * @param string $email
     */
    private function sendResetLink($email) {
        $user = $this->userModel->findByEmail($email);
        if (!$user || $user['status'] !== 'active') {
            return;
        }
        if ($this->userModel->countRecentPasswordResets($user['id'], 60) >= self::MAX_RESETS_PER_HOUR) {
            error_log('Password reset rate limit hit for user ' . $user['id']);
            return;
        }

        $token = bin2hex(random_bytes(32));
        if (!$this->userModel->createPasswordReset($user['id'], hash('sha256', $token), self::RESET_TTL)) {
            return;
        }

        $link = url('auth/reset/' . $token);
        $sent = false;
        try {
            require_once APP_PATH . '/helpers/EmailService.php';
            $emailService = new EmailService();
            $sent = (bool)$emailService->sendPasswordReset($user['email'], array(
                'first_name' => $user['first_name'],
                'reset_url' => $link,
                'expires_minutes' => (int)(self::RESET_TTL / 60)
            ));
        } catch (Throwable $e) {
            error_log('Password reset email failed: ' . $e->getMessage());
        }
        if (!$sent) {
            error_log('Password reset email could not be sent to user ' . $user['id']);
            if (defined('APP_ENV') && APP_ENV === 'development' && defined('APP_DEBUG') && APP_DEBUG) {
                // Development convenience only: mail is usually not configured locally
                error_log('[dev] Password reset link for user ' . $user['id'] . ': ' . $link);
            }
        }
        logActivity($user['id'], $user['tenant_id'], 'password_reset_requested', 'user', $user['id']);
    }

    /**
     * Reset password with a token from the email link (URL: /auth/reset/{token})
     * @param string $token Raw token
     */
    public function reset($token = '') {
        if ($this->isLoggedIn()) {
            $this->redirect('dashboard/index');
        }

        $token = is_string($token) ? $token : '';
        $reset = preg_match('/^[a-f0-9]{64}$/', $token) ? $this->userModel->findValidPasswordReset(hash('sha256', $token)) : false;

        $data = array('title' => 'Reset Password', 'token' => $token, 'errors' => array(), 'invalid' => !$reset);

        if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requirePostWithCsrf('auth/reset/' . $token);

            $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
            $confirm = isset($_POST['confirm_password']) && is_string($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
            $error = $this->validatePassword($password, $confirm);

            if ($error !== null) {
                $data['errors'][$error[0]] = $error[1];
            } elseif ($this->userModel->markPasswordResetUsed($reset['id'])) {
                $this->userModel->updatePassword($reset['user_id'], $password);
                $this->userModel->invalidatePasswordResets($reset['user_id']);
                logActivity($reset['user_id'], null, 'password_reset', 'user', $reset['user_id']);

                // Fresh session for the login that follows
                session_regenerate_id(true);
                unset($_SESSION['csrf_token']);
                $this->setFlash('success', 'Your password has been reset. You can now log in.');
                $this->redirect('auth/login');
            } else {
                $data['invalid'] = true;
            }
        }

        if ($data['invalid']) {
            http_response_code(400);
        }
        $this->view('auth/reset', $data);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Start an authenticated session (new session id + new CSRF token)
     */
    private function startUserSession($userId, $tenantId, $email, $name, $role) {
        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        $_SESSION['user_id'] = (int)$userId;
        $_SESSION['tenant_id'] = $tenantId !== null ? (int)$tenantId : null;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_name'] = $name;
        $_SESSION['user_role'] = $role;
        $_SESSION['last_activity'] = time();
    }

    /**
     * Validate a new password
     * @return array|null [field, message] or null when valid
     */
    private function validatePassword($password, $confirm) {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return array('password', 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters');
        }
        if (strlen($password) > 72) {
            return array('password', 'Password must be at most 72 characters');
        }
        if ($password !== $confirm) {
            return array('confirm_password', 'Passwords do not match');
        }
        return null;
    }

    /**
     * Trimmed POST string ('' when missing)
     */
    private function postString($key) {
        return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
    }

    /**
     * Client IP (REMOTE_ADDR only; proxy headers are spoofable)
     */
    private function clientIp() {
        return isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }

    /**
     * Generate a unique tenant slug from a company name
     * @param string $string Company name
     * @param Tenant $tenantModel
     * @return string
     */
    private function generateSlug($string, $tenantModel) {
        $base = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $string), '-'));
        if ($base === '') {
            $base = 'agency';
        }
        $base = substr($base, 0, 200);
        $slug = $base;
        while ($tenantModel->slugExists($slug)) {
            $slug = $base . '-' . bin2hex(random_bytes(3));
        }
        return $slug;
    }
}
