# Delivery validation

Verified on PHP 8.3.6 with Laravel 12.70.0, firebase/php-jwt 7.2.1 and PHPUnit 11.5.57.

| Check actually run | Result |
| --- | --- |
| `composer test` | 42 tests, 274 assertions; all passed |
| Full Newman collection against running PHP HTTP server | 29 requests, 41 assertions; no failures |
| `composer lint` (Pint PSR-12) | Passed |
| `composer validate --strict` | Passed |
| `composer audit` | No known advisories; no abandoned packages reported |
| SQLite migrations on a file-backed local database | Passed |
| `php artisan config:cache` | Passed; cache cleared afterward |
| `php artisan route:cache` | Passed; cache cleared afterward |

After the service-layer refactor, the same 42 tests / 274 assertions and 29 HTTP requests / 41 assertions passed again. Controllers contain no direct account/order/payment queries. The eight-page Word and PDF guide was rendered and visually verified.

The HTTP workflow exercised registration/login, JWT bearer auth, order CRUD, pagination/filtering, both gateway strategies, decline/retry, idempotent replay, and expected 401/404/409/422 responses. Authentication regression tests also cover oversized/multibyte/null-byte passwords, malformed email input, signed token claim failures, and switching owners between requests.

The dependency lockfile is included for reproducible installation. Audit results describe the advisory database at verification time; run the audit again when installing.

## Verification limits

The MySQL CI job and PHP 8.4 matrix are included but were not executed in this local delivery environment. Local tests used isolated in-memory SQLite; HTTP verification used a disposable file-backed SQLite database. These checks do not establish MySQL concurrency or production load behavior. No real provider API was called and no funds were charged.

## Portable Git repository

A separate `Order_Payment_API.git.bundle` contains the same committed source as the ZIP. To restore the repository:

```bash
git clone Order_Payment_API.git.bundle order-payment-api
cd order-payment-api
```

Then follow README setup. The source ZIP excludes `.git`; it can also be initialized and pushed using the README commands. No external repository has been published.
