<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
$connected = !empty($_SESSION['user_id']);
$debug = debug_enabled();
$oauthCancelled = isset($_GET['oauth']) && is_string($_GET['oauth']) && $_GET['oauth'] === 'cancelled';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
    <title>Smart Mailbox</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body data-connected="<?= $connected ? '1' : '0' ?>" data-debug="<?= $debug ? '1' : '0' ?>">
<header>
    <h1>Smart Mailbox</h1>
    <div>
        <?php if ($connected): ?>
            <button id="disconnect" type="button">Disconnect</button>
        <?php else: ?>
            <a class="btn" href="/auth/connect.php">Connect Gmail</a>
        <?php endif; ?>
    </div>
</header>

<main>
    <?php if ($oauthCancelled): ?>
    <p id="oauth-status" role="status"><?= htmlspecialchars('Google sign-in was cancelled. You can try again whenever you\'re ready.', ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <p id="status" role="status">
        <?= $connected ? 'Loading…' : 'Connect Gmail to get started.' ?>
    </p>
    <div id="actions"></div>

    <?php if ($connected): ?>
    <section class="toolbar">
        <input id="search" type="search" placeholder="Filter this page…" aria-label="Filter this page">
        <select id="category" aria-label="Filter by category">
            <option value="">All categories</option>
            <option>General</option><option>Finance</option>
            <option>Promotions</option><option>Social</option>
        </select>
    </section>

    <div class="mail-layout">
        <section id="email-list" aria-label="Inbox"></section>
        <article id="preview" aria-live="polite">
            <h2>Message details</h2>
            <p>Select a message. The metadata scope gives headers and labels only, not the body.</p>
        </article>
    </div>

    <div class="pager">
        <button id="prev-page" type="button" disabled>Previous</button>
        <span id="page-num"></span>
        <button id="next-page" type="button" disabled>Next</button>
    </div>
    <?php endif; ?>

    <?php if ($debug): ?>
    <section id="lab">
        <h2>Error lab <small>(local only)</small></h2>
        <div class="toolbar">
            <select id="lab-code" aria-label="Status code">
                <?php foreach ([200,201,204,400,401,403,404,405,409,422,429,500,502,503,504] as $c): ?>
                    <option><?= $c ?></option>
                <?php endforeach; ?>
            </select>
            <label><input type="checkbox" id="lab-retry"> use retry helper</label>
            <label><input type="checkbox" id="lab-fail"> fail twice, then succeed</label>
            <button id="lab-send" type="button">Send</button>
        </div>
        <pre id="lab-log" aria-live="polite"></pre>
    </section>
    <?php endif; ?>
</main>

<script src="/assets/app.js" defer></script>
</body>
</html>
