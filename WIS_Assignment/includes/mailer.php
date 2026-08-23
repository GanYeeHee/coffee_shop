<?php
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

// TODO: replace these with your own Gmail address + App Password
// (Google Account -> Security -> 2-Step Verification -> App Passwords).
// Do not use your real account password here.
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'coffeeshopadminacc@gmail.com');
define('SMTP_PASSWORD', 'hegt rdvj lsjz kqhk');
define('SMTP_FROM_NAME', 'TAR Coffee');

/**
 * Returns a configured but unsent PHPMailer instance.
 */
function get_mail() {
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    $mail->isSMTP();
    $mail->SMTPAuth = true;
    $mail->Host = SMTP_HOST;
    $mail->Port = SMTP_PORT;
    $mail->Username = SMTP_USERNAME;
    $mail->Password = SMTP_PASSWORD;
    $mail->CharSet = 'UTF-8';

    $mail->setFrom(SMTP_USERNAME, SMTP_FROM_NAME);

    return $mail;
}

/**
 * Builds an absolute URL back to a script in this project, derived from the
 * current request (host + script folder) instead of a hardcoded host/port,
 * since email links must be absolute and this project's folder/port isn't fixed.
 */
function base_url($path = '') {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $root = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return $protocol . '://' . $_SERVER['HTTP_HOST'] . $root . '/' . ltrim($path, '/');
}
?>
