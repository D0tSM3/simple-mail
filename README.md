# Smart Mailbox

Smart Mailbox is a work-in-progress learning project for a small web app that connects PHP, JavaScript, MySQL, and the Gmail API. It reads Gmail inbox metadata using the `gmail.metadata` scope and `openid email`: sender and subject headers, dates, labels, and read state. It does not read message bodies or snippets. Search filters only the messages already loaded in the current page; Gmail's `gmail.metadata` scope does not support Gmail query search.

## Technologies and learning goals

- PHP, JavaScript, HTML, CSS, MySQL, Composer, and XAMPP.
- Learn OAuth 2.0 API integration, HTTP status handling, consistent JSON errors, prepared SQL statements, credential protection, and frontend/backend requests.

## Current progress

**Status: In Progress.** PHP syntax checks passed for the 14 project PHP files. The mapper test passed all 19 cases. Composer manifest validation succeeded with a warning that no license is specified; Composer also used its default root package version. Gmail integration, database setup, and end-to-end behavior have not been verified. This savepoint is development progress, not a completed release.

## AI assistance

This project is AI-assisted. AI tools helped review code, identify potential issues, suggest corrections, and explain implementation choices. The code and suggestions are being reviewed and validated as part of the learning process.

This is a local learning project and is not intended for production use.

## Local setup (Windows + XAMPP)

The project root is the directory containing `composer.json`, `database/`, `public/`, and `src/`. Open PowerShell there.

1. Start MySQL in XAMPP. Apache is not needed for the PHP built-in server.
2. In phpMyAdmin, run `database/schema.sql`. It creates the database and tables only; it does not create an application user.
3. Create a dedicated MySQL account with a unique password and only `SELECT`, `INSERT`, `UPDATE`, and `DELETE` on `smart_mailbox.*`. Set those credentials in `.env`. Do not reuse a database administrator account.
4. Install Composer if needed, then run `composer install` from the project root. This resolves the declared dependencies and creates the ignored `vendor/` directory and `composer.lock`. PHP must be on `PATH`; with XAMPP, use `C:\xampp\php\php.exe` to run PHP commands and enable `curl`, `openssl`, and `pdo_mysql` in that PHP installation's `php.ini`.
5. Copy `.env.example` to `.env`. Set `APP_ENV=local`, DB credentials, Google OAuth client ID and secret, and a unique `TOKEN_ENC_KEY`. Generate a key locally with `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"` and paste its output into `.env`; do not commit or share it. The application requires exactly 32 decoded key bytes and uses authenticated AES-256-GCM encryption for stored access and refresh tokens. Missing/invalid keys prevent token writes. Losing or changing the key makes existing encrypted tokens unusable; reconnect Gmail after restoring the original key or deliberately clearing those tokens.
6. In Google Cloud Console, enable the Gmail API, configure the OAuth consent screen, add your account as a test user, and create a Web application OAuth client. Set the authorized redirect URI exactly to `http://localhost:8080/auth/callback.php` (and `APP_URL=http://localhost:8080`).
7. From the project root, run `php -S localhost:8080 -t public`. Only `public/` is served; do not change the document root to the project root.

The local error lab appears only when `APP_ENV=local`, `DEBUG_ERRORS=true`, and the request comes from loopback (`127.0.0.1` or `::1`). The endpoint generates a bounded set of synthetic HTTP statuses for frontend practice and does not contact Google. Keep it disabled outside local development. A production deployment should also exclude `public/api/debug/status.php` from its deployment artifact.

## Error handling and checks

The API returns a controlled public error code/message. Diagnostic logs use the same request ID as the response's `X-Request-Id` and error envelope, and intentionally omit exception messages and upstream bodies because they can contain credentials or personal data.

After installing dependencies, `php tests/mapper_test.php` exercises mapper cases without Gmail, a database, or network access. `php tests/exposure_check.php [base-url]` is an HTTP check and requires the local server to be running. Neither check connects an OAuth account. Syntax checks can be run with `php -l` on PHP files. Do not insert arbitrary exceptions into application source to test errors; use the local error lab or mapper tests.

OAuth connection, real Gmail requests, database access, and the HTTP exposure check require explicit local setup. OAuth testing-mode refresh tokens can expire under Google's policies, in which case reconnecting may be required.

## Limitations

- Metadata only: no body, snippets, or Gmail `q` search.
- Up to 20 sequential Gmail message metadata requests are made per page.
- This project is intended for local learning; it does not provide production deployment hardening or its own API rate limiting.
