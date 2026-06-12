<?php
/**
 * stripe_webhook.php
 * Endpoint webhook Stripe — gestisce il rinnovo mensile automatico.
 *
 * Nel Stripe Dashboard → Developers → Webhooks, aggiungi:
 * URL: https://www.vivacitydesign.net/certainThing/v1.2/certainthing/auth/stripe_webhook.php
 * Events: invoice.paid
 */
require_once __DIR__ . '/../api/config.php';

// Leggi il payload grezzo (necessario per verifica firma)
$payload   = (string) file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// ── Verifica firma Stripe ─────────────────────────────────────────────────
function verify_stripe_signature(string $payload, string $sigHeader, string $secret): bool {
    $parts = [];
    foreach (explode(',', $sigHeader) as $part) {
        $kv = explode('=', $part, 2);
        if (count($kv) === 2) {
            $parts[$kv[0]][] = $kv[1];
        }
    }
    $timestamp  = $parts['t'][0]  ?? '';
    $signatures = $parts['v1']    ?? [];
    if (!$timestamp || !$signatures) return false;

    // Rifiuta eventi troppo vecchi (tolleranza 5 minuti)
    if (abs(time() - (int) $timestamp) > 300) return false;

    $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}

if (!verify_stripe_signature($payload, $sigHeader, STRIPE_WEBHOOK_SECRET)) {
    http_response_code(400);
    exit('Invalid signature');
}

$event = json_decode($payload, true);
if (!$event) {
    http_response_code(400);
    exit('Invalid JSON');
}

// ── Gestione eventi ───────────────────────────────────────────────────────
if ($event['type'] === 'invoice.paid') {
    $invoice       = $event['data']['object'];
    $customerEmail = $invoice['customer_email'] ?? '';

    if ($customerEmail) {
        $users = safe_read_json(USERS_FILE);
        $today = date('Y-m-d');
        foreach ($users as &$u) {
            if (isset($u['email']) && strtolower($u['email']) === strtolower($customerEmail)) {
                $u['mode']            = 'paid';
                $u['status']          = 'enabled';
                $u['last_payment_at'] = $today;
                break;
            }
        }
        unset($u);
        safe_write_json(USERS_FILE, $users);
    }
}

http_response_code(200);
echo json_encode(['received' => true]);
