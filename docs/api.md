# Nadeeni API

Share this file with the frontend. It describes the routes that exist today. Web Push is not in this API yet.

## Base URL

Local:

```text
http://127.0.0.1:8000/api
```

Start it from the Laravel repo with `php artisan serve`.

Send JSON:

```http
Accept: application/json
Content-Type: application/json
```

The browser origin must be exactly `FRONTEND_ORIGIN` from the API `.env` (local default `http://localhost:3000`). The API does not use cookies. Send the token on the `Authorization` header.

## Auth

Login returns a token like `1|randomstring`. Store that whole string. Send it on later requests:

```http
Authorization: Bearer 1|randomstring
```

Logout revokes only the token used on that request.

### `POST /auth/login`

No token.

```json
{
  "email": "admin@nadeeni.test",
  "password": "the-password"
}
```

`email` and `password` are required. `email` must look like an email address.

`200`

```json
{
  "token": "1|randomstring",
  "user": {
    "id": 1,
    "name": "Platform Admin",
    "email": "admin@nadeeni.test",
    "is_platform_admin": true,
    "is_active": true,
    "restaurant": null
  }
}
```

A cashier has `is_platform_admin: false` and a `restaurant` object. See [User](#user).

| Status | `message` | When |
|---|---|---|
| 401 | `invalid_credentials` | Unknown email or wrong password |
| 403 | `inactive` | User is inactive, or the cashier's restaurant is inactive |
| 422 | validation | Missing or invalid email or password |
| 429 | `Too Many Attempts.` | More than 5 login attempts per minute from the same IP |

### `POST /auth/logout`

Requires the bearer token.

`200`

```json
{
  "message": "logged_out"
}
```

### `GET /me`

Requires the bearer token.

`200` is the [User](#user) object, with no wrapper.

## Platform admin

These four routes require a bearer token for a user with `is_platform_admin: true`. A cashier receives `403` and `{ "message": "forbidden" }`. A missing token receives `401` and `{ "message": "Unauthenticated." }`.

### `GET /admin/restaurants`

`200` is a JSON array, sorted by name. Each item is a [Restaurant](#restaurant).

```json
[
  {
    "id": 1,
    "name": "Shawarma House",
    "slug": "shawarma-house",
    "preferred_qr_mode": "shared",
    "timezone": "Asia/Damascus",
    "order_ttl_minutes": 240,
    "is_active": true
  }
]
```

### `POST /admin/restaurants`

```json
{
  "name": "Shawarma House",
  "slug": "Shawarma-House"
}
```

| Field | Rule |
|---|---|
| `name` | Required. String, max 255. |
| `slug` | Required. Lowercased by the API. Letters, numbers, and single hyphens. Must be unique. Example: `shawarma-house`. |
| `preferred_qr_mode` | Optional. `shared` or `per_order`. Default `shared`. |
| `timezone` | Optional. PHP timezone name, such as `Asia/Damascus`. Default `Asia/Damascus`. |
| `order_ttl_minutes` | Optional. Integer from 1 to 65535. Default `240`. |
| `is_active` | Optional. Boolean. Default `true`. |

`201` is the created [Restaurant](#restaurant).

### `PATCH /admin/restaurants/{id}`

Send only the fields that change. The same rules as create apply. `{id}` is the restaurant `id` from the list or create response.

`200` is the updated [Restaurant](#restaurant).

`404` means that id does not exist. Treat the status as not found. Do not parse `message`; it is a Laravel sentence.

### `POST /admin/restaurants/{id}/cashiers`

```json
{
  "name": "Hala",
  "email": "hala@shawarma.test",
  "password": "cashier-password"
}
```

| Field | Rule |
|---|---|
| `name` | Required. String, max 255. |
| `email` | Required. Email, max 255, unique across all users. |
| `password` | Required. String, 8 to 255 characters. |

The new user is a cashier of that restaurant, not a platform admin.

`201` is the created [User](#user). The password is not in the response.

## Cashier

These routes require a cashier bearer token. A platform admin receives `403` and `{ "message": "forbidden" }`. A cashier whose user or restaurant is inactive receives `403` and `{ "message": "inactive" }`. A missing token receives `401` and `{ "message": "Unauthenticated." }`.

The API uses the restaurant on the token. A `restaurant_id` in the body is ignored.

`{order}` is the order `id` from create or list. It is a UUID. An id from another restaurant, or an unknown id, is `404`. Treat the status as not found. Do not parse `message`.

`order_number` is a string, max 32 characters. The API trims spaces and keeps the rest as typed, including leading zeros and letter case. Send `"0047"`, not `47`. A JSON number is rejected.

### `GET /orders`

Default, with no query, is `waiting` and `ready`, newest first. Pass `status` to ask for one status: `waiting`, `ready`, `collected`, `cancelled`, or `expired`.

`collected`, `cancelled`, and `expired` include only orders updated within that restaurant's `order_ttl_minutes` (default 240). `waiting` and `ready` stay in the list until they leave those statuses.

`200` is a JSON array. Each item is an [Order](#order) without `cashier_qr`. The list never includes the QR token.

An unknown `status` is `422`.

### `POST /orders`

```json
{
  "order_number": "0047"
}
```

`201` is an [Order](#order) plus `cashier_qr`:

```json
{
  "id": "019a0000-0000-7000-8000-000000000001",
  "order_number": "0047",
  "status": "waiting",
  "expires_at": "2026-10-29T16:00:00.000000Z",
  "ready_at": null,
  "collected_at": null,
  "cancelled_at": null,
  "expired_at": null,
  "created_at": "2026-10-29T12:00:00.000000Z",
  "updated_at": "2026-10-29T12:00:00.000000Z",
  "cashier_qr": {
    "token": "43-character-token",
    "url": "http://localhost:3000/o/43-character-token"
  }
}
```

`url` is `{FRONTEND_URL}/o/{token}`. Draw the QR from `url`. Keep `token` if you need to rebuild that URL.

If this restaurant already has a `waiting` or `ready` order with that number, the response is `422` and `errors.order_number` is `An active order with this number already exists.` Open that order and use Show QR.

If the live order with that number is past `expires_at`, this call marks it `expired` and creates the new order. The new order has a new token.

The same number can be created at another restaurant.

### `GET /orders/{order}`

Show QR. `200` is the same shape as create, including `cashier_qr`. The token does not change between create and later reads.

### `POST /orders/{order}/notify`

No body.

The first call moves `waiting` to `ready` and sets `ready_at`. `200` is the [Order](#order) plus `"already_notified": false`. There is no `cashier_qr` on this response.

A second call, while the order is already `ready`, is also `200`. `already_notified` is `true`. `ready_at` does not change.

Web Push is not sent yet. `ready` means the cashier asked to notify. It does not mean the customer's phone received anything.

Any other status is `409` and `{ "message": "invalid_status" }`.

### `POST /orders/{order}/resend`

No body. Allowed only while `ready`. `200` is the [Order](#order). Status and `ready_at` stay the same. There is no `already_notified` field.

Other statuses are `409` and `{ "message": "invalid_status" }`.

More than 5 resends per minute for the same cashier and order returns `429` and `{ "message": "Too Many Attempts." }`.

Web Push is not sent yet.

### `POST /orders/{order}/collect`

No body. Allowed from `waiting` or `ready`. `200` is the [Order](#order) with `status` `collected` and `collected_at` set.

### `POST /orders/{order}/cancel`

No body. Allowed from `waiting` or `ready`. `200` is the [Order](#order) with `status` `cancelled` and `cancelled_at` set.

`collected`, `cancelled`, and `expired` are final. Notify, resend, collect, and cancel on those statuses return `409` and `{ "message": "invalid_status" }`.

### `GET /restaurant/shared-qr`

`200`

```json
{
  "url": "http://localhost:3000/r/shawarma-house"
}
```

`url` is `{FRONTEND_URL}/r/{slug}`. Draw or print the QR in the browser. This API does not return an image. `preferred_qr_mode` does not turn this route off. Per-order Show QR stays available either way.

## Public

No bearer token. These responses have no cashier data, no other orders, and no internal ids.

A missing or inactive restaurant is `404` and `{ "message": "Not found." }`. Treat that status as not found. Do not parse `message` to tell those two cases apart.

### `GET /public/restaurants/{slug}`

`200`

```json
{
  "name": "Shawarma House"
}
```

`{slug}` is the restaurant slug. Letter case in the URL does not matter.

### `POST /public/restaurants/{slug}/register`

```json
{
  "order_number": "0047"
}
```

Send `order_number` as a string. The API trims spaces and keeps leading zeros and letter case. A JSON number is rejected.

`201` when that restaurant has a `waiting` or `ready` order with that number, and `expires_at` is still in the future:

```json
{
  "token": "43-character-token",
  "url": "http://localhost:3000/o/43-character-token",
  "restaurant_name": "Shawarma House",
  "order_number": "0047",
  "status": "waiting"
}
```

Open `url`, or build `{your origin}/o/{token}`. The token is returned on this response only.

Unknown, not-yet-entered, expired, collected, cancelled, and another restaurant's number all return `404` and `{ "message": "order_unavailable" }`. Use that same sentence in the UI for every one of those cases.

An order can have 5 open links, including the cashier QR. The next registration returns `409` and `{ "message": "order_link_limit" }`.

More than 10 registrations per minute from the same IP returns `429` and `{ "message": "Too Many Attempts." }`.

A blank or too-long `order_number` is `422`. Read `errors.order_number`.

### `GET /public/orders/{token}`

`{token}` is the 43-character token from registration or from the cashier QR.

`200`

```json
{
  "restaurant_name": "Shawarma House",
  "order_number": "0047",
  "status": "waiting"
}
```

`status` stays available after the order is `collected`, `cancelled`, or `expired`, so the page can show the final state. Poll every 4 seconds until one of those.

An unknown or revoked token is `404` and `{ "message": "Not found." }`.

More than 30 requests per minute for the same token returns `429` and `{ "message": "Too Many Attempts." }`. The limit is per token, so customers on one shared network can poll together.

## Objects

### User

```json
{
  "id": 2,
  "name": "Hala",
  "email": "hala@shawarma.test",
  "is_platform_admin": false,
  "is_active": true,
  "restaurant": {
    "name": "Shawarma House",
    "slug": "shawarma-house",
    "preferred_qr_mode": "shared",
    "timezone": "Asia/Damascus"
  }
}
```

`restaurant` is `null` for a platform admin. `preferred_qr_mode` is `shared` or `per_order`. It only changes what the cashier screen should emphasize. Both QR flows will exist for every restaurant.

### Restaurant

```json
{
  "id": 1,
  "name": "Shawarma House",
  "slug": "shawarma-house",
  "preferred_qr_mode": "shared",
  "timezone": "Asia/Damascus",
  "order_ttl_minutes": 240,
  "is_active": true
}
```

## Errors

Validation failures are `422`:

```json
{
  "message": "The slug has already been taken.",
  "errors": {
    "slug": [
      "The slug has already been taken."
    ]
  }
}
```

Read `errors` for the field. `message` on a `422` is an English sentence, not a code. On `401`, `403`, and `429`, `message` is the code in the tables above, except Laravel's `Unauthenticated.` and `Too Many Attempts.` strings. On `404`, `message` is `Not found.` or `order_unavailable`. On `409`, `message` is `invalid_status` or `order_link_limit`.

## Order

```json
{
  "id": "019a0000-0000-7000-8000-000000000001",
  "order_number": "0047",
  "status": "waiting",
  "expires_at": "2026-10-29T16:00:00.000000Z",
  "ready_at": null,
  "collected_at": null,
  "cancelled_at": null,
  "expired_at": null,
  "created_at": "2026-10-29T12:00:00.000000Z",
  "updated_at": "2026-10-29T12:00:00.000000Z"
}
```

Timestamps are UTC. `status` is `waiting`, `ready`, `collected`, `cancelled`, or `expired`.

`cashier_qr` is present only on create and on `GET /orders/{order}`:

```json
{
  "token": "43-character-token",
  "url": "http://localhost:3000/o/43-character-token"
}
```

`already_notified` is present only on notify.

## Not built yet

Do not call these. They are the next API slices, not current routes.

- Web Push subscriptions, and sending a push on notify and resend
