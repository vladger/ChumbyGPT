<?php
// proxy.php

// Check if the URL parameter is provided
if (!isset($_GET['url'])) {
    http_response_code(400);
    echo "URL parameter is missing.";
    exit;
}

$url = $_GET['url'];

// Simple URL validation
if (!preg_match('~^https?://~i', $url)) {
    http_response_code(400);
    echo "Invalid URL provided. Must start with http:// or https://";
    exit;
}

// Generate a cache key
$cacheKey = md5($url);
$cacheDir = __DIR__ . '/cache/';
$cacheFile = $cacheDir . $cacheKey;

// Create cache directory if it doesn't exist
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

// Check if the cached file exists and is still valid (less than 1 hour old)
if (file_exists($cacheFile) && time() - filemtime($cacheFile) < 3600) {
    // Serve the cached content
    header('Content-Type: text/html; charset=UTF-8');
    readfile($cacheFile);
    exit;
}

// Initialize curl
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_ENCODING => '',  // Accept all encodings
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_SSL_VERIFYPEER => false,
]);

// Execute the request
$content = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$error = curl_error($ch);
curl_close($ch);

// Check if the request was successful
if ($httpCode !== 200) {
    http_response_code($httpCode);
    echo "Failed to fetch the content from the provided URL. Error: " . $error;
    exit;
}

// Determine the character encoding

// 1. First try from Content-Type header
$charset = null;
if (preg_match('/charset=([^;]+)/i', $contentType, $matches)) {
    $charset = trim($matches[1]);
}

// 2. If not in headers, try to detect from HTML meta tags
if (!$charset && preg_match('/<meta[^>]+charset=["\']?([^"\'>]+)/i', $content, $matches)) {
    $charset = trim($matches[1]);
}

// 3. Try to detect encoding from content
if (!$charset || strtoupper($charset) == 'ISO-8859-1') { // ISO-8859-1 is often a default/fallback
    // Common Cyrillic encodings
    $encodings = ['UTF-8', 'Windows-1251', 'KOI8-R', 'CP866', 'ISO-8859-5'];
    
    // Try to detect if the content has Cyrillic characters
    $hasCyrillic = false;
    foreach ($encodings as $enc) {
        $sample = mb_convert_encoding(substr($content, 0, 1000), 'UTF-8', $enc);
        if (preg_match('/[А-Яа-яЁё]/u', $sample)) {
            $charset = $enc;
            $hasCyrillic = true;
            break;
        }
    }
    
    // If no Cyrillic detected, default to UTF-8
    if (!$hasCyrillic) {
        $charset = 'UTF-8';
    }
}

// Convert content to UTF-8 if needed
if ($charset && strtoupper($charset) != 'UTF-8') {
    $content = mb_convert_encoding($content, 'UTF-8', $charset);
    
    // Ensure HTML and meta tags reflect UTF-8
    $content = preg_replace(
        '/<meta[^>]+charset=["\']?[^"\'>]+["\']?/i',
        '<meta charset="UTF-8"',
        $content
    );
}

// Make sure we have a charset meta tag
if (!preg_match('/<meta[^>]+charset/i', $content)) {
    $content = preg_replace(
        '/(<head[^>]*>)/i',
        '$1<meta charset="UTF-8">',
        $content,
        1
    );
    
    // If no head tag, add one
    if (!preg_match('/<head/i', $content)) {
        $content = preg_replace(
            '/(<html[^>]*>)/i',
            '$1<head><meta charset="UTF-8"></head>',
            $content,
            1
        );
        
        // If no html tag, add everything
        if (!preg_match('/<html/i', $content)) {
            $content = '' . $content . '';
        }
    }
}

// Proxy base URL for relative links
$proxyBaseUrl = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'] . '?url=';

// Process URLs without using DOM (more reliable for non-ASCII content)
// Handle links
$content = preg_replace_callback(
    '/<a\s+[^>]*href=["\'](https?:\/\/[^"\']+)["\'][^>]*>/i',
    function($matches) use ($proxyBaseUrl) {
        $url = htmlspecialchars_decode($matches[1]);
        return str_replace(
            $matches[1],
            $proxyBaseUrl . urlencode($url),
            $matches[0]
        );
    },
    $content
);

// Handle relative links
$baseUrlParts = parse_url($url);
$baseUrl = $baseUrlParts['scheme'] . '://' . $baseUrlParts['host'];
if (!empty($baseUrlParts['port'])) {
    $baseUrl .= ':' . $baseUrlParts['port'];
}

// Replace relative URLs in links
$content = preg_replace_callback(
    '/<a\s+[^>]*href=["\'](\/[^"\']+)["\'][^>]*>/i',
    function($matches) use ($proxyBaseUrl, $baseUrl) {
        $relUrl = htmlspecialchars_decode($matches[1]);
        $absUrl = $baseUrl . $relUrl;
        return str_replace(
            $matches[1],
            $proxyBaseUrl . urlencode($absUrl),
            $matches[0]
        );
    },
    $content
);

// Handle images and other resources with src attribute
$elements = ['img', 'script', 'iframe', 'source', 'audio', 'video', 'embed'];
foreach ($elements as $tag) {
    // Absolute URLs
    $content = preg_replace_callback(
        '/<' . $tag . '\s+[^>]*src=["\'](https?:\/\/[^"\']+)["\'][^>]*>/i',
        function($matches) use ($proxyBaseUrl) {
            $url = htmlspecialchars_decode($matches[1]);
            return str_replace(
                $matches[1],
                $proxyBaseUrl . urlencode($url),
                $matches[0]
            );
        },
        $content
    );
    
    // Relative URLs (starting with /)
    $content = preg_replace_callback(
        '/<' . $tag . '\s+[^>]*src=["\'](\/[^"\']+)["\'][^>]*>/i',
        function($matches) use ($proxyBaseUrl, $baseUrl) {
            $relUrl = htmlspecialchars_decode($matches[1]);
            $absUrl = $baseUrl . $relUrl;
            return str_replace(
                $matches[1],
                $proxyBaseUrl . urlencode($absUrl),
                $matches[0]
            );
        },
        $content
    );
}

// Handle CSS links
$content = preg_replace_callback(
    '/<link\s+[^>]*href=["\'](https?:\/\/[^"\']+)["\'][^>]*>/i',
    function($matches) use ($proxyBaseUrl) {
        $url = htmlspecialchars_decode($matches[1]);
        return str_replace(
            $matches[1],
            $proxyBaseUrl . urlencode($url),
            $matches[0]
        );
    },
    $content
);

// Handle relative CSS links
$content = preg_replace_callback(
    '/<link\s+[^>]*href=["\'](\/[^"\']+)["\'][^>]*>/i',
    function($matches) use ($proxyBaseUrl, $baseUrl) {
        $relUrl = htmlspecialchars_decode($matches[1]);
        $absUrl = $baseUrl . $relUrl;
        return str_replace(
            $matches[1],
            $proxyBaseUrl . urlencode($absUrl),
            $matches[0]
        );
    },
    $content
);

// Handle forms
$content = preg_replace_callback(
    '/<form\s+[^>]*action=["\'](https?:\/\/[^"\']+)["\'][^>]*>/i',
    function($matches) use ($proxyBaseUrl) {
        $url = htmlspecialchars_decode($matches[1]);
        return str_replace(
            $matches[1],
            $proxyBaseUrl . urlencode($url),
            $matches[0]
        );
    },
    $content
);

// Handle relative form actions
$content = preg_replace_callback(
    '/<form\s+[^>]*action=["\'](\/[^"\']+)["\'][^>]*>/i',
    function($matches) use ($proxyBaseUrl, $baseUrl) {
        $relUrl = htmlspecialchars_decode($matches[1]);
        $absUrl = $baseUrl . $relUrl;
        return str_replace(
            $matches[1], 
            $proxyBaseUrl . urlencode($absUrl),
            $matches[0]
        );
    },
    $content
);

// Save to cache
file_put_contents($cacheFile, $content);

// Output with correct headers
header('Content-Type: text/html; charset=UTF-8');
echo $content;