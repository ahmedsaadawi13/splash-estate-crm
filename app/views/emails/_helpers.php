<?php
// FILE: /app/views/emails/_helpers.php
// Template helpers (included by every email template; safe to include repeatedly).
// $data is the array passed to EmailService::renderTemplate().
$data = isset($data) && is_array($data) ? $data : array();
/** Escaped value of $data[$key] ('' when missing) */
$h = function ($key, $default = '') use ($data) {
    $value = isset($data[$key]) && $data[$key] !== null ? $data[$key] : $default;
    return htmlspecialchars(is_scalar($value) ? (string)$value : '', ENT_QUOTES, 'UTF-8');
};
/** Absolute, escaped application URL */
$link = function ($path) {
    return htmlspecialchars(BASE_URL . '/' . ltrim($path, '/'), ENT_QUOTES, 'UTF-8');
};
/** Escaped money value */
$money = function ($key) use ($data) {
    return '$' . number_format(isset($data[$key]) && is_numeric($data[$key]) ? (float)$data[$key] : 0, 2);
};
/** Escaped, formatted date ('' when missing/invalid) */
$date = function ($key, $format = 'F j, Y') use ($data) {
    $ts = !empty($data[$key]) ? strtotime((string)$data[$key]) : false;
    return $ts ? htmlspecialchars(date($format, $ts), ENT_QUOTES, 'UTF-8') : '';
};
