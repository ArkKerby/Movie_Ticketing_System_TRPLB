<?php
/**
 * Vercel PHP Router
 * 
 * This file acts as the single entry point for all requests on Vercel.
 * It routes incoming requests to the correct PHP file in the project root.
 */

// Get the requested URI path
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);

// Remove leading slash
$path = ltrim($path, '/');

// Default to the main page
if ($path === '' || $path === '/') {
    $path = 'TICKETIX NI CLAIRE.php';
}

// Map clean URLs to PHP files if needed
$routeMap = [
    'index' => 'TICKETIX NI CLAIRE.php',
    'index.php' => 'TICKETIX NI CLAIRE.php',
    'home' => 'TICKETIX NI CLAIRE.php',
];

// Check route map first
if (isset($routeMap[$path])) {
    $path = $routeMap[$path];
}

// Build the full file path (go up one directory from /api/)
$filePath = __DIR__ . '/../' . $path;

// Security: prevent directory traversal
$realBase = realpath(__DIR__ . '/../');
$realFile = realpath($filePath);

if ($realFile === false || strpos($realFile, $realBase) !== 0) {
    // Check if it might be a file that doesn't exist yet (e.g., during first deploy)
    // Normalize and check
    $normalizedPath = realpath(dirname($filePath)) . DIRECTORY_SEPARATOR . basename($filePath);
    $normalizedBase = realpath(__DIR__ . '/../');
    
    if ($normalizedBase === false || strpos(dirname($filePath), '..') !== false) {
        http_response_code(404);
        echo '404 - Page Not Found';
        exit;
    }
}

// Handle static files (CSS, JS, images) - serve them directly
$extension = pathinfo($path, PATHINFO_EXTENSION);
$staticExtensions = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'webp', 'woff', 'woff2', 'ttf', 'eot', 'mp4', 'webm'];

if (in_array(strtolower($extension), $staticExtensions)) {
    if (file_exists($filePath)) {
        $mimeTypes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
        ];
        
        $mime = $mimeTypes[strtolower($extension)] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        readfile($filePath);
        exit;
    } else {
        http_response_code(404);
        echo '404 - Static file not found';
        exit;
    }
}

// Handle PHP files
if (strtolower($extension) === 'php' || $extension === '') {
    // If no extension, try adding .php
    if ($extension === '' && !file_exists($filePath)) {
        $filePath .= '.php';
        $path .= '.php';
    }
    
    if (file_exists($filePath)) {
        // Change to the project root directory so relative includes work
        chdir(__DIR__ . '/../');
        
        // Include the PHP file
        require $filePath;
        exit;
    }
}

// Handle HTML files
if (strtolower($extension) === 'html') {
    if (file_exists($filePath)) {
        header('Content-Type: text/html');
        readfile($filePath);
        exit;
    }
}

// Nothing matched
http_response_code(404);
echo '404 - Page Not Found';
