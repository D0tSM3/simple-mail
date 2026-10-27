<?php
// Run while the server is up:  php tests/exposure_check.php [base-url]
// Every private path must NOT return 200.
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$paths = ['/.env', '/.env.example', '/composer.json', '/src/bootstrap.php',
          '/database/schema.sql', '/vendor/autoload.php', '/tests/mapper_test.php'];

$bad = 0;
foreach ($paths as $p) {
    $h = @get_headers($base . $p);
    $status = $h ? (int) substr($h[0], 9, 3) : 0;
    $ok = $status !== 200;
    $bad += $ok ? 0 : 1;
    printf("%s  %-26s HTTP %s\n", $ok ? 'PASS' : 'FAIL', $p, $status ?: 'no response');
}
echo $bad ? "\n$bad paths are exposed!\n" : "\nNo private files are reachable.\n";
exit($bad ? 1 : 0);
