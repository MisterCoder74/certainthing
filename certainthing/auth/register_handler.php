<?php
require_once __DIR__ . '/../api/config.php';

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
        'sessions' => [],
        'tier' => 'free',
        'status' => 'enabled',
        'last_tier_change_at' => date('c'),
        'reminders_sent' => []
    ];

    $users[] = $new_user;
    safe_write_json(USERS_FILE, $users);

    $_SESSION['user_id'] = $new_user['id'];
    $_SESSION['user_email'] = $new_user['email'];

    header('Location: ../index.php');
    exit;
}
