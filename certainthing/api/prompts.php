<?php
/**
 * "My Prompts" — per-user custom prompt library.
 * GET                    -> { categories: [...] } (current user prompts)
 * GET  ?action=export    -> downloads the same payload as a .json file
 * POST ?action=add       -> body { category, icon?, title, text } -> adds one prompt
 * POST ?action=import    -> body { categories: [...] } (or raw prompts_library.json shape)
 *                            -> merges into the user's library, dedup by title/category
 * POST ?action=delete    -> body { category, title } -> removes one prompt
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/audit_log.php';
check_auth();

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'export') {
    $data = get_user_prompts();
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="my_prompts_export.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(get_user_prompts());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = $_POST;

if ($action === 'add') {
    $category = trim((string) ($input['category'] ?? 'My Prompts'));
    $icon     = trim((string) ($input['icon'] ?? '⭐'));
    $title    = trim((string) ($input['title'] ?? ''));
    $text     = trim((string) ($input['text'] ?? ''));

    if ($title === '' || $text === '') {
        http_response_code(400);
        echo json_encode(['error' => 'title and text are required']);
        exit;
    }

    $incoming = sanitize_prompt_categories([[
        'name' => $category !== '' ? $category : 'My Prompts',
        'icon' => $icon,
        'prompts' => [['title' => $title, 'text' => $text]],
    ]]);

    $existing = get_user_prompts();
    $merged   = merge_prompt_categories($existing['categories'], $incoming);

    if (!save_user_prompts(['categories' => $merged])) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save prompt']);
        exit;
    }

    audit_log_event('prompt_add', ['category' => $category, 'title' => $title]);
    echo json_encode(['success' => true, 'categories' => $merged]);
    exit;
}

if ($action === 'import') {
    $categoriesRaw = $input['categories'] ?? null;
    if (!is_array($categoriesRaw)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid file: expected a "categories" array']);
        exit;
    }

    $incoming = sanitize_prompt_categories($categoriesRaw);
    if (empty($incoming)) {
        http_response_code(400);
        echo json_encode(['error' => 'No valid prompts found in the imported file']);
        exit;
    }

    $existing = get_user_prompts();
    $merged   = merge_prompt_categories($existing['categories'], $incoming);

    if (!save_user_prompts(['categories' => $merged])) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save imported prompts']);
        exit;
    }

    $importedCount = array_sum(array_map(fn($c) => count($c['prompts']), $incoming));
    audit_log_event('prompt_import', ['imported' => $importedCount]);
    echo json_encode(['success' => true, 'imported' => $importedCount, 'categories' => $merged]);
    exit;
}

if ($action === 'delete') {
    $category = trim((string) ($input['category'] ?? ''));
    $title    = trim((string) ($input['title'] ?? ''));
    if ($category === '' || $title === '') {
        http_response_code(400);
        echo json_encode(['error' => 'category and title are required']);
        exit;
    }

    $existing = get_user_prompts();
    $changed  = false;
    $result   = [];
    foreach ($existing['categories'] as $cat) {
        if (mb_strtolower($cat['name']) === mb_strtolower($category)) {
            $before = count($cat['prompts']);
            $cat['prompts'] = array_values(array_filter(
                $cat['prompts'],
                fn($p) => mb_strtolower($p['title']) !== mb_strtolower($title)
            ));
            if (count($cat['prompts']) !== $before) $changed = true;
            if (empty($cat['prompts'])) continue; // drop empty category
        }
        $result[] = $cat;
    }

    if (!$changed) {
        http_response_code(404);
        echo json_encode(['error' => 'Prompt not found']);
        exit;
    }

    if (!save_user_prompts(['categories' => $result])) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save changes']);
        exit;
    }

    audit_log_event('prompt_delete', ['category' => $category, 'title' => $title]);
    echo json_encode(['success' => true, 'categories' => $result]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown or missing action']);
