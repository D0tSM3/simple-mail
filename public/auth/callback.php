<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

if (isset($_GET['error'])) {
    plain_error(400, 'Google authorization was not completed.');
}

$state = $_GET['state'] ?? '';
$expected = $_SESSION['oauth_state'] ?? '';
unset($_SESSION['oauth_state']);

if (!is_string($state) || !is_string($expected) || $expected === ''
    || !hash_equals($expected, $state)) {
    plain_error(400, 'Invalid OAuth state. Please reconnect.');
}

$code = $_GET['code'] ?? '';
if (!is_string($code) || $code === '') {
    plain_error(400, 'Missing authorization code.');
}

try {
    $client = googleClient();
    $tokens = $client->fetchAccessTokenWithAuthCode($code);

    if (isset($tokens['error'])) {
        throw new RuntimeException('Token exchange failed: ' . $tokens['error']);
    }

    // Google lets users untick individual permissions on the consent screen.
    if (!in_array(GMAIL_SCOPE, explode(' ', $tokens['scope'] ?? ''), true)) {
        plain_error(403, 'Gmail access was not granted. Please reconnect and allow it.');
    }

    $identity = $client->verifyIdToken($tokens['id_token'] ?? '');
    if (!$identity || empty($identity['sub'])) {
        throw new RuntimeException('Could not verify Google identity.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    $pdo->prepare(
        'INSERT INTO app_users (google_sub, email) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE email = VALUES(email)'
    )->execute([$identity['sub'], $identity['email'] ?? '']);

    $stmt = $pdo->prepare('SELECT id FROM app_users WHERE google_sub = ?');
    $stmt->execute([$identity['sub']]);
    $userId = (int) $stmt->fetchColumn();

    TokenStore::save($userId, $tokens);
    $pdo->commit();

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;

    header('Location: /', true, 302);
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    log_event(['type' => 'oauth_callback_failed',
               'exception' => get_class($e),
               'diagnostic' => 'OAuth failure details intentionally omitted.']);
    $m = ErrorMapper::map($e);
    plain_error($m['status'] >= 500 ? $m['status'] : 500, 'Unable to connect Gmail.');
}
