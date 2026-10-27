<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// 1. Cheap checks first, before touching DB or Google.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error(405, 'METHOD_NOT_ALLOWED', 'This endpoint only accepts GET.', [], ['Allow' => 'GET']);
}
if (empty($_SESSION['user_id'])) {
    ApiResponse::error(401, 'UNAUTHENTICATED', 'Please connect your Gmail account.');
}

$pageToken = $_GET['pageToken'] ?? null;
if ($pageToken !== null
    && (!is_string($pageToken) || !preg_match('/^[A-Za-z0-9_\-.=]{1,2048}$/', $pageToken))) {
    ApiResponse::error(400, 'INVALID_PAGE_TOKEN', 'The page token is malformed.');
}

// 2. Real work. Anything thrown is mapped to the right HTTP status.
try {
    $started = microtime(true);
    $client = TokenStore::clientFor((int) $_SESSION['user_id']);
    $gmail = new Google\Service\Gmail($client);

    $options = ['maxResults' => 20, 'labelIds' => ['INBOX']];
    if (is_string($pageToken)) $options['pageToken'] = $pageToken;

    $list = $gmail->users_messages->listUsersMessages('me', $options);

    $messages = [];
    foreach ($list->getMessages() ?? [] as $ref) {   // N+1: measure before optimizing
        $m = $gmail->users_messages->get('me', $ref->getId(), [
            'format' => 'metadata',
            'metadataHeaders' => ['From', 'Subject'],
            'fields' => 'id,labelIds,internalDate,payload/headers',
        ]);

        $headers = [];
        foreach ($m->getPayload()?->getHeaders() ?? [] as $h) {
            $headers[strtolower($h->getName())] = $h->getValue();
        }
        $labels  = $m->getLabelIds() ?? [];
        $sender  = $headers['from'] ?? '(Unknown sender)';
        $subject = $headers['subject'] ?? '(No subject)';
        $ms      = (int) $m->getInternalDate();

        $messages[] = [
            'id'       => $m->getId(),
            'sender'   => $sender,
            'subject'  => $subject,
            'date'     => $ms ? gmdate('c', intdiv($ms, 1000)) : null,   // ISO 8601
            'labels'   => $labels,
            'unread'   => in_array('UNREAD', $labels, true),
            'category' => Categorizer::categorize($sender, $subject, $labels),
        ];
    }

    ApiResponse::success([
        'messages' => $messages,
        'nextPageToken' => $list->getNextPageToken(),
        'elapsedMs' => (int) round((microtime(true) - $started) * 1000),
    ]);
} catch (Throwable $e) {
    ApiResponse::fromThrowable($e);
}
