<?php
declare(strict_types=1);
// The two scripts that protect the live site and the staging copy: the folder guard and the "is staging private?" check.
// The check is run against real local servers: one that demands a password (like a protected folder) and one that does not.
require dirname(__DIR__) . '/lib/harness.php';

$repo = dirname(__DIR__, 2);
$staging = getenv('STAGING_SITE_DIR');
$plain = getenv('SITE_DIR');
if (!$staging || !$plain) {
    echo "SKIP no site copies were prepared (run this through tests/run.sh)\n";
    exit(0);
}

function run_script(string $script, array $args, array $env): array {
    $cmd = 'env -i PATH=' . escapeshellarg((string)getenv('PATH')) . ' ';
    foreach ($env as $key => $value) {
        $cmd .= $key . '=' . escapeshellarg($value) . ' ';
    }
    $cmd .= 'bash ' . escapeshellarg($script) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    exec($cmd, $lines, $status);
    return [$status, implode("\n", $lines)];
}

// ---- the folder guard
$guard = $repo . '/.github/scripts/staging-guard.sh';
$base = ['HOST'=>'h', 'USER_NAME'=>'u', 'PASS'=>'p', 'PROD_DIR'=>'/public_html/', 'STAGING_DIR'=>'/public_html/staging/', 'STAGING_URL'=>'https://example.com/staging/', 'OFFGRID_URL'=>'https://example.com/'];
[$s, $o] = run_script($guard, [], $base);
check('guard: a staging folder inside the live one is allowed', $s === 0 && str_contains($o, 'ok=true'), $o);
[$s, $o] = run_script($guard, [], array_diff_key($base, ['STAGING_DIR'=>1]));
check('guard: missing secrets skip quietly', $s === 0 && str_contains($o, 'ok=false') && str_contains($o, 'STAGING_DEPLOY_DIR'), $o);
[$s, $o] = run_script($guard, [], ['STAGING_DIR'=>'/public_html'] + $base);
check('guard: the same folder as the live site is refused', $s === 1 && str_contains($o, 'overwrite the live site'), $o);
[$s, $o] = run_script($guard, [], ['STAGING_DIR'=>'/public_html/', 'PROD_DIR'=>'/public_html'] + $base);
check('guard: the same folder is refused whatever the trailing slashes', $s === 1);
[$s, $o] = run_script($guard, [], ['PROD_DIR'=>'/public_html/mesh/', 'STAGING_DIR'=>'/public_html/'] + $base);
check('guard: a staging folder that contains the live site is refused', $s === 1 && str_contains($o, 'lies inside'), $o);
[$s, $o] = run_script($guard, [], ['STAGING_DIR'=>'/'] + $base);
check('guard: the top of the FTP account is refused', $s === 1 && str_contains($o, 'not the top'), $o);
[$s, $o] = run_script($guard, [], ['STAGING_URL'=>'https://example.com'] + $base);
check('guard: the live address as the staging address is refused', $s === 1 && str_contains($o, 'same address'), $o);
[$s, $o] = run_script($guard, [], ['PROD_DIR'=>'/public_html/mesh-live', 'STAGING_DIR'=>'/public_html/mesh'] + $base);
check('guard: sibling folders with a shared name prefix are not confused', $s === 0 && str_contains($o, 'ok=true'), $o);

// ---- the staging check, against real servers
function start_server(string $docroot, ?string $router): array {
    $port = 0;
    for ($try = 0; $try < 50; $try++) {
        $candidate = random_int(20000, 40000);
        $probe = @fsockopen('127.0.0.1', $candidate, $errno, $errstr, 0.2);
        if (!$probe) { $port = $candidate; break; }
        fclose($probe);
    }
    $cmd = ['php', '-S', "127.0.0.1:$port", '-t', $docroot];
    if ($router) {
        $cmd[] = $router;
    }
    $process = proc_open($cmd, [1=>['file','/dev/null','w'], 2=>['file','/dev/null','w']], $pipes);
    for ($i = 0; $i < 40; $i++) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($probe) { fclose($probe); break; }
        usleep(100000);
    }
    return [$process, $port];
}

$router = sys_get_temp_dir() . '/offgrid-auth-router-' . getmypid() . '.php';
file_put_contents($router, <<<'PHP'
<?php
// Behaves like a folder protected with HTTP Basic auth: 401 without the right user and password.
if (($_SERVER['PHP_AUTH_USER'] ?? '') !== 'tester' || ($_SERVER['PHP_AUTH_PW'] ?? '') !== 'secret') {
    header('HTTP/1.1 401 Unauthorized');
    header('WWW-Authenticate: Basic realm="Staging"');
    echo 'Authorization required';
    return true;
}
return false;
PHP);
file_put_contents($staging . '/robots.txt', "User-agent: *\nDisallow: /\n");
$script = $repo . '/.github/scripts/staging-check.sh';
$servers = [];
$start = static function (string $docroot, ?string $withRouter) use (&$servers): int {
    [$process, $port] = start_server($docroot, $withRouter);
    $servers[] = $process;
    return $port;
};

$protected = $start($staging, $router);
$creds = ['STAGING_USER'=>'tester', 'STAGING_PASSWORD'=>'secret'];
[$s, $o] = run_script($script, ["http://127.0.0.1:$protected/"], $creds);
check('check: a protected staging copy that says staging passes', $s === 0 && str_contains($o, 'staging:true') && str_contains($o, 'closed to the public'), $o);
[$s, $o] = run_script($script, ["http://127.0.0.1:$protected/"], []);
check('check: without credentials it still proves the site is closed, and notes what it skipped', $s === 0 && str_contains($o, 'signed-in checks were skipped'), $o);
[$s, $o] = run_script($script, ["http://127.0.0.1:$protected/"], ['STAGING_USER'=>'tester', 'STAGING_PASSWORD'=>'wrong']);
check('check: wrong credentials fail the signed-in checks', $s === 1, $o);

$open = $start($staging, null);
[$s, $o] = run_script($script, ["http://127.0.0.1:$open/"], []);
check('check: a staging copy that is NOT password protected fails loudly', $s === 1 && str_contains($o, 'Staging is not protected'), $o);

file_put_contents($plain . '/robots.txt', "User-agent: *\nDisallow: /\n");
$notStaging = $start($plain, $router);
[$s, $o] = run_script($script, ["http://127.0.0.1:$notStaging/"], $creds);
check('check: a protected site that is not marked staging fails', $s === 1 && str_contains($o, 'staging:true'), $o);

foreach ($servers as $process) {
    proc_terminate($process);
    proc_close($process);
}
@unlink($router);
finish();
