<?php
// FILE: /app/views/emails/lead_notification.php
// Data: agent_name, lead_name, lead_email, lead_phone, interest_type, source, notes, lead_id
require __DIR__ . '/_helpers.php';
$preheader = 'A new lead has been assigned to you';
$notes = isset($data['notes']) ? trim((string)$data['notes']) : '';
$content = '
<h2>New Lead Assigned to You</h2>
<p>Hi ' . $h('agent_name', 'there') . ',</p>
<p>A new lead has been assigned to you. Please follow up promptly.</p>
<div class="highlight">
    <strong>Lead Name:</strong> ' . $h('lead_name') . '<br>
    <strong>Email:</strong> ' . $h('lead_email') . '<br>
    <strong>Phone:</strong> ' . $h('lead_phone') . '<br>
    <strong>Interest:</strong> ' . $h('interest_type') . '<br>
    <strong>Source:</strong> ' . $h('source') . '
</div>
' . ($notes !== '' ? '<p><strong>Notes:</strong> ' . nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8')) . '</p>' : '') . '
<p style="text-align: center;"><a href="' . $link('leads/view/' . (int)(isset($data['lead_id']) ? $data['lead_id'] : 0)) . '" class="btn">View Lead Details</a></p>
<p>Best regards,<br>SplashEstate CRM</p>
';
include __DIR__ . '/layout.php';
