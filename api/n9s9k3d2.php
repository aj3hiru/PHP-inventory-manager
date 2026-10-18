<?php
// api/fetch_og.php
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$url = $input['url'] ?? '';

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['success' => false, 'error' => 'Invalid URL']);
    exit;
}

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36\r\n" .
                    "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8\r\n"
    ]
]);

$html = @file_get_contents($url, false, $context);

if (!$html) {
    echo json_encode(['success' => false, 'error' => 'Failed to load page']);
    exit;
}

$doc = new DOMDocument();
libxml_use_internal_errors(true);
@$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
libxml_clear_errors();

$xpath = new DOMXPath($doc);

function cleanText($text) {
    return trim(preg_replace('/\s+/', ' ', $text));
}

// TITLE
$title = $xpath->evaluate('string(//meta[@property="og:title"]/@content)') ?:
         $xpath->evaluate('string(//meta[@name="twitter:title"]/@content)') ?:
         $xpath->evaluate('string(//title)') ?:
         $xpath->evaluate('string(//h1[1]') ?:
         $url;

// IMAGE
$image = $xpath->evaluate('string(//meta[@property="og:image"]/@content)') ?:
         $xpath->evaluate('string(//meta[@name="twitter:image"]/@content)') ?? '';

// SITE NAME
$site_name = $xpath->evaluate('string(//meta[@property="og:site_name"]/@content)') ?:
             parse_url($url, PHP_URL_HOST);

echo json_encode([
    'success' => true,
    'data' => [
        'title'       => cleanText($title),
        'image'       => $image,
        'site_name'   => cleanText($site_name)
    ]
]);