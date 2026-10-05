<?php
/**
 * Router for PHP's built-in web server.
 *
 * Apache users can ignore this file: .htaccess already blocks the same paths.
 * It exists because `php -S` does not read .htaccess, and nobody wants their
 * SQLite database or mail log downloadable on a demo machine.
 *
 * Usage (from the folder that holds the apps):
 *     php -S localhost:8080 shophub/router.php
 *
 * Or serve the storefront alone:
 *     cd shophub && php -S localhost:8080 router.php
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = '/' . ltrim(rawurldecode($path), '/');

/* Anything that must never be served over HTTP. */
$blockedPatterns = [
    '#/(\.|storage/)#i',            // dotfiles, storage/ at any depth
    '#/(includes|tools)/#i',        // server-side includes and build scripts
    '#\.(sqlite|sqlite3|db|log|sql|md|ini|local\.php)$#i',
];

foreach ($blockedPatterns as $pattern) {
    if (preg_match($pattern, $path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not found.";
        return true;
    }
}

/* Let the built-in server handle everything else (static files + PHP). */
return false;
