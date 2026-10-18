<?php
// FILE: /app/views/emails/password_reset.php
// Data: first_name, reset_url (absolute URL with the one-time token), expires_minutes
require __DIR__ . '/_helpers.php';
$resetUrl = isset($data['reset_url']) ? (string)$data['reset_url'] : '';
// Only http(s) links are rendered
$resetUrl = preg_match('#^https?://#i', $resetUrl) ? htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') : '';
$minutes = isset($data['expires_minutes']) ? (int)$data['expires_minutes'] : 60;
$preheader = 'Reset your SplashEstate CRM password';
$content = '
<h2>Password Reset Request</h2>
<p>Hi ' . $h('first_name', 'there') . ',</p>
<p>We received a request to reset the password of your SplashEstate CRM account.</p>
' . ($resetUrl !== '' ? '<p style="text-align: center;"><a href="' . $resetUrl . '" class="btn">Reset Password</a></p>
<p class="small">If the button does not work, copy this link into your browser:<br>' . $resetUrl . '</p>' : '') . '
<p>This link expires in ' . $minutes . ' minutes and can be used once.</p>
<p>If you did not request a password reset, you can ignore this email - your password will not change.</p>
<p>SplashEstate CRM</p>
';
include __DIR__ . '/layout.php';
