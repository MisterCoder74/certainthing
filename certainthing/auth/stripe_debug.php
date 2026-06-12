<?php
/**
 * stripe_debug.php — FILE TEMPORANEO DI DIAGNOSTICA
 * ELIMINALO DOPO L'USO — espone info sensibili
 */
session_start();

// ── 1. Prova a caricare config ────────────────────────────────────────────
$config_paths = [
    __DIR__ . '/../api/config.php',   // certainthing/api/config.php
    __DIR__ . '/../config.php',        // certainthing/config.php
];
$config_loaded = false;
foreach ($config_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $config_loaded = $path;
        break;
    }
}

echo "<h3>1. Config.php</h3>";
if ($config_loaded) {
    echo "✅ Caricato da: <code>" . htmlspecialchars($config_loaded) . "</code><br>";
} else {
    echo "❌ NON TROVATO in nessun percorso!<br>";
    foreach ($config_paths as $p) echo "  cercato: <code>" . htmlspecialchars($p) . "</code><br>";
    exit;
}

// ── 2. Controlla costanti ────────────────────────────────────────────────
echo "<h3>2. Costanti Stripe</h3>";
$keys = ['STRIPE_SECRET_KEY', 'STRIPE_PUBLISHABLE_KEY', 'STRIPE_PRICE_ID', 'BASE_URL'];
foreach ($keys as $k) {
    $val = defined($k) ? constant($k) : null;
    if (!$val) {
        echo "❌ <b>$k</b>: non definita<br>";
    } elseif (strpos($val, 'INSERISCI') !== false) {
        echo "⚠️ <b>$k</b>: ancora placeholder!<br>";
    } else {
        // Mostra solo inizio e fine, non la chiave intera
        $masked = substr($val, 0, 12) . '...' . substr($val, -4);
        echo "✅ <b>$k</b>: <code>" . htmlspecialchars($masked) . "</code><br>";
    }
}

// ── 3. Chiama Stripe e mostra risposta raw ───────────────────────────────
echo "<h3>3. Test chiamata API Stripe</h3>";
if (!defined('STRIPE_SECRET_KEY') || strpos(STRIPE_SECRET_KEY, 'INSERISCI') !== false) {
    echo "⚠️ Chiave non valida, skip chiamata API.<br>";
    exit;
}

$ch = curl_init("https://api.stripe.com/v1/checkout/sessions");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
    CURLOPT_HTTPHEADER     => ['Stripe-Version: 2023-10-16'],
    CURLOPT_POSTFIELDS     => http_build_query([
        'mode'                    => 'subscription',
        'line_items[0][price]'    => STRIPE_PRICE_ID,
        'line_items[0][quantity]' => 1,
        'customer_email'          => 'test@example.com',
        'success_url'             => BASE_URL . '/auth/stripe_success.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'              => BASE_URL . '/upgrade.php?cancelled=1',
    ]),
]);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Status: <b>$http_code</b><br>";
$decoded = json_decode($response, true);
if (isset($decoded['error'])) {
    echo "❌ Errore Stripe: <pre>" . htmlspecialchars(json_encode($decoded['error'], JSON_PRETTY_PRINT)) . "</pre>";
} elseif (isset($decoded['url'])) {
    echo "✅ Checkout session creata OK! URL: <code>" . htmlspecialchars(substr($decoded['url'], 0, 60)) . "...</code><br>";
} else {
    echo "Risposta raw: <pre>" . htmlspecialchars(json_encode($decoded, JSON_PRETTY_PRINT)) . "</pre>";
}

// ── 4. Sessione ──────────────────────────────────────────────────────────
echo "<h3>4. Sessione PHP</h3>";
echo "session_id: <code>" . session_id() . "</code><br>";
echo "user_id: <code>" . ($_SESSION['user_id'] ?? '❌ non impostato') . "</code><br>";
echo "user_email: <code>" . ($_SESSION['user_email'] ?? '❌ non impostato') . "</code><br>";
