<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$publicDir = __DIR__.'/public';

if ($uri === '/' || $uri === '/index.html') {
    header('Content-Type: text/html; charset=UTF-8');
    readfile($publicDir.'/index.html');
    exit;
}

$file = $publicDir.$uri;
if (is_file($file)) {
    return false;
}

require_once $publicDir.'/index.php';
