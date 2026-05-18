<?php
/**
 * Website Scraper
 * Fetches URL content via cURL, extracts text using DOMDocument/DOMXPath.
 * Can be included as a utility or called directly for testing.
 */

/**
 * Scrape a URL and extract clean text content.
 *
 * @param string $url The URL to scrape.
 * @return array Associative array with 'success', 'title', 'content', 'error'.
 */
function scrape_url($url) {
    // Validate URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return [
            'success' => false,
            'title' => '',
            'content' => '',
            'error' => 'Invalid URL format.'
        ];
    }

    // cURL setup
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING       => '', // Accept all encodings
    ]);

    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($html === false || $html === '') {
        return [
            'success' => false,
            'title' => '',
            'content' => '',
            'error' => $error ?: 'Empty response (HTTP ' . $http_code . ').'
        ];
    }

    if ($http_code >= 400) {
        return [
            'success' => false,
            'title' => '',
            'content' => '',
            'error' => 'HTTP error ' . $http_code . ' while fetching URL.'
        ];
    }

    // Parse HTML with DOMDocument
    $dom = new DOMDocument();
    // Suppress warnings for malformed HTML
    $old_libxml_errors = libxml_use_internal_errors(true);
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_use_internal_errors($old_libxml_errors);

    // Detect and fix encoding issues - convert all nodes to UTF-8
    $xpath = new DOMXPath($dom);

    // Extract title
    $title = '';
    $titleNodes = $xpath->query('//title');
    if ($titleNodes && $titleNodes->length > 0) {
        $title = trim($titleNodes->item(0)->textContent);
    }

    // Remove unwanted elements: script, style, noscript, iframe, nav, footer, header, aside
    foreach (['script', 'style', 'noscript', 'iframe', 'nav', 'footer', 'header', 'aside'] as $tag) {
        $nodes = $xpath->query('//' . $tag);
        foreach ($nodes as $node) {
            $node->parentNode->removeChild($node);
        }
    }

    // Remove comment nodes
    $comments = $xpath->query('//comment()');
    foreach ($comments as $comment) {
        $comment->parentNode->removeChild($comment);
    }

    // Extract text from body (falls back to full document if no <body>)
    $bodyNodes = $xpath->query('//body');
    if ($bodyNodes && $bodyNodes->length > 0) {
        $textContent = $bodyNodes->item(0)->textContent;
    } else {
        $textContent = $dom->documentElement ? $dom->documentElement->textContent : '';
    }

    // Clean up whitespace
    $textContent = preg_replace('/\s+/', ' ', $textContent);
    $textContent = preg_replace('/\n\s*\n/', "\n", $textContent);
    $textContent = preg_replace('/\n{3,}/', "\n\n", $textContent);
    $textContent = trim($textContent);

    // Limit content length to avoid blowing up LLM context
    $max_length = 15000;
    if (strlen($textContent) > $max_length) {
        $textContent = substr($textContent, 0, $max_length) . "\n\n[... content truncated at {$max_length} characters ...]";
    }

    if (empty($textContent)) {
        return [
            'success' => false,
            'title' => $title,
            'content' => '',
            'error' => 'No readable text content found on the page.'
        ];
    }

    return [
        'success' => true,
        'title' => $title,
        'content' => $textContent,
        'error' => ''
    ];
}

/**
 * If called directly as a script, test with a provided URL.
 * Usage: php scrape.php "https://example.com"
 */
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    header('Content-Type: application/json');
    $url = $_GET['url'] ?? ($argv[1] ?? '');
    if (empty($url)) {
        echo json_encode(['error' => 'Please provide a url parameter (GET or CLI).'], JSON_PRETTY_PRINT);
        exit;
    }
    $result = scrape_url($url);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
