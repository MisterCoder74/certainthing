<?php
/**
 * Email Helper
 */

function send_email($to, $subject, $message) {
    $headers = [
        'From' => 'no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'certainthing.app'),
        'Reply-To' => 'no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'certainthing.app'),
        'X-Mailer' => 'PHP/' . phpversion(),
        'Content-Type' => 'text/html; charset=UTF-8'
    ];

    $headerString = '';
    foreach ($headers as $key => $value) {
        $headerString .= "$key: $value\r\n";
    }

    // In a real environment, you might want to use a more robust email sending method
    // For now, we use the built-in mail() function as requested
    return mail($to, $subject, $message, $headerString);
}
