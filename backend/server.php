<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$publicDir = __DIR__.'/public';

if ($uri === '/') {
    header('HTTP/1.1 302 Found');
    header('Location: /app/login.html');
    exit;
}

$file = $publicDir.$uri;
if (is_file($file)) {
    return false;
}

require_once $publicDir.'/index.php';
