<?php
// Router for a throwaway "ntfy server" used by the tests: records every request as one JSON line in $CAPTURE_LOG and answers 200.
$log = getenv('CAPTURE_LOG');
if ($log) {
    file_put_contents($log, json_encode(['uri'=>$_SERVER['REQUEST_URI'], 'method'=>$_SERVER['REQUEST_METHOD'],
        'headers'=>array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER), 'body'=>file_get_contents('php://input')]) . "\n", FILE_APPEND | LOCK_EX);
}
http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';
return true;
