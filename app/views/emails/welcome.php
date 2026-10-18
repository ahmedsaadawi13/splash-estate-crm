<?php
// FILE: /app/views/emails/welcome.php
// Data: first_name, email, company_name
require __DIR__ . '/_helpers.php';
$preheader = 'Your SplashEstate CRM account is ready';
$content = '
<h2>Welcome to SplashEstate CRM!</h2>
<p>Hi ' . $h('first_name', 'there') . ',</p>
<p>Thank you for joining SplashEstate CRM. We\'re excited to help you manage your real estate business more effectively.</p>
<p>Your account has been created:</p>
<div class="highlight">
    <strong>Email:</strong> ' . $h('email') . '<br>
    <strong>Company:</strong> ' . $h('company_name') . '
</div>
<p>Get started by exploring:</p>
<ul>
    <li>Lead Management - track and convert prospects</li>
    <li>Property Listings - manage your inventory</li>
    <li>Deal Pipeline - close more deals</li>
    <li>Task Management - stay organized</li>
</ul>
<p style="text-align: center;"><a href="' . $link('dashboard/index') . '" class="btn">Go to Dashboard</a></p>
<p>Best regards,<br>The SplashEstate Team</p>
';
include __DIR__ . '/layout.php';
