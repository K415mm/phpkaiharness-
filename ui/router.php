<?php

/**
 * PHP built-in server router for phpkaiharness Web UI.
 * Routes requests to the correct PHP file while serving static assets directly.
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve static assets (CSS, JS, images, fonts) directly
if (preg_match('/\.(css|js|png|jpg|gif|ico|svg|woff2?|ttf)$/', $uri)) {
    return false;
}

// API endpoint
if (str_starts_with($uri, '/api')) {
    require __DIR__.'/api.php';

    return true;
}

// Session detail page
if (str_starts_with($uri, '/session')) {
    require __DIR__.'/session.php';

    return true;
}

// Configuration page
if (str_starts_with($uri, '/config')) {
    require __DIR__.'/config.php';

    return true;
}

// Default: dashboard index
require __DIR__.'/index.php';

return true;
