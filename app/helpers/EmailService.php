<?php
// FILE: /app/helpers/EmailService.php

/**
 * SplashEstate CRM - Email Service
 * Renders email templates and sends them through the configured driver:
 *
 *   MAIL_DRIVER=smtp  SMTP via SMTPMailer (MAIL_HOST, MAIL_PORT, MAIL_ENCRYPTION, MAIL_USERNAME, MAIL_PASSWORD)
 *   MAIL_DRIVER=mail  PHP mail()
 *   MAIL_DRIVER=log   write the complete message to storage/logs/mail.log (development)
 *
 * Every attempt is recorded (without credentials) in storage/logs/email.log.
 */

class EmailService {

    private $driver;
    private $smtpHost;
    private $smtpPort;
    private $smtpUser;
    private $smtpPass;
    private $smtpEncryption;
    private $fromAddress;
    private $fromName;
    private $logDir;
    private $lastError = '';

    public function __construct() {
        $this->driver = strtolower((string)(defined('MAIL_DRIVER') ? MAIL_DRIVER : 'log'));
        $this->smtpHost = (string)(defined('MAIL_HOST') ? MAIL_HOST : (defined('SMTP_HOST') ? SMTP_HOST : ''));
        $this->smtpPort = (int)(defined('MAIL_PORT') ? MAIL_PORT : (defined('SMTP_PORT') ? SMTP_PORT : 587));
        $this->smtpUser = (string)(defined('MAIL_USERNAME') ? MAIL_USERNAME : (defined('SMTP_USER') ? SMTP_USER : ''));
        $this->smtpPass = (string)(defined('MAIL_PASSWORD') ? MAIL_PASSWORD : (defined('SMTP_PASS') ? SMTP_PASS : ''));
        $this->smtpEncryption = (string)(defined('MAIL_ENCRYPTION') ? MAIL_ENCRYPTION : 'tls');
        $this->fromAddress = (string)(defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : (defined('SMTP_FROM') ? SMTP_FROM : 'noreply@splashestate.com'));
        $this->fromName = (string)(defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : (defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'SplashEstate CRM'));
        $this->logDir = (defined('STORAGE_PATH') ? STORAGE_PATH : dirname(dirname(__DIR__)) . '/storage') . '/logs';
    }

    /**
     * Override the driver (smtp, mail, log) - used by tests and CLI tools
     * @param string $driver
     */
    public function setDriver($driver) {
        $this->driver = strtolower((string)$driver);
    }

    /**
     * Override the log directory (tests)
     * @param string $dir
     */
    public function setLogDirectory($dir) {
        $this->logDir = rtrim((string)$dir, '/');
    }

    /**
     * Last failure reason (no credentials)
     * @return string
     */
    public function getLastError() {
        return $this->lastError;
    }

    /**
     * Send an email rendered from app/views/emails/{template}.php
     * @param string $to Recipient email
     * @param string $subject Subject
     * @param string $template Template name
     * @param array $data Template variables (escaped by the template)
     * @return bool
     */
    public function send($to, $subject, $template, $data = array()) {
        $body = $this->renderTemplate($template, $data);
        if ($body === false) {
            $this->lastError = 'Template not found: ' . $template;
            $this->logEmail($to, $subject, 'failed', $this->lastError);
            return false;
        }
        return $this->sendEmail($to, $subject, $body);
    }

    /**
     * Send an email
     * @param string $to Recipient
     * @param string $subject Subject
     * @param string $body HTML body
     * @param string $altBody Plain-text alternative (generated when empty)
     * @return bool
     */
    public function sendEmail($to, $subject, $body, $altBody = '') {
        require_once APP_PATH . '/helpers/SMTPMailer.php';
        $this->lastError = '';

        // Header injection protection: CR/LF never reach a header
        $subject = SMTPMailer::sanitizeHeader($subject);
        $toAddress = SMTPMailer::sanitizeAddress($to);
        $fromAddress = SMTPMailer::sanitizeAddress($this->fromAddress);
        $fromName = SMTPMailer::sanitizeHeader($this->fromName);

        if ($toAddress === false) {
            $this->lastError = 'Invalid recipient address';
            $this->logEmail(SMTPMailer::sanitizeHeader($to), $subject, 'failed', $this->lastError);
            return false;
        }
        if ($fromAddress === false) {
            $this->lastError = 'Invalid MAIL_FROM_ADDRESS';
            $this->logEmail($toAddress, $subject, 'failed', $this->lastError);
            return false;
        }

        $driver = $this->driver;
        if ($driver === 'smtp' && ($this->smtpHost === '' || ($this->smtpUser === '' && !$this->isLocalHost($this->smtpHost)))) {
            // Unconfigured SMTP (e.g. the config.php defaults): never block the request on a doomed connection
            $driver = 'log';
            error_log('EmailService: SMTP is not configured (MAIL_HOST/MAIL_USERNAME), email written to mail.log instead');
        }

        try {
            switch ($driver) {
                case 'smtp':
                    $mailer = new SMTPMailer(array(
                        'host' => $this->smtpHost,
                        'port' => $this->smtpPort,
                        'username' => $this->smtpUser,
                        'password' => $this->smtpPass,
                        'encryption' => $this->smtpEncryption
                    ));
                    $success = $mailer->send($toAddress, $subject, $body, $fromAddress, $fromName, $altBody);
                    if (!$success) {
                        $this->lastError = $mailer->getLastError();
                    }
                    break;

                case 'mail':
                    $success = $this->sendWithMailFunction($toAddress, $subject, $body, $fromAddress, $fromName, $altBody);
                    break;

                case 'log':
                default:
                    $success = $this->writeToMailLog($toAddress, $subject, $body, $fromAddress, $fromName, $altBody);
                    break;
            }
        } catch (Exception $e) {
            $success = false;
            $this->lastError = $e->getMessage();
        }

        $this->logEmail($toAddress, $subject, $success ? 'sent (' . $driver . ')' : 'failed (' . $driver . ')', $success ? '' : $this->lastError);
        if (!$success) {
            error_log('Email to ' . $toAddress . ' failed: ' . $this->lastError);
        }

        return $success;
    }

    /**
     * Render an email template
     * @param string $template Template name (letters, digits, underscore)
     * @param array $data Variables
     * @return string|false HTML
     */
    public function renderTemplate($template, $data = array()) {
        if (!preg_match('/^[a-z0-9][a-z0-9_]*$/', (string)$template) || $template === 'layout') {
            return false;
        }
        $templatePath = APP_PATH . '/views/emails/' . $template . '.php';
        if (!is_file($templatePath)) {
            return false;
        }

        extract(is_array($data) ? $data : array(), EXTR_SKIP);
        ob_start();
        try {
            include $templatePath;
        } catch (Throwable $e) {
            ob_end_clean();
            error_log('Email template ' . $template . ' failed: ' . $e->getMessage());
            return false;
        }
        return ob_get_clean();
    }

    /**
     * PHP mail() transport
     */
    private function sendWithMailFunction($to, $subject, $body, $from, $fromName, $altBody) {
        $message = SMTPMailer::buildMessage($to, $subject, $body, $from, $fromName, $altBody);
        list($headerBlock, $messageBody) = explode("\r\n\r\n", $message, 2);

        // mail() adds To and Subject itself
        $headers = array();
        $encodedSubject = $subject;
        foreach (explode("\r\n", $headerBlock) as $line) {
            if (stripos($line, 'To:') === 0) {
                continue;
            }
            if (stripos($line, 'Subject:') === 0) {
                $encodedSubject = trim(substr($line, 8));
                continue;
            }
            $headers[] = $line;
        }

        $result = @mail($to, $encodedSubject, $messageBody, implode("\r\n", $headers), '-f' . $from);
        if (!$result) {
            $this->lastError = 'mail() returned false';
        }
        return (bool)$result;
    }

    /**
     * Log transport: append the full message to storage/logs/mail.log
     */
    private function writeToMailLog($to, $subject, $body, $from, $fromName, $altBody) {
        if (!$this->ensureLogDir()) {
            $this->lastError = 'Log directory is not writable';
            return false;
        }
        $message = SMTPMailer::buildMessage($to, $subject, $body, $from, $fromName, $altBody);
        $entry = str_repeat('=', 72) . "\n" . date('Y-m-d H:i:s') . "\n" . str_replace("\r\n", "\n", $message) . "\n";
        if (@file_put_contents($this->logDir . '/mail.log', $entry, FILE_APPEND | LOCK_EX) === false) {
            $this->lastError = 'Could not write mail.log';
            return false;
        }
        return true;
    }

    /**
     * Append a one-line status record to storage/logs/email.log
     */
    private function logEmail($to, $subject, $status, $error = '') {
        if (!$this->ensureLogDir()) {
            return;
        }
        $line = date('Y-m-d H:i:s') . ' | To: ' . SMTPMailer::sanitizeHeader($to)
            . ' | Subject: ' . SMTPMailer::sanitizeHeader($subject)
            . ' | Status: ' . $status
            . ($error !== '' ? ' | Error: ' . SMTPMailer::sanitizeHeader($error) : '') . "\n";
        @file_put_contents($this->logDir . '/email.log', $line, FILE_APPEND | LOCK_EX);
    }

    private function isLocalHost($host) {
        return in_array(strtolower($host), array('localhost', '127.0.0.1', '::1'), true);
    }

    private function ensureLogDir() {
        if (!is_dir($this->logDir) && !@mkdir($this->logDir, 0775, true) && !is_dir($this->logDir)) {
            return false;
        }
        return is_writable($this->logDir);
    }

    /**
     * Welcome email (first_name, email, company_name)
     */
    public function sendWelcomeEmail($to, $data) {
        return $this->send($to, 'Welcome to SplashEstate CRM', 'welcome', $data);
    }

    /**
     * New lead assigned (agent_name, lead_name, lead_email, lead_phone, interest_type, source, notes, lead_id)
     */
    public function sendLeadNotification($to, $data) {
        return $this->send($to, 'New Lead Assigned', 'lead_notification', $data);
    }

    /**
     * Task reminder (user_name, task_title, due_date, priority, description, task_id)
     */
    public function sendTaskReminder($to, $data) {
        $title = isset($data['task_title']) ? $data['task_title'] : '';
        return $this->send($to, 'Task Reminder: ' . $title, 'task_reminder', $data);
    }

    /**
     * Deal won (agent_name, deal_title, client_name, deal_value, commission, closed_date, deal_id)
     */
    public function sendDealWonNotification($to, $data) {
        return $this->send($to, 'Congratulations! Deal Closed', 'deal_won', $data);
    }

    /**
     * Password reset (first_name, reset_url, expires_minutes)
     */
    public function sendPasswordReset($to, $data) {
        return $this->send($to, 'Password Reset Request', 'password_reset', $data);
    }

    /**
     * Invoice (invoice_number, amount, due_date, company_name)
     */
    public function sendInvoice($to, $data) {
        $number = isset($data['invoice_number']) ? $data['invoice_number'] : '';
        return $this->send($to, 'Invoice #' . $number, 'invoice', $data);
    }
}
