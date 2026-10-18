<?php
// FILE: /app/views/emails/task_reminder.php
// Data: user_name, task_title, due_date, priority, description, task_id
require __DIR__ . '/_helpers.php';
$priority = isset($data['priority']) ? (string)$data['priority'] : 'medium';
$priorityColors = array('urgent' => '#e74c3c', 'high' => '#f39c12', 'medium' => '#3498db', 'low' => '#95a5a6');
$priorityColor = isset($priorityColors[$priority]) ? $priorityColors[$priority] : '#95a5a6';
$description = isset($data['description']) ? trim((string)$data['description']) : '';
$preheader = 'Reminder: ' . (isset($data['task_title']) ? (string)$data['task_title'] : 'task due');
$content = '
<h2>Task Reminder</h2>
<p>Hi ' . $h('user_name', 'there') . ',</p>
<p>This is a reminder about your upcoming task:</p>
<div class="highlight">
    <strong>Task:</strong> ' . $h('task_title') . '<br>
    <strong>Due:</strong> ' . $date('due_date', 'F j, Y g:i A') . '<br>
    <strong>Priority:</strong> <span style="color: ' . $priorityColor . ';">' . htmlspecialchars(ucfirst($priority), ENT_QUOTES, 'UTF-8') . '</span>
</div>
' . ($description !== '' ? '<p><strong>Description:</strong><br>' . nl2br(htmlspecialchars($description, ENT_QUOTES, 'UTF-8')) . '</p>' : '') . '
<p style="text-align: center;"><a href="' . $link('tasks/view/' . (int)(isset($data['task_id']) ? $data['task_id'] : 0)) . '" class="btn">View Task</a></p>
<p>Stay productive!<br>SplashEstate CRM</p>
';
include __DIR__ . '/layout.php';
