<?php

declare(strict_types=1);

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');

if (is_file(dirname(__DIR__) . '/public' . $path)) {
    return false;
}


return require dirname(__DIR__) . '/public/index_test.php';
