<?php

// A smart-HTTP git server for the tests, run by PHP's built-in server in front
// of git http-backend. It admits one user and token, given in the environment,
// with Basic authentication, as OneDev does; anything else gets 401. Every
// Authorization header it receives is appended to AYBAK_TEST_AUTH_LOG, so a
// test can count the requests that carried a credential.

declare(strict_types=1);

$user = (string) getenv('AYBAK_TEST_USER');
$token = (string) getenv('AYBAK_TEST_TOKEN');
$given = [$_SERVER['PHP_AUTH_USER'] ?? null, $_SERVER['PHP_AUTH_PW'] ?? null];
if ($given[0] !== null) {
    file_put_contents((string) getenv('AYBAK_TEST_AUTH_LOG'), $given[0] . "\n", FILE_APPEND);
}
if ($given !== [$user, $token]) {
    header('WWW-Authenticate: Basic realm="test"');
    http_response_code(401);
    echo "authentication required\n";

    return true;
}

$uri = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$env = [
    'GIT_PROJECT_ROOT' => (string) getenv('AYBAK_TEST_ROOT'),
    'GIT_HTTP_EXPORT_ALL' => '1',
    'REMOTE_USER' => $user,
    'REQUEST_METHOD' => (string) $_SERVER['REQUEST_METHOD'],
    'PATH_INFO' => $uri,
    'QUERY_STRING' => (string) ($_SERVER['QUERY_STRING'] ?? ''),
    'CONTENT_TYPE' => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
    'HTTP_CONTENT_ENCODING' => (string) ($_SERVER['HTTP_CONTENT_ENCODING'] ?? ''),
    'PATH' => (string) getenv('PATH'),
];
$process = proc_open(['git', 'http-backend'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
fwrite($pipes[0], (string) file_get_contents('php://input'));
fclose($pipes[0]);
$out = (string) stream_get_contents($pipes[1]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($process);

[$head, $body] = explode("\r\n\r\n", $out, 2) + [1 => ''];
foreach (explode("\r\n", $head) as $line) {
    if (stripos($line, 'Status:') === 0) {
        http_response_code((int) trim(substr($line, 7)));
    } elseif ($line !== '') {
        header($line);
    }
}
echo $body;

return true;
