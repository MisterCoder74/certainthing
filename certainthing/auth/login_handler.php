<?php
require_once __DIR__ . '/../api/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = $_POST['email']    ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        header('Location: ../login.php?error=All fields are required');
        exit;
    }

    $users = safe_read_json(USERS_FILE);

    foreach ($users as $index => $user) {
        if ($user['email'] !== $email || !password_verify($password, $user['password_hash'])) {
            continue;
        }

        // ── TRIAL CHECK ──────────────────────────────────────────────
        if ($user['mode'] === 'trial') {
            $created  = new DateTime($user['created_at']);
            $today    = new DateTime('today');
            $diffDays = (int) $today->diff($created)->days;

            // Trial scaduto (> 7 giorni)
            if ($diffDays > 7) {
                $users[$index]['status'] = 'disabled';
                safe_write_json(USERS_FILE, $users);
                header('Location: ../login.php?error=' . urlencode('Il periodo di prova è scaduto'));
                exit;
            }

            // Già disabilitato
            if ($user['status'] === 'disabled') {
                header('Location: ../login.php?error=' . urlencode('Il periodo di prova è scaduto'));
                exit;
            }

            // In scadenza (giorni 4–7)
            if ($diffDays >= 4) {
                $_SESSION['trial_ending_soon'] = true;
                $_SESSION['trial_days_left']   = 7 - $diffDays;
            }
        }
        // ── FINE TRIAL CHECK ─────────────────────────────────────────

        $_SESSION['user_id']      = $user['id'];
        $_SESSION['user_email']   = $user['email'];
        $_SESSION['user_mode']    = $user['mode'];
        $_SESSION['user_status']  = $user['status'];
        $_SESSION['user_created'] = $user['created_at'];
		$_SESSION['last_payment_at'] = $user['last_payment_at'] ?? null;            

        header('Location: ../index.php');
        exit;
    }

    header('Location: ../login.php?error=Invalid email or password');
    exit;
}

