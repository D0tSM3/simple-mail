<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$state = bin2hex(random_bytes(32));
$_SESSION['oauth_state'] = $state;

$client = googleClient();
$client->setState($state);

header('Location: ' . $client->createAuthUrl(), true, 302);
exit;
