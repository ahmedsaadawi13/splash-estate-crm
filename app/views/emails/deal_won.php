<?php
// FILE: /app/views/emails/deal_won.php
// Data: agent_name, deal_title, client_name, deal_value, commission, closed_date, deal_id
require __DIR__ . '/_helpers.php';
$preheader = 'Deal closed: ' . (isset($data['deal_title']) ? (string)$data['deal_title'] : '');
$content = '
<h2>Congratulations! Deal Closed Successfully!</h2>
<p>Hi ' . $h('agent_name', 'there') . ',</p>
<p>Great news! The deal has been successfully closed.</p>
<div class="highlight">
    <strong>Deal:</strong> ' . $h('deal_title') . '<br>
    <strong>Client:</strong> ' . $h('client_name') . '<br>
    <strong>Deal Value:</strong> ' . $money('deal_value') . '<br>
    <strong>Commission:</strong> ' . $money('commission') . '<br>
    <strong>Closed Date:</strong> ' . $date('closed_date') . '
</div>
<p>Congratulations on closing this deal! Your hard work has paid off.</p>
<p style="text-align: center;"><a href="' . $link('deals/view/' . (int)(isset($data['deal_id']) ? $data['deal_id'] : 0)) . '" class="btn">View Deal Details</a></p>
<p>Keep up the excellent work!<br>SplashEstate CRM</p>
';
include __DIR__ . '/layout.php';
