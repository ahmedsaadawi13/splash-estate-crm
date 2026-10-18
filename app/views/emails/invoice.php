<?php
// FILE: /app/views/emails/invoice.php
// Data: company_name, invoice_number, amount, due_date, status
require __DIR__ . '/_helpers.php';
$preheader = 'Invoice ' . (isset($data['invoice_number']) ? (string)$data['invoice_number'] : '');
$content = '
<h2>Invoice ' . $h('invoice_number') . '</h2>
<p>Hello ' . $h('company_name', 'there') . ',</p>
<p>Here is a summary of your SplashEstate CRM invoice:</p>
<div class="highlight">
    <strong>Invoice #:</strong> ' . $h('invoice_number') . '<br>
    <strong>Amount:</strong> ' . $money('amount') . '<br>
    <strong>Due date:</strong> ' . $date('due_date') . '<br>
    <strong>Status:</strong> ' . $h('status', 'pending') . '
</div>
<p style="text-align: center;"><a href="' . $link('subscription/invoices') . '" class="btn">View Payment History</a></p>
<p>Thank you for your business!<br>SplashEstate CRM</p>
';
include __DIR__ . '/layout.php';
