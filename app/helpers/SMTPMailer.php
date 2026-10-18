<?php
// FILE: /app/helpers/SMTPMailer.php

/**
 * SplashEstate CRM - SMTP Mailer
 * Dependency-free SMTP client: implicit TLS (ssl, port 465) or STARTTLS (tls,
 * port 587), AUTH PLAIN/LOGIN, multipart/alternative messages, header-injection
 * safe headers, dot-stuffing and timeouts. Credentials are never logged.
 */

class SMTPMailer {

    private $host;
    private $port;
    private $username;
    private $password;
    private $encryption;
    private $timeout = 15;
    private $heloHost;
    private $socket;
    private $debug = false;
    private $lastError = '';
    private $extensions = array();

    /**
     * @param array $config host, port, username, password, encryption (tls|ssl|none), timeout, helo_host, debug
     */
    public function __construct($config = array()) {
        $this->host = (string)(isset($config['host']) ? $config['host'] : (defined('MAIL_HOST') ? MAIL_HOST : 'localhost'));
        $this->port = (int)(isset($config['port']) ? $config['port'] : (defined('MAIL_PORT') ? MAIL_PORT : 587));
        $this->username = (string)(isset($config['username']) ? $config['username'] : (defined('MAIL_USERNAME') ? MAIL_USERNAME : ''));
        $this->password = (string)(isset($config['password']) ? $config['password'] : (defined('MAIL_PASSWORD') ? MAIL_PASSWORD : ''));
        $encryption = strtolower((string)(isset($config['encryption']) ? $config['encryption'] : (defined('MAIL_ENCRYPTION') ? MAIL_ENCRYPTION : 'tls')));
        $this->encryption = in_array($encryption, array('tls', 'ssl'), true) ? $encryption : 'none';
        if (isset($config['timeout'])) {
            $this->timeout = max(1, (int)$config['timeout']);
        }
        $this->heloHost = isset($config['helo_host']) ? (string)$config['helo_host'] : self::localHostname();
        $this->debug = !empty($config['debug']);
    }

    /**
     * Send an email
     * @param string $to Recipient address
     * @param string $subject Subject
     * @param string $htmlBody HTML body
     * @param string|null $from Sender address (defaults to MAIL_FROM_ADDRESS)
     * @param string|null $fromName Sender name (defaults to MAIL_FROM_NAME)
     * @param string $textBody Plain-text alternative (generated from the HTML when empty)
     * @param string|null $replyTo Reply-To address
     * @return bool Success (see getLastError())
     */
    public function send($to, $subject, $htmlBody, $from = null, $fromName = null, $textBody = '', $replyTo = null) {
        $this->lastError = '';
        $from = $from ?: (defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'noreply@localhost');
        $fromName = $fromName !== null ? $fromName : (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : '');

        $to = self::sanitizeAddress($to);
        $from = self::sanitizeAddress($from);
        if ($to === false || $from === false) {
            $this->lastError = 'Invalid sender or recipient address';
            return false;
        }

        try {
            $message = self::buildMessage($to, $subject, $htmlBody, $from, $fromName, $textBody, $replyTo);

            $this->connect();
            $this->hello();
            if ($this->username !== '') {
                $this->authenticate();
            }

            $this->command('MAIL FROM:<' . $from . '>', array(250));
            $this->command('RCPT TO:<' . $to . '>', array(250, 251));
            $this->command('DATA', array(354));
            $this->command(self::dotStuff($message) . "\r\n.", array(250), 'message body');
            $this->command('QUIT', array(221));
            $this->disconnect();
            return true;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            $this->log('SMTP error: ' . $this->lastError);
            $this->disconnect();
            return false;
        }
    }

    /**
     * Last error message (never contains credentials)
     * @return string
     */
    public function getLastError() {
        return $this->lastError;
    }

    /**
     * Test SMTP connection and authentication
     * @return array success, message
     */
    public function testConnection() {
        try {
            $this->connect();
            $this->hello();
            if ($this->username !== '') {
                $this->authenticate();
            }
            $this->command('QUIT', array(221));
            $this->disconnect();
            return array('success' => true, 'message' => 'SMTP connection successful');
        } catch (Exception $e) {
            $this->disconnect();
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Build the full RFC 5322 message (headers + multipart/alternative body)
     * @return string Message with CRLF line endings (not yet dot-stuffed)
     */
    public static function buildMessage($to, $subject, $htmlBody, $from, $fromName = '', $textBody = '', $replyTo = null) {
        $htmlBody = (string)$htmlBody;
        if ((string)$textBody === '') {
            $textBody = self::htmlToText($htmlBody);
        }

        $domain = substr(strrchr($from, '@'), 1);
        if (!is_string($domain) || $domain === '' || !preg_match('/^[A-Za-z0-9.-]+$/', $domain)) {
            $domain = 'localhost';
        }
        $boundary = '=_b' . bin2hex(random_bytes(12));

        $headers = array();
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
        $headers[] = 'From: ' . self::formatAddress($from, $fromName);
        $headers[] = 'To: <' . $to . '>';
        $replyTo = $replyTo !== null ? self::sanitizeAddress($replyTo) : false;
        if ($replyTo !== false) {
            $headers[] = 'Reply-To: <' . $replyTo . '>';
        }
        $headers[] = 'Subject: ' . self::encodeHeader(self::sanitizeHeader($subject));
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $headers[] = 'X-Mailer: SplashEstate CRM';

        $body = "This is a multi-part message in MIME format.\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . self::normalizeNewlines(quoted_printable_encode(self::normalizeNewlines($textBody))) . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . self::normalizeNewlines(quoted_printable_encode(self::normalizeNewlines($htmlBody))) . "\r\n"
            . '--' . $boundary . "--\r\n";

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    /**
     * Remove CR, LF and NUL from a header value (prevents header injection)
     * @param string $value
     * @return string
     */
    public static function sanitizeHeader($value) {
        return trim(preg_replace('/[\r\n\0]+/', ' ', (string)$value));
    }

    /**
     * Validate an email address after stripping header-breaking characters
     * @param string $address
     * @return string|false
     */
    public static function sanitizeAddress($address) {
        $address = self::sanitizeHeader($address);
        if ($address === '' || strpbrk($address, '<>,;"') !== false) {
            return false;
        }
        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : false;
    }

    /**
     * SMTP transparency: double a leading "." on every line (RFC 5321 4.5.2)
     * @param string $data CRLF-normalized message
     * @return string
     */
    public static function dotStuff($data) {
        $data = self::normalizeNewlines($data);
        if (strpos($data, '.') === 0) {
            $data = '.' . $data;
        }
        return str_replace("\r\n.", "\r\n..", $data);
    }

    /**
     * Encode a header value as RFC 2047 when it is not plain ASCII
     */
    private static function encodeHeader($text) {
        if (!preg_match('/[^\x20-\x7E]/', $text)) {
            return $text;
        }
        if (function_exists('mb_encode_mimeheader')) {
            return mb_encode_mimeheader($text, 'UTF-8', 'B', "\r\n");
        }
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    /**
     * "Name" <address>
     */
    private static function formatAddress($address, $name) {
        $name = self::sanitizeHeader($name);
        if ($name === '') {
            return '<' . $address . '>';
        }
        if (preg_match('/[^\x20-\x7E]/', $name)) {
            $name = self::encodeHeader($name);
        } else {
            $name = '"' . addcslashes($name, '"\\') . '"';
        }
        return $name . ' <' . $address . '>';
    }

    /**
     * Plain-text version of an HTML body
     */
    private static function htmlToText($html) {
        $text = preg_replace('#<(style|script|head)[^>]*>.*?</\1>#is', '', (string)$html);
        $text = preg_replace('#<br\s*/?>|</(p|div|h[1-6]|li|tr)>#i', "\n", $text);
        $text = preg_replace('#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', '$2 ($1)', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text);
        return trim($text);
    }

    private static function normalizeNewlines($text) {
        return preg_replace("/\r\n|\r|\n/", "\r\n", (string)$text);
    }

    private static function localHostname() {
        $name = function_exists('gethostname') ? gethostname() : '';
        return (is_string($name) && preg_match('/^[A-Za-z0-9.-]+$/', $name)) ? $name : 'localhost';
    }

    /**
     * Open the connection (implicit TLS for "ssl") and read the greeting
     */
    private function connect() {
        $errno = 0;
        $errstr = '';
        $remote = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $context = stream_context_create(array('ssl' => array(
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $this->host
        )));

        $this->socket = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$this->socket) {
            throw new Exception('Failed to connect to SMTP server ' . $this->host . ':' . $this->port . ': ' . ($errstr !== '' ? $errstr : 'error ' . $errno));
        }
        stream_set_timeout($this->socket, $this->timeout);

        $response = $this->getResponse();
        if (substr($response, 0, 3) !== '220') {
            throw new Exception('Unexpected SMTP greeting: ' . trim($response));
        }
    }

    /**
     * EHLO (+ STARTTLS and a second EHLO when encryption is "tls")
     */
    private function hello() {
        $this->ehlo();

        if ($this->encryption === 'tls') {
            if (!isset($this->extensions['STARTTLS'])) {
                throw new Exception('SMTP server does not support STARTTLS (set MAIL_ENCRYPTION=ssl or none)');
            }
            $this->command('STARTTLS', array(220));
            $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
            }
            if (!@stream_socket_enable_crypto($this->socket, true, $method)) {
                throw new Exception('Failed to enable TLS encryption');
            }
            // Capabilities must be re-read over the encrypted channel (RFC 3207)
            $this->ehlo();
        }
    }

    private function ehlo() {
        try {
            $response = $this->command('EHLO ' . $this->heloHost, array(250));
        } catch (Exception $e) {
            $response = $this->command('HELO ' . $this->heloHost, array(250));
        }
        $this->extensions = array();
        foreach (preg_split('/\r\n/', trim($response)) as $i => $line) {
            if ($i === 0) {
                continue;
            }
            $parts = explode(' ', strtoupper(trim(substr($line, 4))), 2);
            $this->extensions[$parts[0]] = isset($parts[1]) ? $parts[1] : '';
        }
    }

    /**
     * AUTH PLAIN or LOGIN (whichever the server offers)
     */
    private function authenticate() {
        $mechanisms = isset($this->extensions['AUTH']) ? explode(' ', $this->extensions['AUTH']) : array('LOGIN');
        if ($this->encryption === 'none' && !in_array(strtolower($this->host), array('localhost', '127.0.0.1', '::1'), true)) {
            $this->log('Warning: sending SMTP credentials without encryption');
        }

        if (in_array('PLAIN', $mechanisms, true)) {
            $this->command('AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password), array(235), 'AUTH PLAIN [credentials hidden]');
            return;
        }
        $this->command('AUTH LOGIN', array(334));
        $this->command(base64_encode($this->username), array(334), '[username hidden]');
        $this->command(base64_encode($this->password), array(235), '[password hidden]');
    }

    /**
     * Send a command and check the reply code
     * @param string $command Raw command
     * @param array $expected Accepted reply codes
     * @param string|null $logAs What to log/report instead of the raw command (hides secrets)
     * @return string Server response
     */
    private function command($command, $expected, $logAs = null) {
        $label = $logAs !== null ? $logAs : $command;
        $this->log('>> ' . $label);

        if (@fwrite($this->socket, $command . "\r\n") === false) {
            throw new Exception('Failed to write to SMTP server (' . $label . ')');
        }
        $response = $this->getResponse();
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new Exception('SMTP command rejected (' . $label . '): ' . trim($response));
        }
        return $response;
    }

    /**
     * Read a (possibly multi-line) reply
     */
    private function getResponse() {
        $response = '';
        while (true) {
            $line = fgets($this->socket, 1024);
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                throw new Exception(!empty($meta['timed_out']) ? 'SMTP server timed out' : 'SMTP connection closed unexpectedly');
            }
            $response .= rtrim($line, "\r\n") . "\r\n";
            $this->log('<< ' . rtrim($line));
            // "250-..." continues, "250 ..." ends the reply
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return $response;
    }

    private function disconnect() {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    private function log($message) {
        if ($this->debug) {
            error_log('SMTPMailer: ' . $message);
        }
    }
}
