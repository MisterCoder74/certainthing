<?php
/**
 * Standalone Scrape & Export
 * One-shot, no-storage workflow built on scrape.php's scrape_url().
 *
 * POST ?action=fetch    body { url }                         -> scrape a single URL, return JSON result
 * POST ?action=download body { format: 'md'|'txt', items }   -> single file (1 item) or ZIP (2+ items) download
 *
 * No content is persisted server-side: "download" packages exactly the
 * title/content the client already received from "fetch" — the client is
 * the one driving one request per URL (see scrape_export UI in app.js),
 * this endpoint never chains multiple external fetches in one request.
 */
require_once __DIR__ . '/config.php';
check_auth();

require_once __DIR__ . '/scrape.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

if ($action === 'fetch') {
    header('Content-Type: application/json');

    $url = trim((string) ($input['url'] ?? ''));
    if ($url === '') {
        http_response_code(400);
        echo json_encode(['error' => 'url is required']);
        exit;
    }

    $result = scrape_url($url);
    $wordCount = $result['success'] ? str_word_count(strip_tags($result['content'])) : 0;

    echo json_encode([
        'success'    => $result['success'],
        'url'        => $url,
        'title'      => $result['title'],
        'content'    => $result['content'],
        'error'      => $result['error'],
        'wordCount'  => $wordCount,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'download') {
    $format = (string) ($input['format'] ?? 'md');
    if (!in_array($format, ['md', 'txt'], true)) $format = 'md';
    $ext = $format === 'md' ? 'md' : 'txt';

    $items = $input['items'] ?? null;
    if (!is_array($items) || empty($items)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'items array is required']);
        exit;
    }
    // Hard cap mirrors the batch-fetch UI cap — guards against an oversized ZIP request.
    if (count($items) > 25) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Too many items (max 25)']);
        exit;
    }

    $files = [];
    foreach ($items as $item) {
        $url     = trim((string) ($item['url'] ?? ''));
        $title   = trim((string) ($item['title'] ?? ''));
        $content = (string) ($item['content'] ?? '');
        if ($url === '' || $content === '') continue;

        $files[] = [
            'name' => scrape_export_filename($title, $url, $ext),
            'body' => scrape_export_render($title, $url, $content, $format),
        ];
    }

    if (empty($files)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'No valid items to export']);
        exit;
    }

    if (count($files) === 1) {
        $file = $files[0];
        header('Content-Type: ' . ($format === 'md' ? 'text/markdown' : 'text/plain') . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file['name'] . '"');
        header('Content-Length: ' . strlen($file['body']));
        echo $file['body'];
        exit;
    }

    // Multiple items -> ZIP, same tempnam/ZipArchive pattern as export.php.
    $tempZip = tempnam(sys_get_temp_dir(), 'ct_scrape_export_');
    if (!$tempZip) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Failed to create temporary file']);
        exit;
    }

    $zip = new ZipArchive();
    if ($zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Failed to initialize ZipArchive']);
        @unlink($tempZip);
        exit;
    }

    $usedNames = [];
    foreach ($files as $file) {
        $name = $file['name'];
        // De-duplicate names that collided after sanitization (e.g. same title twice).
        $base = $name; $i = 2;
        while (isset($usedNames[$name])) {
            $name = preg_replace('/(\.[a-z]+)$/', "-{$i}\$1", $base);
            $i++;
        }
        $usedNames[$name] = true;
        $zip->addFromString($name, $file['body']);
    }
    $zip->close();

    if (!file_exists($tempZip) || filesize($tempZip) === 0) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'ZIP file generation failed']);
        @unlink($tempZip);
        exit;
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="scrape_export_' . date('Y-m-d_His') . '.zip"');
    header('Content-Length: ' . filesize($tempZip));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    readfile($tempZip);
    unlink($tempZip);
    exit;
}

http_response_code(400);
header('Content-Type: application/json');
echo json_encode(['error' => 'Unknown or missing action']);
exit;

/**
 * Build a safe, readable file name from the page title (falls back to the
 * URL host+path) — mirrors export.php's traversal-safe sanitization.
 */
function scrape_export_filename($title, $url, $ext) {
    $base = $title !== '' ? $title : preg_replace('#^https?://#', '', $url);
    $base = str_replace(['..', '\\', '/'], '-', $base);
    $base = preg_replace('/[^A-Za-z0-9 _\-]/', '', $base);
    $base = trim(preg_replace('/\s+/', '_', trim($base)));
    if ($base === '') $base = 'page';
    if (strlen($base) > 80) $base = substr($base, 0, 80);
    return $base . '.' . $ext;
}

/**
 * Render one scraped page as Markdown or plain text, with a small header
 * carrying the source URL and title for traceability.
 */
function scrape_export_render($title, $url, $content, $format) {
    if ($format === 'md') {
        $heading = $title !== '' ? $title : $url;
        return "# {$heading}\n\nSource: {$url}\n\n---\n\n{$content}\n";
    }
    $heading = $title !== '' ? $title : $url;
    return "{$heading}\nSource: {$url}\n\n{$content}\n";
}
