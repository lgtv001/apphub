<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$publicDir = __DIR__.'/public';

$file = $publicDir.$uri;
if (is_file($file)) {
    return false;
}

require_once $publicDir.'/index.php';
