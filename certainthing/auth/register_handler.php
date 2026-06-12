<?php
require_once __DIR__ . '/../api/config.php';

// Registration notification function
function sendRegistrationNotification($email, $userId) {
    $to = 'info@vivacitydesign.net, ademontis@hotmail.com';
    $subject = 'CertainThing - New User Registration';
    
    // Date and time
    $dateTime = date('d/m/Y H:i:s');
    $timezone = date('T');
    
    // Location detection via ip-api.com (free, no key needed)
    $location = 'Unknown';
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip && $ip !== '127.0.0.1' && $ip !== '::1') {
        // Take first IP if multiple (X-Forwarded-For can have a chain)
        $ip = explode(',', $ip)[0];
        $ip = trim($ip);
        $geo = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,city,regionName,country");
        if ($geo) {
            $data = json_decode($geo, true);
            if ($data && $data['status'] === 'success') {
                $parts = array_filter([$data['city'] ?? '', $data['regionName'] ?? '', $data['country'] ?? '']);
                $location = implode(', ', $parts) ?: 'Unknown';
            }
        }
    }
    
    $headers  = "From: certainthing_earlyaccess@vivacitydesign.net\r\n";
    $headers .= "Reply-To: " . $email . "\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    
    $body = "
    <div style='font-family: -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif; 
                max-width: 500px; padding: 20px; background: #0d1117; color: #c9d1d9; 
                border-radius: 10px; border: 1px solid #30363d;'>
        <h2 style='color: #58a6ff; margin-top: 0;'>🎉 New Registration</h2>
        <table style='width: 100%; border-collapse: collapse;'>
            <tr>
                <td style='padding: 8px 0; color: #8b949e;'>Email</td>
                <td style='padding: 8px 0; color: #e6edf3; font-weight: bold;'>" . htmlspecialchars($email) . "</td>
            </tr>
            <tr>
                <td style='padding: 8px 0; color: #8b949e;'>User ID</td>
                <td style='padding: 8px 0; color: #e6edf3;'>{$userId}</td>
            </tr>
            <tr>
                <td style='padding: 8px 0; color: #8b949e;'>Date & Time</td>
                <td style='padding: 8px 0; color: #e6edf3;'>{$dateTime} ({$timezone})</td>
            </tr>
            <tr>
                <td style='padding: 8px 0; color: #8b949e;'>Location</td>
                <td style='padding: 8px 0; color: #e6edf3;'>{$location}</td>
            </tr>
            <tr>
                <td style='padding: 8px 0; color: #8b949e;'>IP</td>
                <td style='padding: 8px 0; color: #e6edf3;'>{$ip}</td>
            </tr>
        </table>
    </div>";
    
    @mail($to, $subject, $body, $headers);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    if (empty($email) || empty($password)) {
        header('Location: ../register.php?error=All fields are required');
        exit;
    }
    $users = safe_read_json(USERS_FILE);
    foreach ($users as $user) {
        if ($user['email'] === $email) {
            header('Location: ../register.php?error=Email already exists');
            exit;
        }
    }
    $new_user = [
        'id' => 'usr_' . bin2hex(random_bytes(8)),
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'created_at' => date('c'),
        'mode' => 'trial',    
        'status' => 'enabled',    
        'sessions' => [],
        'last_payment_at' => '',
        'stripe_customer_id' => ''
    ];
    $users[] = $new_user;
    safe_write_json(USERS_FILE, $users);

    // Send notification email
    sendRegistrationNotification($email, $new_user['id']);

    $_SESSION['user_id'] = $new_user['id'];
    $_SESSION['user_email'] = $new_user['email'];
    $_SESSION['user_mode'] = $new_user['mode'];
    $_SESSION['user_status'] = $new_user['status']; 
    $_SESSION['user_created'] = $new_user['created_at']; 
    $_SESSION['last_payment_at'] = $new_user['last_payment_at'] ?? null;   
    header('Location: ../index.php');
    exit;
}
