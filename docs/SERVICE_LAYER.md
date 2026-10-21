# Service layer and readable code

The controller reads validated input, calls a service, and returns a resource or HTTP response. It contains no database query, password comparison, total calculation, order transition rule, or payment decision.

| Component | Responsibility | Main methods |
| --- | --- | --- |
| AuthService | Account creation and credential verification | register, login |
| JwtService | Issue tokens, validate signature/claims, find token subject | issue, authenticate |
| OrderService | Owned order queries, totals, lifecycle, transactions | paginate, find, create, update, delete |
| PaymentService | Owned payment queries, idempotency, payment orchestration | paginate, find, process |
| GatewayRegistry | Select a configured, enabled driver | methods, resolve |
| PaymentGateway | Provider-specific simulation strategy | charge |
| FormRequests | Input validation and normalization | rules, prepareForValidation |
| API Resources | Response shape and decimal formatting | toArray |

## Read the order update from top to bottom

1. Lock and find the owned order.
2. Check that the order can be edited.
3. Apply a valid status change if requested.
4. Replace customer information if supplied.
5. Replace items and recalculate the total if supplied.
6. Save the order and return its loaded items.

Named private methods explain each rule: ensureOrderCanBeUpdated, changeStatus, and replaceItems. The item calculation uses an ordinary foreach loop with quantity, unitPriceMinor, lineTotalMinor, and orderTotalMinor variables. All calculations use integer minor units.

## Read payment processing from top to bottom

1. Lock the owner and then the owned order.
2. Build a stable fingerprint for the order, method, and simulation result.
3. Find a previous attempt with the same key. Return it when it matches; reject conflicting reuse.
4. Check that the order is confirmed and has no pending or successful payment.
5. Create a pending payment using the order's stored total and currency.
6. Select the gateway, create an immutable PaymentContext, and run the simulator.
7. Store the result and return the payment.

These steps map directly to private service methods: lockOrderForPayment, requestFingerprint, findExistingPayment, ensureOrderCanBePaid, createPendingPayment, and chargePayment. The transaction wraps the entire simulated operation.

## HTTP response handling stays in controllers

A new payment returns 201, a replay returns 200, and the Idempotent-Replayed header tells clients which path ran. These are HTTP concerns, so the controller chooses the response code/header from ProcessedPayment. Services return models or result objects and do not construct JsonResponse objects.

List services receive validated page/per_page/status values explicitly and append those same filters to pagination links. They do not read request() or auth() helpers. The authenticated user is passed as an argument, which keeps ownership explicit and makes services reusable from commands or jobs.

## Readable boundaries

Each class has a clear job. Small helpers identify business decisions; there is no generic repository layer around Eloquent. OrderItemResource handles item serialization separately instead of nesting an anonymous mapping function in OrderResource. FormRequest rule arrays and model relationships follow Laravel conventions.

The refactor preserves the API paths, payloads, status codes, amounts, payment references, and idempotency fingerprint format. Existing payment keys remain compatible. See VALIDATION.md for regression checks.
