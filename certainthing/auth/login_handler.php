<?php
require_once __DIR__ . '/../api/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        header('Location: ../login.php?error=All fields are required');
        exit;
    }

    $users = safe_read_json(USERS_FILE);

    foreach ($users as $user) {
        if ($user['email'] === $email && password_verify($password, $user['password_hash'])) {
            // Process account control (check expiry, send reminders)
            $updated_user = process_account_control($user['id']);
            
            // Use updated user data for status check
            $current_user = $updated_user ?? $user;
            
            if (($current_user['status'] ?? 'enabled') !== 'enabled') {
                header('Location: ../login.php?error=Account disabled');
                exit;
            }
            
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['user_id'] = $current_user['id'];
            $_SESSION['user_email'] = $current_user['email'];
            header('Location: ../index.php');
            exit;
        }
    }

    header('Location: ../login.php?error=Invalid email or password');
    exit;
}
