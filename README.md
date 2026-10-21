# Extendable Order & Payment API

A complete Laravel 12 / PHP 8.3 API for the supplied senior PHP assignment. It includes JWT authentication, owner-scoped order management, two simulated payment strategies, pagination, validation, automated tests, and an executable Postman collection.

**The credit card and PayPal drivers are simulators. They never charge money or call an external provider.** All monetary amounts use USD and integer cents internally; responses use decimal strings.

## Quick start (SQLite)

Requirements: PHP 8.3+, Composer 2, and the extensions required by Laravel, plus `pdo_sqlite`. No Node.js, Redis, or external payment account is required. On Windows, enable extensions in the `php.ini` used by your CLI.

```bash
composer install
php -r "file_exists('.env') || copy('.env.example', '.env');"
php artisan key:generate
php artisan jwt:secret
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan migrate
php artisan serve
```

API base URL: `http://127.0.0.1:8000/api/v1`. Health: `GET /up`.

Register your own account through the API or run the Postman collection. No demo credentials are seeded. `JWT_SECRET` is a dedicated random signing secret, independent of `APP_KEY`. `php artisan jwt:secret --force` rotates it and invalidates every existing token; clear cached configuration afterward.

## Run the checks

```bash
composer test
composer lint
composer validate --strict
composer audit
```

The default PHPUnit configuration forces an isolated in-memory SQLite database. It does not use your application's database. See [validation report](docs/VALIDATION.md) for the checks actually run during delivery.

## Postman: import once, then run

Import `postman/Order_Payment_API.postman_collection.json`. The collection contains its own variables and works **without importing an environment**. Optionally import `postman/Local.postman_environment.json` to override only `base_url`.

Run the **whole collection in order** against a fresh or existing local database:

1. Authentication creates a unique account, logs in, and saves the bearer token.
2. Orders creates, updates, shows, filters, and deletes a separate disposable order.
3. Payments demonstrates decline, retry, PayPal, success, idempotent replay, and listings.
4. Business Rule Examples verifies expected `401`, `404`, `409`, and `422` responses.

`token`, `order_id`, `payment_id`, and UUID idempotency keys are captured/generated automatically. The collection runner assertions cover expected responses, including intentional error cases. For manual use, run Register or Login first and Create Order before payment requests. The primary order is confirmed before the Payments folder runs.

## Endpoints

All endpoints below have the prefix `/api/v1`.

| Method | Endpoint | Purpose | Success |
| --- | --- | --- | --- |
| POST | `/auth/register` | Register and issue JWT | 201 |
| POST | `/auth/login` | Issue JWT | 200 |
| GET | `/auth/me` | Current user | 200 |
| POST | `/orders` | Create order | 201 |
| GET | `/orders` | Owner's orders, optional `status` | 200 |
| GET | `/orders/{id}` | Order and items | 200 |
| PATCH / PUT | `/orders/{id}` | Update supplied fields | 200 |
| DELETE | `/orders/{id}` | Delete if no payment attempts | 204 |
| POST | `/orders/{id}/payments` | Simulate full payment | 201; replay 200 |
| GET | `/orders/{id}/payments` | Order's payment attempts | 200 |
| GET | `/payments` | Owner's payments, optional `status` | 200 |
| GET | `/payments/{uuid}` | Payment details | 200 |

Only registration and login are public. Send `Authorization: Bearer <JWT>` elsewhere. List endpoints accept `page` and `per_page` (default 15, maximum 100), and return `data`, `links`, and `meta`.

## Example order and payment

```json
{
  "customer": {"name": "Mohamed Sayed", "email": "customer@example.com"},
  "status": "confirmed",
  "items": [
    {"product_name": "Keyboard", "quantity": 2, "price": "19.99"},
    {"product_name": "Cable", "quantity": 3, "price": "0.10"}
  ]
}
```

The calculated total is exactly `"40.28"`. Never send `total`, `user_id`, or an arbitrary payment amount. Use decimal strings for prices; each unit price must be positive, at most `999999.99`, with no more than two decimal places. Orders accept 1–100 items and quantities from 1–10000. An update containing `items` replaces the complete item list; a supplied `customer` object must contain both name and email.

`POST /api/v1/orders/1/payments`, with `Idempotency-Key: <UUID>`:

```json
{"method": "credit_card", "simulation_result": "successful"}
```

Use `paypal` to choose the other strategy. `simulation_result` defaults to `successful`; use `failed` to simulate a decline. A decline still creates a payment resource and returns HTTP 201 with `status: failed`. Retry a declined payment using a **new** key; replaying its original key returns the original decline. Card numbers and CVVs are not accepted.

## Business decisions and assumptions

- An account sees only its own orders/payments. Foreign and missing IDs both return 404.
- New orders default to `pending`; creating a `confirmed` order is supported.
- Allowed status changes: `pending → confirmed`, `pending → cancelled`, `confirmed → cancelled`. Cancelled orders cannot be edited; confirmed orders cannot return to pending.
- Payment requires a confirmed order and uses its entire server-calculated total. Split payments, refunds, inventory, tax, shipping, and currency conversion are outside the task.
- Any payment attempt, including a failed one, prevents deleting the order and changing its customer/items. This preserves the payment audit trail.
- Pending or successful payments prevent cancellation and additional charges. A failed-only order may be cancelled or retried. Successful payment leaves the order status `confirmed`; payment status is a separate concern.
- Idempotency is scoped per user. Reusing the same key for the same order/method/outcome returns the same payment with HTTP 200 and `Idempotent-Replayed: true`; conflicting reuse returns 409. Keys have no expiry in this implementation.
- Payments start as pending and finalize synchronously. A gateway exception rolls back the simulated attempt and returns a sanitized 500; provider declines are persisted as failed. There is no public endpoint for overriding payment status.
- JWTs expire after 60 minutes by default; fixed HS256, issuer, audience, subject and time claims are verified. This assignment has no refresh-token or per-token logout/revocation endpoint. Deleting the user invalidates access. Use HTTPS when deployed.
- Auth endpoints are limited to 10 requests/minute per IP and 5/minute per email+IP; protected endpoints to 120/minute per user. File cache is suitable for local use; use a shared cache such as Redis across multiple instances.

## Adding a gateway

1. Implement `App\Payments\PaymentGateway::charge(PaymentContext): GatewayResult`.
2. Add its driver and `enabled` flag to `config/payments.php`.
3. Put configuration/secrets in `.env`, read them only from configuration, and inject dependencies through Laravel's container.

The request validation, registry, controller and payment orchestration discover the new configured method automatically. See [gateway guide](docs/GATEWAYS.md) for a complete example. No service/controller switch statement needs editing.

## Architecture and database

Controllers handle HTTP only. FormRequests validate input. AuthService, OrderService and PaymentService own all account/order/payment queries and business decisions; JwtService handles tokens. Resources define response shapes, and the gateway strategy handles payment behavior. Eloquent is used directly; there is no repository wrapper that merely duplicates Eloquent methods. See [service layer guide](docs/SERVICE_LAYER.md) and [architecture](docs/ARCHITECTURE.md) for locking, schema decisions, and the real-provider adaptation.

SQLite is the easiest single-process development setup. For MySQL 8+, create a database, enable `pdo_mysql`, and set `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env`. Run `php artisan config:clear` and `php artisan migrate`. SQLite does not implement MySQL-style row locking; use MySQL for concurrent deployment. MySQL CI configuration is included separately.

## Submission

The archive is a clean source tree with `composer.lock`, tests, CI workflows, README, API examples, and Postman exports. Install dependencies with `composer install`; `vendor`, local secrets and development databases are excluded. It is ready to commit and push to a Git repository:

```bash
git init
git add .
git commit -m "Implement extendable order and payment API with JWT and tests"
git branch -M main
git remote add origin <your-repository-url>
git push -u origin main
```

The project also includes a standalone API and implementation guide in docs/Order_Payment_API_Guide.docx and docs/Order_Payment_API_Guide.pdf. No repository has been published to an external account as part of this delivery.
