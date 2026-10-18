<?php
// FILE: /tests/cases/EmailTest.php
// Email: header injection, MIME structure, dot-stuffing, log driver, templates,
// and an SMTP conversation with a local fake server.

require_once APP_PATH . '/helpers/SMTPMailer.php';
require_once APP_PATH . '/helpers/EmailService.php';

/**
 * Fake SMTP server: appends every session's transcript to $argv[2]
 * @return array array(port, transcriptFile)
 */
function test_fake_smtp() {
    $dir = TEST_TMP_DIR . '/smtp';
    @mkdir($dir, 0777, true);
    $script = $dir . '/server.php';
    $transcript = $dir . '/transcript_' . bin2hex(random_bytes(3)) . '.log';
    file_put_contents($script, '<?php
// Serves SMTP sessions until killed (the first connection is the readiness probe)
$server = stream_socket_server("tcp://127.0.0.1:" . $argv[1], $errno, $errstr);
while ($client = @stream_socket_accept($server, 60)) {
    $out = fopen($argv[2], "a");
    $send = function ($line) use ($client, $out) { fwrite($client, $line . "\r\n"); fwrite($out, "S: " . $line . "\n"); };
    $send("220 fake.smtp ESMTP");
    $inData = false;
    while (($line = fgets($client)) !== false) {
        fwrite($out, "C: " . $line);
        if ($inData) {
            if ($line === ".\r\n") { $inData = false; $send("250 2.0.0 queued"); }
            continue;
        }
        $cmd = strtoupper(substr(trim($line), 0, 4));
        if ($cmd === "EHLO") { fwrite($client, "250-fake.smtp\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME\r\n"); }
        elseif ($cmd === "AUTH") { $send("235 2.7.0 Authentication successful"); }
        elseif ($cmd === "MAIL" || $cmd === "RCPT") { $send("250 OK"); }
        elseif ($cmd === "DATA") { $inData = true; $send("354 End data with <CR><LF>.<CR><LF>"); }
        elseif ($cmd === "QUIT") { $send("221 Bye"); break; }
        else { $send("502 Unknown"); }
    }
    fclose($client);
    fclose($out);
}
');
    $port = TestServer::startScript($script, array($transcript));
    return array($port, $transcript);
}

$suite->add('Email', 'sanitizeHeader()/sanitizeAddress() strip CR/LF and reject injected addresses', function () {
    assert_equals('Hello Bcc: victim@example.com', SMTPMailer::sanitizeHeader("Hello\r\nBcc: victim@example.com"));
    assert_equals('a@example.com', SMTPMailer::sanitizeAddress(" a@example.com \r\n"));
    assert_false(SMTPMailer::sanitizeAddress("a@example.com\r\nBcc: victim@example.com"));
    assert_false(SMTPMailer::sanitizeAddress('"Evil" <a@example.com>'));
    assert_false(SMTPMailer::sanitizeAddress('a@example.com,b@example.com'));
    assert_false(SMTPMailer::sanitizeAddress('not an email'));
});

$suite->add('Email', 'buildMessage() produces safe headers and a multipart/alternative body', function () {
    $message = SMTPMailer::buildMessage('to@example.com', "Hi\r\nBcc: victim@example.com", '<p>Hello <b>World</b></p><a href="https://x.test/a">link</a>', 'from@example.com', "Sender\r\nX-Evil: 1");
    list($headers, $body) = explode("\r\n\r\n", $message, 2);
    $lines = explode("\r\n", $headers);
    foreach ($lines as $line) {
        assert_false(stripos($line, 'Bcc:') === 0, 'no injected Bcc header');
        assert_false(stripos($line, 'X-Evil:') === 0, 'no injected header from the sender name');
    }
    assert_true((bool)preg_match('/^Date: .+$/m', $headers), 'Date header');
    assert_true((bool)preg_match('/^Message-ID: <[0-9a-f]{32}@example\.com>\r?$/m', $headers), 'Message-ID header');
    assert_contains('Content-Type: multipart/alternative; boundary=', $headers);
    assert_contains('Content-Type: text/plain; charset=UTF-8', $body);
    assert_contains('Content-Type: text/html; charset=UTF-8', $body);
    assert_contains('Hello World', quoted_printable_decode($body), 'plain-text alternative generated');
    assert_contains('link (https://x.test/a)', quoted_printable_decode($body));
});

$suite->add('Email', 'non-ASCII subjects are RFC 2047 encoded', function () {
    $message = SMTPMailer::buildMessage('to@example.com', 'Café déjà vu ✓', '<p>x</p>', 'from@example.com', 'Équipe');
    assert_true((bool)preg_match('/^Subject: =\?UTF-8\?B\?/m', $message));
    assert_true((bool)preg_match('/^From: =\?UTF-8\?B\?.+\?= <from@example\.com>\r?$/m', $message));
});

$suite->add('Email', 'dotStuff() doubles leading dots (SMTP transparency)', function () {
    assert_equals("..hidden\r\nline\r\n..\r\n...x", SMTPMailer::dotStuff(".hidden\nline\r\n.\r\n..x"));
    assert_equals("plain", SMTPMailer::dotStuff('plain'));
});

$suite->add('Email', 'log driver writes the full message to mail.log', function () {
    $dir = TEST_TMP_DIR . '/maillog_' . bin2hex(random_bytes(3));
    $service = new EmailService();
    $service->setDriver('log');
    $service->setLogDirectory($dir);

    assert_true($service->sendEmail('agent@example.com', "Report\r\nBcc: victim@example.com", '<p>Body</p>'));
    $log = file_get_contents($dir . '/mail.log');
    assert_contains('To: <agent@example.com>', $log);
    assert_contains('Subject: Report Bcc: victim@example.com', $log, 'CR/LF stripped from subject');
    assert_false((bool)preg_match('/^Bcc:/m', $log), 'no injected header line');
    assert_contains('sent (log)', file_get_contents($dir . '/email.log'));

    assert_false($service->sendEmail("agent@example.com\r\nBcc: victim@example.com", 'x', 'y'), 'injected recipient rejected');
    assert_contains('Invalid recipient', $service->getLastError());
});

$suite->add('Email', 'templates escape data and tolerate missing variables', function () {
    $service = new EmailService();
    $html = $service->renderTemplate('lead_notification', array(
        'agent_name' => '<script>alert(1)</script>',
        'lead_name' => 'Tom & "Jerry"',
        'notes' => "<img src=x onerror=alert(1)>\nsecond line",
        'lead_id' => '5 onmouseover=alert(1)'
    ));
    assert_true(is_string($html));
    assert_not_contains('<script>alert(1)</script>', $html);
    assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    assert_contains('Tom &amp; &quot;Jerry&quot;', $html);
    assert_not_contains('<img src=x', $html);
    assert_contains('/leads/view/5"', $html, 'ids are cast to int in links');

    foreach (array('welcome', 'task_reminder', 'deal_won', 'password_reset', 'invoice', 'lead_notification') as $template) {
        $output = $service->renderTemplate($template, array());
        assert_true(is_string($output) && strpos($output, 'SplashEstate CRM') !== false, $template . ' renders with no data');
    }
    $reset = $service->renderTemplate('password_reset', array('reset_url' => 'javascript:alert(1)'));
    assert_not_contains('javascript:', $reset, 'only http(s) reset links');
});

$suite->add('Email', 'template names cannot escape the emails directory', function () {
    $service = new EmailService();
    assert_false($service->renderTemplate('../layouts/header'));
    assert_false($service->renderTemplate('_helpers'));
    assert_false($service->renderTemplate('layout'));
    assert_false($service->renderTemplate('does_not_exist'));
    assert_false($service->send('a@example.com', 'x', '../../config/config'));
});

$suite->add('Email', 'SMTP session: EHLO, AUTH, envelope, dot-stuffed DATA, QUIT', function () {
    list($port, $transcript) = test_fake_smtp();
    $mailer = new SMTPMailer(array('host' => '127.0.0.1', 'port' => $port, 'username' => 'user', 'password' => 's3cret-pass', 'encryption' => 'none', 'timeout' => 5, 'helo_host' => 'crm.test'));
    $ok = $mailer->send('to@example.com', "Subject\r\nBcc: victim@example.com", "<p>Line one</p>\n.leading dot line", 'from@example.com', 'CRM');
    assert_true($ok, 'send succeeded: ' . $mailer->getLastError());

    usleep(200000);
    $log = file_get_contents($transcript);
    assert_contains('C: EHLO crm.test', $log);
    assert_contains('C: AUTH PLAIN ' . base64_encode("\0user\0s3cret-pass"), $log);
    assert_contains('C: MAIL FROM:<from@example.com>', $log);
    assert_contains('C: RCPT TO:<to@example.com>', $log);
    assert_contains("C: ..leading dot line", $log, 'dot-stuffed body line');
    assert_contains('C: QUIT', $log);
    assert_false((bool)preg_match('/^C: Bcc:/m', $log), 'no injected header');
});

$suite->add('Email', 'SMTP failures are reported without leaking the password', function () {
    $mailer = new SMTPMailer(array('host' => '127.0.0.1', 'port' => 9, 'username' => 'user', 'password' => 'super-secret-pw', 'encryption' => 'none', 'timeout' => 2));
    $started = microtime(true);
    assert_false($mailer->send('to@example.com', 'x', 'y', 'from@example.com'));
    assert_true(microtime(true) - $started < 3, 'connection failure is fast');
    assert_not_contains('super-secret-pw', $mailer->getLastError());
    assert_contains('Failed to connect', $mailer->getLastError());
});
