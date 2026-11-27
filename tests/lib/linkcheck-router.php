<?php
declare(strict_types=1);
// A tiny web server for tests/php/linkcheck.test.php: pages that answer the way real sites do, so the link checker can be tried on them.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
switch ($path) {
    case '/ok':
        echo 'hello';
        return;
    case '/missing':
        http_response_code(404);
        echo 'not here';
        return;
    case '/gone':
        http_response_code(410);
        return;
    case '/boom':
        http_response_code(500);
        return;
    case '/denied':
        http_response_code(403);
        return;
    case '/moved':
        header('Location: /ok', true, 301);
        return;
    case '/chain':
        header('Location: /moved', true, 302);
        return;
    case '/loop':
        header('Location: /loop', true, 302);
        return;
    case '/big':
        echo str_repeat('x', 400000);
        return;
    case '/slow':
        sleep(4);
        echo 'late';
        return;
    case '/flaky':
        // Fails twice, then works. The count lives in the file named in ?f=.
        $file = (string)($_GET['f'] ?? '');
        $n = (int)@file_get_contents($file) + 1;
        file_put_contents($file, (string)$n);
        if ($n <= 2) {
            http_response_code(503);
            return;
        }
        echo 'fine';
        return;
}
http_response_code(404);
