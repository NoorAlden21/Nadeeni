# Nadeeni API

This file is the contract for the Next.js app. Implement only the routes written here.

The service worker that shows the notification lives in the Next.js repo. This API sends the push. It does not serve the customer page.

## How to call it

Deployed base URL:

```text
https://nadeeni-api.onrender.com/api
```

Every path in this file is relative to that base. `POST /auth/login` means:

```http
POST https://nadeeni-api.onrender.com/api/auth/login
```

Do not add a second `/api`. Do not drop `/api`.

On a machine running the API locally, the same paths use `http://127.0.0.1:8000/api` after `php artisan serve`.

Send JSON on requests that have a body:

```http
Accept: application/json
Content-Type: application/json
```

Responses are the JSON shown below. They are not wrapped in `{ "data": ... }`. A list is a JSON array.

The browser origin must be exactly `FRONTEND_ORIGIN` from the API `.env`. The local default is `http://localhost:3000`. The API does not use cookies.

Next.js env:

```text
NEXT_PUBLIC_API_URL=https://nadeeni-api.onrender.com/api
NEXT_PUBLIC_VAPID_PUBLIC_KEY=
```

For a local API, set `NEXT_PUBLIC_API_URL` to `http://127.0.0.1:8000/api` instead.

`NEXT_PUBLIC_VAPID_PUBLIC_KEY` must be the same string as `VAPID_PUBLIC_KEY` on the API. The API does not return that key. The private key stays on the API.

## Accounts

There is no public signup and no password reset. Do not build those screens, and do not call `/register`, `/signup`, or `/forgot-password`.

Three people log in, all through `POST /auth/login`:

| Person | How the account is created | After login |
|---|---|---|
| Platform admin | Seeded on the API. Not created from the app. | Restaurant and staff screens |
| Cashier | Platform admin calls `POST /admin/restaurants/{id}/cashiers` | Order screen |
| Owner | Platform admin calls `POST /admin/restaurants/{id}/owners` | Stats screen |

A restaurant is not a login. Create the restaurant first, then create a cashier and an owner for it. Creating a user does not return a token. That person logs in with the email and password the admin set. The admin can list those accounts, change the name, email, password, role, or active flag, and delete an account. There is still no self-service password reset.

A customer never has an account. `POST /public/restaurants/{slug}/register` attaches an order number to a status link. It does not create a user.

## Where each screen goes

Read `user` from the login response. Do not ask the person to pick a role.

| `user` | Next.js screen | Calls |
|---|---|---|
| `is_platform_admin: true` | Admin | `/admin/restaurants` |
| `restaurant_role: "cashier"` | Cashier | `/orders`, `/restaurant/shared-qr` |
| `restaurant_role: "owner"` | Owner | `/restaurant/stats` |
| no token | Customer `/r/{slug}` and `/o/{token}` | `/public/...` |

`POST /auth/login`, `POST /auth/logout`, and `GET /me` are shared by the admin, the cashier, and the owner.

Store the login `token` and send it on those screens:

```http
Authorization: Bearer 1|randomstring
```

Send the whole string, including the number and the `|`. A later `401` with `{ "message": "Unauthenticated." }` means that token is missing or dead. Clear it and show login.

Logout revokes only the token used on that request.

## Auth

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
    "restaurant_role": null,
    "is_active": true,
    "restaurant": null
  }
}
```

A cashier or owner has `is_platform_admin: false`, `restaurant_role` set, and a `restaurant` object. Same response shape, for a cashier:

```json
{
  "token": "2|randomstring",
  "user": {
    "id": 2,
    "name": "Hala",
    "email": "hala@shawarma.test",
    "is_platform_admin": false,
    "restaurant_role": "cashier",
    "is_active": true,
    "restaurant": {
      "name": "Shawarma House",
      "slug": "shawarma-house",
      "preferred_qr_mode": "shared",
      "timezone": "Asia/Damascus"
    }
  }
}
```

An owner is the same with `"restaurant_role": "owner"`. The full field list is [User](#user). There is no password in this response.

| Status | `message` | When |
|---|---|---|
| 401 | `invalid_credentials` | Unknown email or wrong password |
| 403 | `inactive` | User is inactive, or their restaurant is inactive |
| 422 | validation | Missing or invalid email or password |
| 429 | `Too Many Attempts.` | More than 5 login attempts per minute from the same IP |

### `POST /auth/logout`

Requires the bearer token. A missing token is `401` and `{ "message": "Unauthenticated." }`.

`200`

```json
{
  "message": "logged_out"
}
```

### `GET /me`

Requires the bearer token. A missing token is `401` and `{ "message": "Unauthenticated." }`.

`200` is the [User](#user) object, with no wrapper. On a fresh page load, call this with the stored token before showing a staff screen. Use the same role fields as login. `restaurant` is `null` for the platform admin.

## Platform admin

These routes require a bearer token for a user with `is_platform_admin: true`. A cashier or an owner receives `403` and `{ "message": "forbidden" }`. A missing token receives `401` and `{ "message": "Unauthenticated." }`.

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

`201` is the created [User](#user). The password is not in the response. `restaurant_role` is `cashier`. The response has no `token`. The cashier signs in with `POST /auth/login`.

### `POST /admin/restaurants/{id}/owners`

Same body and validation as creating a cashier.

The new user is an owner of that restaurant. `restaurant_role` is `owner`. An owner can read stats and cannot manage orders.

```json
{
  "name": "Nabil",
  "email": "nabil@shawarma.test",
  "password": "owner-password"
}
```

`201` is the created [User](#user). The password is not in the response. The response has no `token`. The owner signs in with `POST /auth/login`.

### `GET /admin/restaurants/{id}/accounts`

`200` is a JSON array of the cashiers and owners of that restaurant, sorted by name. Each item is a [User](#user). Platform admins are not in this list. Another restaurant's accounts are not in this list.

`404` means that restaurant id does not exist.

### `GET /admin/accounts/{id}`

`{id}` is the user `id` from the account list or from create.

`200` is that [User](#user).

`404` means that id does not exist, or it is a platform admin. This route does not manage the platform admin.

### `PATCH /admin/accounts/{id}`

Send only the fields that change.

| Field | Rule |
|---|---|
| `name` | String, max 255. |
| `email` | Email, max 255, unique across all users. |
| `password` | String, 8 to 255 characters. This replaces the password. The old password stops working. |
| `restaurant_role` | `cashier` or `owner`. |
| `is_active` | Boolean. `false` turns the account off. Login then returns `403` and `{ "message": "inactive" }`. |

`200` is the updated [User](#user). The password is not in the response.

A new password, or `is_active: false`, deletes that account's bearer tokens. The next call with an old token is `401` and `{ "message": "Unauthenticated." }`.

`404` is the same as show. `422` is a validation error, including an email that another user already has.

### `DELETE /admin/accounts/{id}`

Deletes that cashier or owner. Orders they created stay. The order's creator becomes empty.

`200`

```json
{
  "message": "deleted"
}
```

Their bearer tokens stop working. `404` is the same as show. A platform admin cannot be deleted here.

## Cashier

These routes require a cashier bearer token. A platform admin or an owner receives `403` and `{ "message": "forbidden" }`. A cashier whose user or restaurant is inactive receives `403` and `{ "message": "inactive" }`. A missing token receives `401` and `{ "message": "Unauthenticated." }`.

The API uses the restaurant on the token. A `restaurant_id` in the body is ignored.

`{order}` is the order `id` from create or list. It is a UUID. An id from another restaurant, or an unknown id, is `404`. Treat the status as not found. Do not parse `message`.

`order_number` is a string, max 32 characters. The API trims spaces and keeps the rest as typed, including leading zeros and letter case. Send `"0047"`, not `47`. A JSON number is rejected.

### `GET /orders`

Default, with no query, is `waiting` and `ready`, newest first. Pass `status` to ask for one status: `waiting`, `ready`, `collected`, `cancelled`, or `expired`.

`collected`, `cancelled`, and `expired` include only orders updated within that restaurant's `order_ttl_minutes` (default 240). `waiting` and `ready` stay in the list until they leave those statuses.

`200` is a JSON array. Each item is an [Order](#order) without `cashier_qr`. The list never includes the QR token.

Poll this every 4 seconds. After a number correction, Notify, Resend, Collect, or Cancel, update the row from that response immediately. The next poll will match it.

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

### `PATCH /orders/{order}`

Correct a mistyped number. The order and its QR stay the same. The customer page shows the new number on the next poll.

```json
{
  "order_number": "0048"
}
```

`order_number` follows the same rules as create. Allowed while the order is `waiting` or `ready`, including after Notify.

`200` is the [Order](#order) with the new `order_number`. This response has no `cashier_qr`. Read the existing QR from create or from `GET /orders/{order}`.

Sending the number the order already has is `200` and changes nothing.

If another `waiting` or `ready` order at this restaurant already has that number, the response is `422` and `errors.order_number` is `An active order with this number already exists.`

`collected`, `cancelled`, and `expired` return `409` and `{ "message": "invalid_status" }`.

### `POST /orders/{order}/notify`

No body.

The first call moves `waiting` to `ready` and sets `ready_at`. `200` is the [Order](#order) plus `"already_notified": false`. There is no `cashier_qr` on this response.

A second call, while the order is already `ready`, is also `200`. `already_notified` is `true`. `ready_at` does not change.

The first call also asks the push service to deliver to this order's subscriptions. `ready` means the cashier asked to notify. It does not mean the customer's phone received anything. Say the request was sent. Do not say the customer got it.

Any other status is `409` and `{ "message": "invalid_status" }`.

### `POST /orders/{order}/resend`

No body. Allowed only while `ready`. `200` is the [Order](#order). Status and `ready_at` stay the same. There is no `already_notified` field.

Other statuses are `409` and `{ "message": "invalid_status" }`.

More than 5 resends per minute for the same cashier and order returns `429` and `{ "message": "Too Many Attempts." }`.

Resend asks the push service to deliver again. It does not mean the customer's phone received anything.

### `POST /orders/{order}/collect`

No body. Allowed from `waiting` or `ready`. `200` is the [Order](#order) with `status` `collected` and `collected_at` set.

### `POST /orders/{order}/cancel`

No body. This is the customer cancelling, not a way to fix a typo. Correct a mistyped number with `PATCH /orders/{order}`.

Allowed from `waiting` or `ready`. `200` is the [Order](#order) with `status` `cancelled` and `cancelled_at` set. `ready_at` stays as it was. The number can be used on a new order after this.

```json
{
  "id": "019a0000-0000-7000-8000-000000000001",
  "order_number": "0047",
  "status": "cancelled",
  "expires_at": "2026-10-29T16:00:00.000000Z",
  "ready_at": null,
  "collected_at": null,
  "cancelled_at": "2026-10-29T12:10:00.000000Z",
  "expired_at": null,
  "created_at": "2026-10-29T12:00:00.000000Z",
  "updated_at": "2026-10-29T12:10:00.000000Z"
}
```

`collected`, `cancelled`, and `expired` are final. Notify, resend, collect, cancel, and a number change on those statuses return `409` and `{ "message": "invalid_status" }`.

### `GET /restaurant/shared-qr`

`200`

```json
{
  "url": "http://localhost:3000/r/shawarma-house"
}
```

`url` is `{FRONTEND_URL}/r/{slug}`. Draw or print the QR in the browser. This API does not return an image. `preferred_qr_mode` does not turn this route off. Per-order Show QR stays available either way.

## Restaurant owner

These routes require an owner bearer token. A cashier or a platform admin receives `403` and `{ "message": "forbidden" }`. An inactive owner or restaurant receives `403` and `{ "message": "inactive" }`.

The API uses the restaurant on the token.

### `GET /restaurant/stats?from=2026-10-28&to=2026-10-30`

`from` and `to` are required `YYYY-MM-DD` dates. `to` is on or after `from`. The range is at most 90 days. Dates are interpreted in the restaurant timezone.

`200`

```json
{
  "from": "2026-10-29",
  "to": "2026-10-29",
  "timezone": "Asia/Damascus",
  "orders": 4,
  "ready_orders": 3,
  "cancelled_orders": 1,
  "average_ready_seconds": 400,
  "orders_without_browser": 1,
  "new_browsers": 2,
  "returning_browsers": 1,
  "days": [
    {
      "date": "2026-10-29",
      "orders": 4,
      "ready_orders": 3,
      "cancelled_orders": 1,
      "average_ready_seconds": 400,
      "orders_without_browser": 1,
      "new_browsers": 2,
      "returning_browsers": 1
    }
  ]
}
```

`orders` counts orders created in the window. `ready_orders` is how many of those have `ready_at`. `cancelled_orders` is how many of those now have status `cancelled`. `average_ready_seconds` is the average of `ready_at - created_at` for the ready subset, or `null` when none became ready. `days` includes every date from `from` through `to`, including dates with zero orders. On those dates `average_ready_seconds` is `null`.

`new_browsers` and `returning_browsers` are distinct browsers that opened a page in the window. A browser is new when its first order at this restaurant is inside the window. The same browser is returning when it already had an earlier order there. `orders_without_browser` never sent an id, so leave those out of the new/returning percentage. Daily browser numbers use that rule for each day, so the days will not add up to the range totals.

A missing date, a reversed range, or a range longer than 90 days is `422`.

## Public

No bearer token. Do not send `Authorization`. These responses have no cashier data, no other orders, and no internal ids.

Customer routes:

| Page | Calls |
|---|---|
| `/r/{slug}` from the shared restaurant QR | `GET /public/restaurants/{slug}`, then `POST /public/restaurants/{slug}/register`, then open `/o/{token}` |
| `/o/{token}` from a per-order QR or from register | `GET /public/orders/{token}` every 4 seconds, and the push-subscription routes after the customer allows notifications |

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

This does not create an account. The customer stays anonymous. It attaches the typed order number to a new status link.

```json
{
  "order_number": "0047",
  "visitor_id": "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"
}
```

Send `order_number` as a string. The API trims spaces and keeps leading zeros and letter case. A JSON number is rejected.

`visitor_id` is optional. When the customer page has one, send the UUID it stored in `localStorage`. The first id on an order is kept. A later id does not replace it. The response does not include it. An invalid UUID is `422`.

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

Send the stored browser id on this request too, including while polling:

```http
X-Visitor-Id: aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa
```

A valid id is saved when the order does not have one yet. An invalid header is ignored, and the status response stays `200`. The JSON does not include the id.

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

### `POST /public/orders/{token}/push-subscriptions`

No bearer token. `{token}` is the status-page token. The order must still be `waiting` or `ready`, and the token must not be revoked or past `expires_at`. Otherwise `404` and `{ "message": "Not found." }`.

Send the browser subscription. `expirationTime` is the browser value: milliseconds since Unix epoch, or `null`.

```json
{
  "endpoint": "https://fcm.googleapis.com/fcm/send/example",
  "expirationTime": null,
  "keys": {
    "p256dh": "browser-public-key",
    "auth": "browser-auth-secret"
  }
}
```

`content_encoding` is optional. `aes128gcm` or `aesgcm`. The default is `aes128gcm`.

`201` the first time that endpoint is saved. `200` when the same endpoint is saved again. Both bodies are:

```json
{
  "subscribed": true
}
```

The response never includes `endpoint`, `keys`, or the token. One browser endpoint tracks one order. Saving it again for another order moves that row. The previous order keeps updating by polling.

An order accepts 5 subscriptions. The next new endpoint is `409` and `{ "message": "subscription_limit" }`. Updating an endpoint that is already on that order does not count as a new one.

More than 10 subscription writes per minute for the same token returns `429` and `{ "message": "Too Many Attempts." }`.

### `DELETE /public/orders/{token}/push-subscriptions`

```json
{
  "endpoint": "https://fcm.googleapis.com/fcm/send/example"
}
```

`200`

```json
{
  "message": "deleted"
}
```

The endpoint must belong to this token. Anything else is `404` and `{ "message": "Not found." }`.

### Notification payload

Notify and resend send this JSON to the browser. The service worker shows it. `message` is Arabic. A click opens `url`.

```json
{
  "restaurant_name": "Shawarma House",
  "order_number": "0047",
  "message": "طلب 0047 جاهز",
  "url": "http://localhost:3000/o/43-character-token"
}
```

There is no subscription key and no VAPID private key in this body. The VAPID public key is `NEXT_PUBLIC_VAPID_PUBLIC_KEY` in the Next.js env. It must equal `VAPID_PUBLIC_KEY` on the API. The private key stays on the API.

The customer page creates the browser id with `crypto.randomUUID()`, stores it in `localStorage`, and sends that same value on later visits. Closing the tab keeps it. Clearing the site data, a private window, or another browser starts a new id.

Show the notification from `restaurant_name` and `message`. A click opens `url`. On iOS, lock-screen delivery needs the site added to the Home Screen on iOS 16.4 or newer, then opened from that icon before the customer allows notifications. A normal Safari tab will not show that alert. The status page still updates by polling. The cashier screen says the request was sent. It does not say the customer received it.

## Objects

### User

```json
{
  "id": 2,
  "name": "Hala",
  "email": "hala@shawarma.test",
  "is_platform_admin": false,
  "restaurant_role": "cashier",
  "is_active": true,
  "restaurant": {
    "name": "Shawarma House",
    "slug": "shawarma-house",
    "preferred_qr_mode": "shared",
    "timezone": "Asia/Damascus"
  }
}
```

`restaurant` is `null` for a platform admin, and `restaurant_role` is `null` too. A restaurant user has `restaurant_role` `cashier` or `owner`. `preferred_qr_mode` is `shared` or `per_order`. It only changes what the cashier screen should emphasize. Both QR flows will exist for every restaurant.

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

Read `errors` for the field. `message` on a `422` is an English sentence, not a code. On `401`, `403`, and `429`, `message` is the code in the tables above, except Laravel's `Unauthenticated.` and `Too Many Attempts.` strings. On `404`, `message` is `Not found.` or `order_unavailable`. On `409`, `message` is `invalid_status`, `order_link_limit`, or `subscription_limit`.

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
