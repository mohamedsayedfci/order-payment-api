# API contract and examples

Base URL: `http://127.0.0.1:8000/api/v1`. Use JSON and `Authorization: Bearer <access_token>` for protected endpoints. JSON responses are also forced for API errors if the caller omits the Accept header.

## Register: POST /auth/register

```json
{"name":"Mohamed","email":"mohamed@example.com","password":"SecurePass123","password_confirmation":"SecurePass123"}
```

201:

```json
{"data":{"access_token":"<signed-jwt>","token_type":"Bearer","expires_in":3600,"user":{"id":1,"name":"Mohamed","email":"mohamed@example.com"}}}
```

Email is trimmed and lowercased. Password: at least 10 characters, both cases, a number, maximum 72 bytes and no null bytes, with matching confirmation. Duplicate email or invalid fields → 422. Password hashes are never serialized.

## Login: POST /auth/login

```json
{"email":"mohamed@example.com","password":"SecurePass123"}
```

200 has the same token envelope as registration. Invalid credentials → 401:

```json
{"message":"Invalid email or password."}
```

`GET /auth/me` → 200 `{"data":{"id":1,"name":"Mohamed","email":"mohamed@example.com"}}`.

## Create: POST /orders

```json
{"customer":{"name":"Customer","email":"customer@example.com"},"status":"pending","items":[{"product_name":"Keyboard","quantity":2,"price":"19.99"},{"product_name":"Cable","quantity":3,"price":"0.10"}]}
```

201 (IDs/timestamps below are illustrative):

```json
{
  "data": {
    "id": 1,
    "customer": {"name": "Customer", "email": "customer@example.com"},
    "status": "pending",
    "currency": "USD",
    "total": "40.28",
    "items": [
      {"id": 1, "product_name": "Keyboard", "quantity": 2, "price": "19.99", "line_total": "39.98"},
      {"id": 2, "product_name": "Cable", "quantity": 3, "price": "0.10", "line_total": "0.30"}
    ],
    "created_at": "2026-10-19T23:00:00.000000Z",
    "updated_at": "2026-10-19T23:00:00.000000Z"
  }
}
```

`status` defaults to pending; confirmed is also allowed at creation. Creating cancelled returns 409 `invalid_order_status`. Customer name/email and a non-empty item list are required. Limits and amount semantics are in README.

## Update/show/delete

`PATCH /orders/1` with `{"status":"confirmed"}` → 200, updated order envelope. Both PATCH and PUT apply supplied fields; omitted fields retain their values. A customer object replaces its name/email together, and an items array replaces every line and recalculates the total. There is no partial item endpoint.

`GET /orders/1` → 200, order envelope. Missing or foreign order → 404.

`DELETE /orders/1` → 204 with **no response body** if no payment attempts exist. Otherwise → 409 `order_has_payments`.

## Order listing

`GET /orders?status=confirmed&per_page=15&page=1` → 200:

```json
{
  "data": [],
  "links": {
    "first": "http://127.0.0.1:8000/api/v1/orders?status=confirmed&per_page=15&page=1",
    "last": "http://127.0.0.1:8000/api/v1/orders?status=confirmed&per_page=15&page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": null,
    "last_page": 1,
    "links": [
      {"url": null, "label": "&laquo; Previous", "page": null, "active": false},
      {"url": "http://127.0.0.1:8000/api/v1/orders?status=confirmed&per_page=15&page=1", "label": "1", "page": 1, "active": true},
      {"url": null, "label": "Next &raquo;", "page": null, "active": false}
    ],
    "path": "http://127.0.0.1:8000/api/v1/orders",
    "per_page": 15,
    "to": null,
    "total": 0
  }
}
```

Non-empty `data` contains order envelopes without another nested `data` wrapper. Results sort newest ID first. Laravel generates pagination links; consumers should follow `links` rather than depending on internal numbered-link labels.

## Process: POST /orders/1/payments

Required header: `Idempotency-Key: 123e4567-e89b-42d3-a456-426614174000` (UUID).

```json
{"method":"credit_card","simulation_result":"successful"}
```

201, `Idempotent-Replayed: false`:

```json
{
  "data": {
    "id": "019a0d00-0000-7000-8000-000000000001",
    "order_id": 1,
    "status": "successful",
    "method": "credit_card",
    "amount": "40.28",
    "currency": "USD",
    "gateway_reference": "sim_cc_019a0d00-0000-7000-8000-000000000001",
    "failure_code": null,
    "created_at": "2026-10-19T23:00:00.000000Z",
    "updated_at": "2026-10-19T23:00:00.000000Z"
  }
}
```

An identical repeat → 200, same ID/result, `Idempotent-Replayed: true`. The key is normalized to lowercase. Omitted `simulation_result` means successful, so explicit successful and omitted successful are equivalent. Same key plus changed order/method/outcome → 409 `idempotency_key_reused`.

For `simulation_result: failed`, the resource is still created (201), `status` becomes `failed`, and `failure_code` is `card_declined` or `paypal_declined`. PayPal references start with `sim_paypal_`. No arbitrary amount, card number or CVV is accepted.

## View payments

- `GET /payments/{uuid}` → 200, payment envelope.
- `GET /payments?status=failed&per_page=15&page=1` → 200, paginated payments.
- `GET /orders/1/payments` → 200, paginated attempts for that owned order.

Payment collections use the same `data`, `links`, `meta` pagination envelope as orders. They sort by created_at descending then UUID descending. The payment status filter accepts pending, successful or failed.

## Errors

| HTTP | Meaning | Example |
| --- | --- | --- |
| 401 | Missing, invalid, expired JWT / invalid credentials | `{"message":"Unauthenticated."}` |
| 404 | Record is absent or belongs to a different account | Laravel JSON not-found message |
| 409 | Domain conflict | See codes below |
| 422 | Validation error | Field-specific `errors` object |
| 429 | Rate limit exceeded | Laravel JSON message, Retry-After header |
| 500 | Unexpected infrastructure/configuration failure | Sanitized message when APP_DEBUG=false |

Example 422:

```json
{"message":"The items.0.quantity field must be at least 1.","errors":{"items.0.quantity":["The items.0.quantity field must be at least 1."]}}
```

Example 409:

```json
{"message":"Payments can only be processed for confirmed orders.","error_code":"order_not_confirmed"}
```

| Conflict code | Trigger |
| --- | --- |
| `invalid_order_status` | Creating a cancelled order |
| `invalid_status_transition` | Confirmed → pending |
| `order_cancelled` | Editing a cancelled order |
| `order_has_payments` | Delete / customer-items edit after any payment attempt |
| `order_payment_active` | Cancelling while pending or paid |
| `order_not_confirmed` | Processing for pending/cancelled order |
| `order_already_paid_or_processing` | New attempt after pending/successful payment |
| `idempotency_key_reused` | Same user's key with a different order/method/outcome |

Error messages are readable; clients should use HTTP status and `error_code` or field keys for programmatic handling. Unknown/disabled methods, missing/invalid idempotency headers and invalid list filters are 422 errors.
