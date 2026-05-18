<?php
/**
 * API: Export Files as ZIP
 */

require_once 'config.php';

// Ensure user is authenticated
check_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['files']) || !is_array($input['files'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input: files array expected']);
    exit;
}

// Create a temporary file for the ZIP
$tempZip = tempnam(sys_get_temp_dir(), 'ct_export_');
if (!$tempZip) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create temporary file']);
    exit;
}

$zip = new ZipArchive();
if ($zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to initialize ZipArchive']);
    @unlink($tempZip);
    exit;
}

// Add files to the ZIP
foreach ($input['files'] as $file) {
    $name = $file['name'] ?? 'unnamed_file';
    $content = $file['content'] ?? '';
    
    // Simple path sanitization to prevent directory traversal in the ZIP
    $name = ltrim(str_replace(['..', '\\'], ['', '/'], $name), '/');
    
    if (!empty($name)) {
        $zip->addFromString($name, $content);
    }
}

$zip->close();

// Check if ZIP was created
if (!file_exists($tempZip) || filesize($tempZip) === 0) {
    http_response_code(500);
    echo json_encode(['error' => 'ZIP file generation failed']);
    @unlink($tempZip);
    exit;
}

// Send the ZIP file
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="certainthing_export_' . date('Y-m-d_His') . '.zip"');
header('Content-Length: ' . filesize($tempZip));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($tempZip);

// Delete the temporary file
unlink($tempZip);
exit;
