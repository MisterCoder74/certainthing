<?php
/**
 * stripe_success.php
 * Stripe reindirizza qui dopo un pagamento riuscito.
 * Verifica la sessione, aggiorna users.json e la sessione PHP.
 */
require_once __DIR__ . '/../api/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$sessionId = trim($_GET['session_id'] ?? '');
if (!$sessionId) {
    header('Location: ../upgrade.php?error=no_session');
    exit;
}

// Verifica con Stripe
$stripeSession = stripe_api('GET', "checkout/sessions/{$sessionId}");

// Controlli di sicurezza
if (
    empty($stripeSession['id']) ||
    ($stripeSession['payment_status'] ?? '') !== 'paid' ||
    ($stripeSession['metadata']['user_id'] ?? '') !== $_SESSION['user_id']
) {
    error_log('[Stripe] Success verification failed for session: ' . $sessionId);
    header('Location: ../upgrade.php?error=payment_failed');
    exit;
}

// Aggiorna users.json
$users   = safe_read_json(USERS_FILE);
$today   = date('Y-m-d');
$updated = false;

foreach ($users as &$u) {
    if ($u['id'] === $_SESSION['user_id']) {
        $u['mode']               = 'paid';
        $u['status']             = 'enabled';
        $u['last_payment_at']    = $today;
        $u['stripe_customer_id'] = $stripeSession['customer'] ?? '';
        $updated = true;
        break;
    }
}
unset($u);

if ($updated) {
    safe_write_json(USERS_FILE, $users);
}

// Aggiorna la sessione corrente
$_SESSION['user_mode']        = 'paid';
$_SESSION['user_status']      = 'enabled';
$_SESSION['last_payment_at']  = $today;
unset($_SESSION['trial_ending_soon'], $_SESSION['trial_days_left']); 

header('Location: ../index.php?payment=success');
exit;
