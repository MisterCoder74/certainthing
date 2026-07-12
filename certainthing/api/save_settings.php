<?php
require_once __DIR__ . '/config.php';
check_auth();

header('Content-Type: application/json');

const ALLOWED_MODELS = ['gpt-5-nano', 'gpt-5.4-nano'];

function _settings_is_paid(): bool {
    return ($_SESSION['user_mode'] ?? 'trial') === 'paid';
}

function _settings_public_payload(array $settings, bool $isPaid): array {
    return [
        'github_repo'     => $settings['github_repo'],
        'github_pat_set'  => $settings['github_pat'] !== '',
        'github_pat_mask' => mask_secret_value($settings['github_pat']),
        'model'           => $isPaid ? $settings['model'] : 'gpt-5-nano',
        'voice_language'  => $settings['voice_language'],
        'is_paid'         => $isPaid,
        'allowed_models'  => $isPaid ? ALLOWED_MODELS : ['gpt-5-nano'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(_settings_public_payload(get_user_settings(), _settings_is_paid()));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

$isPaid  = _settings_is_paid();
$partial = [];

if (array_key_exists('github_repo', $input)) {
    $partial['github_repo'] = trim((string) $input['github_repo']);
}

if (array_key_exists('github_pat', $input)) {
    $pat = trim((string) $input['github_pat']);
    // '__unchanged__' = il client non ha ritoccato il campo (mostra solo la maschera): non sovrascrivere
    if ($pat !== '__unchanged__') {
        $partial['github_pat'] = $pat;
    }
}

if (array_key_exists('model', $input)) {
    $model = trim((string) $input['model']);
    $partial['model'] = ($isPaid && in_array($model, ALLOWED_MODELS, true)) ? $model : 'gpt-5-nano';
}

if (array_key_exists('voice_language', $input)) {
    $lang = trim((string) $input['voice_language']);
    // Vuoto = default OS/browser, oppure tag lingua standard tipo "it-IT"
    $partial['voice_language'] = ($lang === '' || preg_match('/^[a-z]{2}-[A-Z]{2}$/', $lang)) ? $lang : '';
}

if (empty($partial)) {
    http_response_code(400);
    echo json_encode(['error' => 'No valid fields to save']);
    exit;
}

if (!save_user_settings($partial)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save settings']);
    exit;
}

echo json_encode(array_merge(
    ['success' => true],
    _settings_public_payload(get_user_settings(), $isPaid)
));
