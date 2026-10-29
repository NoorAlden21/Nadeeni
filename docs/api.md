# Nadeeni API

Share this file with the frontend. It describes the routes that exist today. Customer pages, order actions, and Web Push are not in this API yet.

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

Read `errors` for the field. `message` on a `422` is an English sentence, not a code. On `401`, `403`, and `429`, `message` is the code in the tables above, except Laravel's `Unauthenticated.` and `Too Many Attempts.` strings.

## Not built yet

Do not call these. They are the next API slices, not current routes.

- Cashier orders: create, list, show QR, notify, resend, collect, cancel
- Public customer routes: restaurant by slug, register by order number, order status, push subscriptions
