<?php
require_once __DIR__ . '/config.php';
check_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isset($_FILES['file'])) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];
$fileName = $file['name'];
$fileType = $file['type'];
$fileTmpPath = $file['tmp_name'];
$fileSize = $file['size'];

$response = [
    'name' => $fileName,
    'type' => $fileType,
    'size' => $fileSize
];

// Handle Images
if (preg_match('/^image\/(jpeg|png|webp)$/', $fileType)) {
    $data = file_get_contents($fileTmpPath);
    $base64 = base64_encode($data);
    $response['content'] = 'data:' . $fileType . ';base64,' . $base64;
    $response['is_image'] = true;
} 
// Handle Text Files
else {
    // Basic text file check - can be more sophisticated but let's stick to common ones
    // or just try to read it if it's not a binary-looking type
    $allowed_text_types = [
        'text/plain', 'text/html', 'text/css', 'application/javascript', 
        'application/x-php', 'text/x-php', 'application/json', 'text/markdown'
    ];
    
    // Also check extension for files that might not have a proper MIME type from the browser
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $text_extensions = ['txt', 'html', 'css', 'js', 'php', 'json', 'md', 'py', 'ts', 'sql', 'c', 'cpp'];

    if (in_array($fileType, $allowed_text_types) || in_array($ext, $text_extensions)) {
        $content = file_get_contents($fileTmpPath);
        
        // Truncate to 32,000 characters
        if (mb_strlen($content) > 32000) {
            $content = mb_substr($content, 0, 32000) . "\n\n[TRUNCATED DUE TO LENGTH]";
        }
        
        $response['content'] = $content;
        $response['is_image'] = false;
    } else {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['error' => 'Unsupported file type: ' . $fileType]);
        exit;
    }
}

header('Content-Type: application/json');
echo json_encode($response);
