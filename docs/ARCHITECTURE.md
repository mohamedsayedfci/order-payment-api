# Architecture and tradeoffs

## Request flow

JWT authentication → FormRequest → controller → service → Eloquent / gateway strategy → API resource. Write services create transactions where multiple changes must succeed together.

`JwtRequestGuard` is a request guard registered with Laravel's Auth manager. It clears its cached principal when Laravel rebinds the request, preventing the preceding request's user from being reused in an application that handles multiple requests per process. `JwtService` uses the maintained `firebase/php-jwt` package; it does not implement signature primitives itself. Tokens carry required exp/iat/nbf/iss/aud/sub/jti claims, and the decoder is fixed to HS256. No session cookie authenticates an API request.

Controllers delegate every account/order/payment query to a service. OrderService and PaymentService scope all reads and writes to the authenticated user's relationships. AuthService owns registration and credential verification. This applies to nested payments as well as payment UUIDs and does not rely on the client supplying an owner ID. Validation precedes owner lookup on writes, so a malformed request can return 422 before existence/ownership is evaluated.

## Tables

- `users`: authenticating account; framework password hashing.
- `orders`: account owner, customer snapshot, status, currency, calculated total in minor units.
- `order_items`: product-name and price snapshots, quantity and calculated line total. Cascades only when the parent can be deleted.
- `payments`: UUID, account/order, immutable full amount/currency snapshot, method, status, idempotency key, request fingerprint, safe provider reference and decline code.

Payments have foreign keys with restricted order deletion. A composite unique key on `(user_id, idempotency_key)` provides a database backstop. A unique nullable gateway reference prevents recording the same simulator result twice. The extra `payments.user_id` makes owner scoping/idempotency indexing direct; it is always derived from the authenticated owner after locking the owned order.

Money uses 64-bit integers. Input bounds make the maximum total `100 × 10000 × 99999999 = 99999999000000` cents, well below signed 64-bit capacity. There is no floating-point multiplication, no trusting a client-supplied total, and no decimal rounding during persistence.

## Transactions and concurrent requests

- Create/update replaces items and recalculates totals atomically.
- Updates and deletion lock the order row.
- Payment processing locks the owner row, then the owned order row, in that order. This serializes payment requests per owner, including reuse of a key across different orders.
- Under the lock, the service checks an existing key before checking whether another payment already succeeded. Thus a retry after success returns the original resource.
- A new request cannot process an unconfirmed order or one with pending/successful payment. Failed payment attempts allow another attempt with a fresh key.
- The service inserts pending and persists the final strategy result in the same transaction. No half-written local attempt survives an exception.
- Transactions retry detected deadlocks up to three times. Both supplied strategies are deterministic and side-effect-free, so retrying a transaction cannot charge a real customer.

The owner lock deliberately favors clarity/correctness over parallel payment throughput for the same customer. A higher-volume design could move the idempotency claim to a dedicated table/unique insert and use per-order locks. Do not simply remove the owner lock while retaining a read-then-insert key check.

The SQLite suite verifies behavior and rollback; it cannot prove MySQL's concurrent locking guarantees. The included MySQL CI job runs the functional suite on MySQL, but a parallel multi-process race test and load test remain separate deployment checks. SQL row locks require MySQL/InnoDB or a similarly capable database; SQLite is for the local demo.

## Real gateways: deliberate boundary

The assignment asks for **simulation**, so the network boundary is not implemented. In particular, putting provider API keys in `.env` does not turn these drivers into real payment clients. The extra credentials show where configuration belongs.

Before introducing an external charge:

1. Claim the idempotency key and persist a durable pending attempt in a short database transaction. Persist a provider-facing key derived from the payment UUID.
2. Commit an outbox entry atomically; call the provider outside database locks using that stable key and tokenized payment credentials.
3. Persist a known success or decline in another short transaction, verifying amount/currency/order and valid state transitions.
4. Treat transport timeouts as an **unknown/pending** outcome, never as a definite decline. Reconcile using authenticated, deduplicated webhooks and provider status lookup before retrying a charge.
5. Define webhook signature verification, replay protection, retries, reconciliation scheduling, monitoring, refunds and secret rotation for the actual provider.

A remote charge cannot be undone by rolling back SQL. That is why the synchronous transaction used by the local simulators must not be copied directly into a real integration.

## Boundaries intentionally omitted

Product catalogue, inventory, discounts, tax, shipping, partial payments, refunds, email verification, password reset, refresh tokens, admin roles, and provider webhooks are not requested. Customer details are a name/email snapshot supplied by the account. Both account and customer emails are validated syntactically; no network DNS lookup is required for a deterministic local API.
