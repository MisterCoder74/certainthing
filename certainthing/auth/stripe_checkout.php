<?php
/**
 * stripe_checkout.php
 * Crea una Stripe Checkout Session e reindirizza l'utente alla pagina di pagamento.
 */
require_once __DIR__ . '/../api/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$session = stripe_api('POST', 'checkout/sessions', [
    'mode'                   => 'subscription',
    'line_items[0][price]'   => STRIPE_PRICE_ID,
    'line_items[0][quantity]'=> 1,
    'customer_email'         => $_SESSION['user_email'],
    'metadata[user_id]'      => $_SESSION['user_id'],
    'allow_promotion_codes'  => 'true',
    'success_url'            => BASE_URL . '/auth/stripe_success.php?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'             => BASE_URL . '/upgrade.php?cancelled=1',
]);

if (!empty($session['url'])) {
    header('Location: ' . $session['url']);
    exit;
}

// Errore nella creazione della sessione
error_log('[Stripe] Checkout session error: ' . json_encode($session));
header('Location: ../upgrade.php?error=checkout');
exit;
