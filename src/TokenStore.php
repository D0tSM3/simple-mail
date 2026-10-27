<?php
declare(strict_types=1);

use Google\Client;

final class TokenStore
{
    /** Returns a Google client with a valid access token, refreshing if needed. */
    public static function clientFor(int $userId): Client
    {
        $stmt = db()->prepare(
            'SELECT access_token, refresh_token, expires_at FROM oauth_tokens WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new AppException(401, 'GMAIL_NOT_CONNECTED', 'Gmail is not connected.');
        }

        $access  = self::decrypt($row['access_token']);
        $refresh = $row['refresh_token'] !== null ? self::decrypt($row['refresh_token']) : null;

        $client = googleClient();
        $client->setAccessToken([
            'access_token'  => $access,
            'refresh_token' => $refresh,
            'created'       => time(),
            'expires_in'    => (int) $row['expires_at'] - time(), // seconds left
        ]);

        if ($client->isAccessTokenExpired()) {
            self::refresh($client, $userId, $refresh);
        }

        return $client;
    }

    private static function refresh(Client $client, int $userId, ?string $refresh): void
    {
        if (!$refresh) {
            throw new AppException(401, 'RECONNECT_REQUIRED', 'Please reconnect your Gmail account.');
        }

        try {
            $new = $client->fetchAccessTokenWithRefreshToken($refresh);
        } catch (Throwable $e) {
            // invalid_grant = revoked/expired refresh token -> user must reconnect.
            // Anything else (network, 5xx) propagates to ErrorMapper as 502/503/504.
            if (self::isInvalidGrant($e)) {
                throw new AppException(401, 'RECONNECT_REQUIRED',
                    'Your Gmail authorization expired. Please reconnect.', false, $e);
            }
            throw $e;
        }

        if (isset($new['error'])) {
            if ($new['error'] === 'invalid_grant') {
                throw new AppException(401, 'RECONNECT_REQUIRED',
                    'Your Gmail authorization expired. Please reconnect.');
            }
            throw new AppException(502, 'TOKEN_REFRESH_FAILED',
                'Could not refresh Gmail authorization.', true);
        }

        self::save($userId, $new);
    }

    /** Persist a token response from Google (auth-code exchange or refresh). */
    public static function save(int $userId, array $tokens): void
    {
        $stmt = db()->prepare(
            'INSERT INTO oauth_tokens (user_id, access_token, refresh_token, expires_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                access_token = VALUES(access_token),
                refresh_token = COALESCE(VALUES(refresh_token), refresh_token),
                expires_at = VALUES(expires_at)'
        );
        $stmt->execute([
            $userId,
            self::encrypt((string) $tokens['access_token']),
            isset($tokens['refresh_token']) ? self::encrypt((string) $tokens['refresh_token']) : null,
            time() + (int) ($tokens['expires_in'] ?? 3600),
        ]);
    }

    /** The token to revoke on disconnect (refresh token revokes the whole grant). */
    public static function revocableToken(int $userId): ?string
    {
        $stmt = db()->prepare('SELECT access_token, refresh_token FROM oauth_tokens WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        return self::decrypt($row['refresh_token'] ?? $row['access_token']);
    }

    private static function isInvalidGrant(Throwable $e): bool
    {
        if (stripos($e->getMessage(), 'invalid_grant') !== false) return true;
        if ($e instanceof GuzzleHttp\Exception\RequestException && $e->getResponse()) {
            $body = json_decode((string) $e->getResponse()->getBody(), true);
            return is_array($body) && ($body['error'] ?? '') === 'invalid_grant';
        }
        return false;
    }

    // AES-256-GCM is mandatory; keys must be stored outside source and database.
    private static function key(): string
    {
        $k = env('TOKEN_ENC_KEY');
        if (!$k) throw new AppException(500, 'TOKEN_STORAGE_UNAVAILABLE', 'Secure token storage is unavailable.');
        $raw = base64_decode($k, true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new AppException(500, 'TOKEN_STORAGE_UNAVAILABLE', 'Secure token storage is unavailable.');
        }
        return $raw;
    }

    private static function encrypt(string $plain): string
    {
        $key = self::key();
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false || !isset($tag) || strlen($tag) !== 16) {
            throw new AppException(500, 'TOKEN_STORAGE_UNAVAILABLE', 'Secure token storage is unavailable.');
        }
        return 'enc:v1:' . base64_encode($iv . $tag . $cipher);
    }

    private static function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, 'enc:v1:')) {
            throw new AppException(401, 'RECONNECT_REQUIRED', 'Stored authorization needs to be reconnected.');
        }
        $key = self::key();
        $raw = base64_decode(substr($stored, 7), true);
        if ($raw === false || strlen($raw) < 29) {
            throw new AppException(401, 'RECONNECT_REQUIRED', 'Stored authorization needs to be reconnected.');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key,
            OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) throw new AppException(401, 'RECONNECT_REQUIRED', 'Stored authorization needs to be reconnected.');
        return $plain;
    }
}
