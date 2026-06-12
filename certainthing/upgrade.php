<?php
/**
 * upgrade.php
 * Pagina di upgrade al piano paid — stile dark coerente con CertainThing.
 */
require_once __DIR__ . '/api/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$error     = $_GET['error']     ?? '';
$cancelled = $_GET['cancelled'] ?? '';
$mode      = $_SESSION['user_mode'] ?? 'trial';

// Se già paid, reindirizza a index
if ($mode === 'paid') {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CertainThing – Upgrade</title>
    <link rel="stylesheet" href="assets/style.css?v=<?php echo time(); ?>">
    <style>
        /* ── Upgrade page ── */
        .upgrade-wrap {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: var(--bg-primary, #0f0f0f);
            padding: 40px 20px;
        }
        .upgrade-logo {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--text-primary, #f0f0f0);
            margin-bottom: 32px;
            letter-spacing: -0.5px;
        }
        .upgrade-logo span { color: var(--accent, #7047eb); }
        .upgrade-card {
            background: var(--bg-secondary, #1a1a1a);
            border: 1px solid var(--border, #2a2a2a);
            border-radius: 16px;
            padding: 40px 48px;
            max-width: 420px;
            width: 100%;
            text-align: center;
        }
        .plan-badge {
            display: inline-block;
            background: linear-gradient(135deg, #7047eb, #a78bfa);
            color: #fff;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            padding: 4px 14px;
            border-radius: 99px;
            margin-bottom: 20px;
        }
        .plan-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary, #f0f0f0);
            margin: 0 0 8px;
        }
        .plan-desc {
            color: var(--text-secondary, #888);
            font-size: 0.9rem;
            margin-bottom: 28px;
        }
        .plan-price {
            font-size: 3rem;
            font-weight: 800;
            color: var(--text-primary, #f0f0f0);
            line-height: 1;
            margin-bottom: 4px;
        }
        .plan-price sup { font-size: 1.4rem; vertical-align: super; font-weight: 600; }
        .plan-price sub { font-size: 1rem; color: var(--text-secondary, #888); font-weight: 400; }
        .plan-note {
            font-size: 0.78rem;
            color: var(--text-secondary, #888);
            margin-bottom: 28px;
        }
        .plan-features {
            list-style: none;
            padding: 0;
            margin: 0 0 32px;
            text-align: left;
        }
        .plan-features li {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-secondary, #aaa);
            font-size: 0.9rem;
            padding: 6px 0;
            border-bottom: 1px solid var(--border, #222);
        }
        .plan-features li:last-child { border-bottom: none; }
        .plan-features li .check {
            color: #22c55e;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .btn-upgrade {
            display: block;
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #7047eb, #a78bfa);
            color: #fff;
            font-size: 1rem;
            font-weight: 700;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            text-decoration: none;
            transition: opacity .2s, transform .1s;
        }
        .btn-upgrade:hover { opacity: .9; transform: translateY(-1px); }
        .btn-upgrade:active { transform: translateY(0); }
        .upgrade-back {
            margin-top: 20px;
            font-size: 0.85rem;
            color: var(--text-secondary, #888);
        }
        .upgrade-back a {
            color: var(--accent, #7047eb);
            text-decoration: none;
        }
        .upgrade-back a:hover { text-decoration: underline; }
        .upgrade-alert {
            background: #3b1a1a;
            border: 1px solid #7f2020;
            color: #f87171;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            text-align: left;
        }
        .upgrade-info {
            background: #1a2b3b;
            border: 1px solid #1e3a5f;
            color: #93c5fd;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            text-align: left;
        }
        .stripe-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-top: 18px;
            color: var(--text-secondary, #666);
            font-size: 0.78rem;
        }
    </style>
</head>
<body>
<div class="upgrade-wrap">

    <div class="upgrade-logo">✦ <span>CertainThing</span></div>

    <div class="upgrade-card">

        <?php if ($error === 'checkout'): ?>
        <div class="upgrade-alert">⚠️ Impossibile avviare il pagamento. Riprova tra qualche secondo.</div>
        <?php elseif ($error === 'payment_failed'): ?>
        <div class="upgrade-alert">⚠️ Il pagamento non è andato a buon fine. Nessun addebito è stato effettuato.</div>
        <?php elseif ($cancelled): ?>
        <div class="upgrade-info">ℹ️ Hai annullato il pagamento. Puoi riprovare quando vuoi.</div>
        <?php endif; ?>

        <div class="plan-badge">Piano Base</div>
        <h1 class="plan-title">CertainThing Full</h1>
        <p class="plan-desc">Accesso completo a tutte le funzionalità. Nessun limite.</p>

        <div class="plan-price">
            <sup>€</sup>4,99<sub>/mese</sub>
        </div>
        <p class="plan-note">IVA inclusa &middot; Rinnovo automatico mensile &middot; Cancella quando vuoi</p>

        <ul class="plan-features">
            <li><span class="check">✓</span> Generazione codice illimitata</li>
            <li><span class="check">✓</span> Sessioni e cronologia illimitata</li>
            <li><span class="check">✓</span> Deploy con un click</li>
            <li><span class="check">✓</span> Supporto prioritario</li>
            <li><span class="check">✓</span> Accesso alle nuove funzionalità</li>
        </ul>

        <form action="auth/stripe_checkout.php" method="POST">
            <button type="submit" class="btn-upgrade">🔒 Abbonati ora — €4,99/mese</button>
        </form>

        <div class="stripe-badge">
            🔐 Pagamento sicuro tramite Stripe
        </div>
    </div>

    <div class="upgrade-back">
        <a href="index.php">← Torna alla dashboard</a>
    </div>

</div>
</body>
</html>
