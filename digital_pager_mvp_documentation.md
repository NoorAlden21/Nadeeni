# Digital Pager — MVP Project Documentation

Revised 29 Oct 2026. This revision is the source of truth. It locks the data model, the QR token, auth, and live updates that the first draft left open.

**This repository is the Laravel API only.** The Next.js app (cashier, platform admin, customer pages, landing page) lives in a separate codebase. Laravel owns business rules, tenant isolation, orders, tokens, and push delivery. Next.js renders UI and calls this API. Do not duplicate those rules in Next.js.

## 1. Project overview

**Working name:** Digital Pager
**Product type:** Multi-tenant web application for restaurant order-ready notifications
**Initial market:** Syria
**Primary users:** Restaurant cashiers and customers at food courts, shopping malls, airports, festivals, and restaurant complexes.

### Problem

Restaurants often use physical pagers, or ask customers to wait near the counter and keep checking whether their order is ready. Physical pagers cost money, need charging and maintenance, and can be lost or damaged.

### Solution

A cashier enters the order number the restaurant already uses. The customer opens a link on their phone, either by scanning a QR code or by typing that number, and waits on a status page. When the food is ready, the cashier presses **Notify**. The open page updates on its own, and supported phones also get a Web Push notification.

Customers use the browser they already have. The MVP does not require an app install.

### Product principles

- Extremely simple for cashiers and customers.
- No customer accounts.
- No POS integration.
- No SMS, WhatsApp, email, or phone calls.
- Two ways to attach a phone to an order, one order model, one notify action.
- Straightforward Laravel app. No microservices, no Redis, no WebSockets in the MVP.

### Pilot language

Customer pages are Arabic, right-to-left. Cashier and platform-admin screens are Arabic as well. The API returns stable English error codes (and Laravel's normal validation payload). Next.js chooses the Arabic sentence. The landing page is owned separately and is out of this repo.

## 2. MVP scope

### Included

- Restaurant accounts and isolated restaurant data.
- Platform admin creates restaurants and cashier users. No public signup.
- Cashier login and dashboard.
- Cashier creates an order by typing the order number from the restaurant's existing process. The customer never creates an order.
- Two QR entry points, both available for every restaurant:
  1. **Shared QR:** one permanent QR per restaurant. The customer scans it, types their order number, and is attached to the order the cashier already created.
  2. **Per-order QR:** creating an order also creates a private link. The cashier shows that QR. The customer scans it and lands on that order. **Show QR** on an existing order draws the same link again. It does not create a new order.
- One customer status page for both entry points.
- One-click **Notify**. A second click does not send another push.
- A separate **Resend** action when a push failed or the customer missed it.
- Web Push where the browser actually supports it.
- Polling on the open customer page and the cashier list, so status changes without a manual refresh and without WebSockets.
- Order status tracking, expiry, and a minimal event log.

### Not included

- Native Android or iOS apps, and Apple App Clips.
- SMS, WhatsApp, email, or voice calls.
- POS integrations, payments, online ordering, menus, inventory, kitchen screens, loyalty, or table reservations.
- Billing and advanced analytics.
- Customer accounts and cashier self-registration.
- Password reset. An admin sets or replaces a cashier password out of band for the pilot.
- WebSockets, Laravel Reverb, Redis, and a queue worker. The queue driver stays `sync`.
- A "new QR / revoke token" action. Showing the existing QR again covers the glitched-scan case. Revoking a leaked QR can be added later.
- Spatie roles and permissions, and a restaurant-membership pivot. See the data model.

### Notification limits

Web Push can deliver while a supported browser is in the background or closed, subject to browser, permission, network, and OS settings. It cannot deliver while the phone is powered off.

On iOS, Web Push requires the site to be added to the Home Screen (iOS/iPadOS 16.4+) and notification permission. A normal Safari tab cannot be assumed to receive background pushes. The UI must say this plainly. The open status page, updated by polling, is the fallback.

Sending a push means the push service accepted the message. It does not mean the customer saw it.

## 3. Technology stack

### This repo

- Laravel 12 as the only place for business rules and data access.
- JSON API. Laravel validation, policies, Sanctum, migrations, and the scheduler.
- PostgreSQL.
- `minishlink/web-push` with VAPID keys.
- Queue driver: `sync`.

### The other repo

- Next.js with TypeScript.
- Tailwind CSS. shadcn/ui is optional.
- Mobile-first customer pages. Cashier and admin layouts sized for a tablet or desktop.
- A service worker that displays Web Push messages.
- Polling. No socket client in the MVP.

### Auth across the two origins

Next.js is a different origin, so cashier and admin sessions use **Sanctum personal access tokens** sent as `Authorization: Bearer`. Do not use cookie SPA auth for the pilot.

The server resolves the cashier's restaurant from `users.restaurant_id`. A `restaurant_id` sent by the browser is never trusted.

### Deployment

Provider is undecided. Production must be HTTPS, because service workers and push require a secure context. Secrets stay in environment variables.

### Later, only if a real need shows up

- Laravel Reverb, if polling the cashier board becomes a problem. It does not replace Web Push. A socket drops when the phone sleeps.
- Redis and a real queue, if push volume outgrows sending inside the request.
- POS integrations and other notification channels.

## 4. Main user journeys

The cashier creates the order first in both modes. If the customer arrives before that, registration returns the same generic failure as a wrong or expired number, and they can try again.

### Flow A — Shared restaurant QR

For restaurants that cannot show a unique QR on every order. A poster or counter card is enough.

1. The restaurant has a permanent QR whose target is `{FRONTEND_URL}/r/{slug}`.
2. The customer scans it and sees the restaurant name and an order-number field.
3. They enter the number and choose **Track my order**.
4. Laravel finds the single active order with that number at that restaurant and creates an access token.
5. Next.js moves to `{FRONTEND_URL}/o/{token}`. From here the page is the same as Flow B.
6. The customer can enable notifications where the browser allows it.
7. The cashier presses **Notify**.
8. The order becomes `ready`. Push goes out to subscriptions on that order. The open page picks up `ready` on its next poll.

### Flow B — QR per order

For restaurants that can show a code per order, for example on a spare screen.

1. The cashier enters the order number and presses **Create order**.
2. Laravel creates the order and one cashier access token in the same transaction.
3. The dashboard shows a QR for `{FRONTEND_URL}/o/{token}`.
4. The customer scans it and the status page loads. No typing.
5. Notifications work the same as Flow A.
6. **Notify** works the same as Flow A.

### Showing the QR again

The cashier creates order `47`, the QR is on screen, and the customer's scan fails. The cashier then creates order `48`, so `48` replaces the code on screen.

Order `47` is still in the list with the same token. **Show QR** on `47` draws that same link again. The customer scans it and lands on `47`. No second order is created, and the token is not rotated.

A failed scan usually never reaches the server, so there is nothing to undo. Scanning the same picture again is the fix.

Every order gets this cashier token at creation, including restaurants whose preferred mode is shared. Preferred mode only changes which control the dashboard emphasizes: the permanent restaurant QR, or the per-order QR. Both entry points work either way.

### Shared behavior

Both flows use the same order row, the same status transitions, the same notify service, and the same status page. The entry path only decides how the phone receives a token.

## 5. Order lifecycle

Statuses:

- `waiting` — created, being prepared. Customers may attach.
- `ready` — cashier has notified. Customers may still open the status page.
- `collected` — customer took the order.
- `cancelled` — voided.
- `expired` — the retention window passed.

Transitions:

- `waiting` → `ready` on **Notify**.
- `waiting` → `collected` when the customer already took the food and no notification is needed.
- `waiting` → `cancelled`.
- `ready` → `collected`.
- `ready` → `cancelled`.
- `waiting` or `ready` → `expired` by the scheduler, or by order creation when that number must be freed before insert.
- `collected`, `cancelled`, and `expired` are final.
- Any other transition returns HTTP 409 with `message` set to `invalid_status`.

**Notify** locks the order row. The first call from `waiting` sets `ready`, sets `ready_at`, writes `order_marked_ready`, and commits, then sends push. A second call sees `ready`, returns success, and does not push. The status change is kept even when every push fails. `notification_sent` is recorded only after a successful push attempt, not when the status changes. **Resend** is allowed only from `ready`, is logged as `notification_resent`, and is rate limited. It does not change status.

`expires_at` is set at creation from the restaurant's `order_ttl_minutes` (default 240). A scheduled command, every minute, marks due `waiting` and `ready` orders as `expired` and writes `expired_at`. That status change is what frees the order number. A partial unique index cannot depend on `now()`, so an order past `expires_at` still holds its number until the status becomes `expired`. Order creation does that itself, in the same transaction, before inserting a reuse of the number. The cashier does not wait for the scheduler.

Public registration attaches only to `waiting` or `ready` orders that have not passed `expires_at`. Unknown, collected, cancelled, and expired numbers all return the same error code, `order_unavailable`.

## 6. Data model

PostgreSQL. Timestamps in UTC (`timestamptz`). Display them in the restaurant timezone.

`restaurants` and `users` use bigint ids. `orders`, access tokens, push subscriptions, and events use UUIDs. Customer URLs use the restaurant slug or the access token, never a numeric id.

Store order status as a string plus a check constraint. Avoid a native PostgreSQL enum, because changing those later is painful.

### `restaurants`

- `id`
- `name`
- `slug` — unique, lowercase, URL-safe
- `preferred_qr_mode` — `shared` or `per_order`. UI default only.
- `timezone` — default `Asia/Damascus`
- `order_ttl_minutes` — default `240`
- `is_active` — default true. Inactive restaurants reject new orders and public registration.
- `created_at`, `updated_at`

### `users`

Laravel's users table, plus:

- `email` — unique. Login identifier for cashiers and the platform admin.
- `password`
- `is_platform_admin` — default false
- `restaurant_id` — nullable FK to `restaurants`. Null for a platform admin. Required for a cashier.
- `is_active` — default true. Inactive users cannot log in.
- `created_at`, `updated_at`

A person is one or the other for the pilot: platform admin with no restaurant, or cashier of exactly one restaurant. One login at two restaurants is out of scope, so there is no membership pivot and no Spatie permission tables. Add those only when a person actually works at two restaurants, or when an admin screen needs several editable permissions.

Deleting a restaurant that still has orders is blocked (`restrictOnDelete` from orders). `users.restaurant_id` uses `restrictOnDelete` as well.

No password-reset tokens in the pilot.

### `orders`

- `id` — UUID
- `restaurant_id` — FK, `restrictOnDelete`
- `order_number` — string, max 32. Trimmed, case-sensitive, leading zeros preserved. Not an integer.
- `status` — `waiting`, `ready`, `collected`, `cancelled`, `expired`
- `created_by_user_id` — FK, `nullOnDelete`
- `expires_at`
- `ready_at`, `collected_at`, `cancelled_at`, `expired_at` — nullable
- `created_at`, `updated_at`

There is no token column on this table.

Indexes:

- `(restaurant_id, status, created_at)`
- Partial unique index, so a restaurant has at most one live order per number:

```sql
CREATE UNIQUE INDEX orders_one_active_number_per_restaurant
ON orders (restaurant_id, order_number)
WHERE status IN ('waiting', 'ready');
```

The same number may exist again after the earlier order is `collected`, `cancelled`, or `expired`, and may exist at the same time in another restaurant. A duplicate create returns a validation error: an active order with this number already exists. The cashier opens that order and uses **Show QR** instead of creating another one.

Also index `expires_at` for rows in `waiting` or `ready`, for the scheduler.

### `order_access_tokens`

The token is a private ticket for one order, like a "anyone with the link" URL. The QR code is only a picture of `{FRONTEND_URL}/o/{token}`.

The order number is a poor secret: it is short, people nearby can hear it, and the restaurant will reuse it. The status page trusts the token, not the order number.

- `id` — UUID
- `order_id` — FK, `cascadeOnDelete`
- `token` — unique string. 32 random bytes, encoded base64url. Stored raw so **Show QR** can draw it after a refresh. This payload is "order 47 is ready," not a password. Hashing it would make the QR impossible to show again.
- `purpose` — `cashier_qr` or `shared_registration`
- `expires_at`
- `last_seen_at` — nullable
- `revoked_at` — nullable. Unused by the MVP UI. Reserved so a later "invalidate this QR" action has a place to land.
- `created_at`

Rules:

- Creating an order inserts exactly one `cashier_qr` token in the same transaction. **Show QR** always reads that row.
- Shared registration inserts a `shared_registration` token and returns it once. The browser then opens `/o/{token}`.
- At most 5 non-revoked tokens per order. Further shared registrations fail with `order_link_limit`.
- A token is usable while `revoked_at` is null, `expires_at` is in the future, and the order is `waiting` or `ready`.
- Status responses include only restaurant name, order number, and status.

### `push_subscriptions`

One row is one browser waiting to hear that an order is ready.

When the customer enables notifications, the browser registers with its push service (Google, Mozilla, or Apple) and returns a subscription. Laravel stores it. **Notify** and **Resend** load the rows for that order and send through `minishlink/web-push`. The push service delivers the encrypted body. Laravel does not talk to the phone directly.

A customer can use the status page without ever subscribing. Then there is a token and no subscription row, and polling is the only update path. That is a valid outcome, especially on iOS Safari tabs.

- `id` — UUID
- `order_id` — the order Notify queries. It is not a separate foreign key.
- `order_access_token_id` — the link the browser used when it subscribed. Required.
- Composite foreign key `(order_access_token_id, order_id)` → `order_access_tokens (id, order_id)`, `cascadeOnDelete`. A subscription cannot point at an order and a token from a different order. Deleting the token deletes the subscription. Deleting the order deletes its tokens, which deletes the subscriptions.
- `endpoint` — `text`, unique across all orders. One browser push endpoint tracks one order. Subscribing for another order updates this row and moves it. The previous order's open page still updates by polling. Uniqueness on `(order_id, endpoint)` would let one phone register for two orders, and a refreshed endpoint would leave a dead row behind on the first order.
- `p256dh` — the browser public key. The payload is encrypted with it so the push service cannot read the body.
- `auth` — the browser secret used with `p256dh`. Not a login token and not the QR token.
- `content_encoding` — default `aes128gcm`.
- `expires_at` — nullable. From the browser's `expirationTime`, when it sends one.
- `last_success_at` — nullable. The push service accepted a message. Not proof the person saw it.
- `last_error_at` — nullable.
- `failure_count` — default 0. Reset to 0 on success. Delete the row after repeated failures, and delete it immediately when the push service returns `410 Gone`.
- `created_at`, `updated_at`

At most 5 subscriptions per order. Store the keys as normal columns. Never return them from the API and never write them to logs. Application-level encryption can wait.

### `order_events`

- `id` — UUID
- `order_id` — FK, `cascadeOnDelete`
- `actor_user_id` — nullable FK, `nullOnDelete`. Null for customer registration and for the scheduler.
- `event_type` — `created`, `customer_linked`, `order_marked_ready`, `notification_sent`, `notification_failed`, `notification_resent`, `collected`, `cancelled`, `expired`
- `metadata` — `jsonb`, nullable. No tokens, endpoints, or keys.
- `created_at`

Index `(order_id, created_at)`.

### Framework tables

- Sanctum `personal_access_tokens`.
- No jobs table while the queue driver is `sync`.

## 7. API

Success JSON uses Laravel API resources. Errors use Laravel's normal JSON shape, `{ message, errors }`, with a stable machine-readable code in `message` where the client must branch (`order_unavailable`, `order_link_limit`, `invalid_status`, `already_notified` is not an error). Validation errors stay field-level.

### Cashier and platform admin

Bearer token required.

- `POST /api/auth/login` — email and password. Reject inactive users and cashiers whose restaurant is inactive.
- `POST /api/auth/logout` — revoke the current token.
- `GET /api/me` — user, `is_platform_admin`, and the restaurant when the user is a cashier (`name`, `slug`, `preferred_qr_mode`, `timezone`).

Platform admin:

- `GET /api/admin/restaurants`
- `POST /api/admin/restaurants`
- `PATCH /api/admin/restaurants/{restaurant}`
- `POST /api/admin/restaurants/{restaurant}/cashiers` — creates the user bound to that restaurant.

Cashier, always scoped to `users.restaurant_id`:

- `GET /api/orders?status=waiting` — default list is `waiting` and `ready`, newest first. `collected`, `cancelled`, and `expired` only when that status is requested. Those terminal rows are limited to orders updated within the restaurant's `order_ttl_minutes`. `waiting` and `ready` stay listed until they leave those statuses.
- `POST /api/orders` — body `{ order_number }`. Response includes the cashier token and `{FRONTEND_URL}/o/{token}` so the dashboard can render the QR.
- `GET /api/orders/{order}` — includes the same cashier QR payload. This is **Show QR**.
- `POST /api/orders/{order}/notify`
- `POST /api/orders/{order}/resend`
- `POST /api/orders/{order}/collect`
- `POST /api/orders/{order}/cancel`
- `GET /api/restaurant/shared-qr` — returns the public URL `{FRONTEND_URL}/r/{slug}`. Next.js draws and prints the image. Laravel does not generate a QR file.

Notify response after a duplicate click is HTTP 200 with the order and `already_notified: true`. No second push.

### Public

No bearer token. Responses contain no cashier data, no other orders, no subscription secrets, and no internal numeric ids.

- `GET /api/public/restaurants/{slug}` — `name` only, and only when the restaurant is active. Otherwise 404.
- `POST /api/public/restaurants/{slug}/register` — body `{ order_number }`. On a match, create a `shared_registration` token and return it once, with the status payload. On no match, `order_unavailable`.
- `GET /api/public/orders/{token}` — restaurant name, order number, status. Updates `last_seen_at`. This is the polling endpoint.
- `POST /api/public/orders/{token}/push-subscriptions` — browser subscription: `endpoint`, `keys.p256dh`, `keys.auth`, optional `content_encoding` and `expirationTime`. Upsert on `endpoint`.
- `DELETE /api/public/orders/{token}/push-subscriptions` — remove the subscription whose endpoint matches the current browser.

The VAPID public key lives in the Next.js env (`NEXT_PUBLIC_VAPID_PUBLIC_KEY`) and must equal Laravel's public key. The private key exists only on Laravel.

### Polling

Customer status page: `GET /api/public/orders/{token}` every 4 seconds until the status is `collected`, `cancelled`, or `expired`. Keep polling through `ready`, so a later collect or cancel still shows up.

Cashier list: the same 4 second interval. The tablet that just called Notify, Resend, Collect, or Cancel updates from that response immediately.

### Rate limits

Registration is the guessing surface. Limit it by IP (for example 10 per minute). Status polling is limited by token, and the limit must sit above 15 requests per minute so a 4 second poll is not blocked. A food-court NAT means many customers can share one public IP, so do not use a tight per-IP cap on the status endpoint. Login is 5 per minute per IP. Resend is 5 per minute per cashier per order. Subscription writes get their own limit.

### Notify and push

Inside a transaction: lock the order, require `waiting`, set `ready` and `ready_at`, write `order_marked_ready`, commit. After commit, send to each subscription. Record `notification_sent` or `notification_failed` per attempt. A `410` deletes that subscription. Other failures increment `failure_count` and set `last_error_at`.

Push payload stays small: restaurant name, order number, and a ready message. The click opens `{FRONTEND_URL}/o/{token}`. No secrets in the payload.

## 8. Security and privacy

- Tenant isolation lives in policies and queries. A cashier query always includes their `restaurant_id`.
- Never authorize from a client-supplied restaurant id.
- Customer routes authorize with the unguessable token, not with a sequential id or the order number.
- Tokens and orders expire.
- Public registration does not reveal whether a number exists.
- HTTPS in production.
- VAPID private key, database credentials, and `APP_KEY` stay in Laravel env. They never appear in a Next.js bundle. Only the VAPID public key is public.
- CORS allows the Next.js origin only.
- Subscription keys, raw tokens, and auth secrets are never logged and never returned on cashier list endpoints except the single cashier QR token on create and on `GET /api/orders/{order}`.
- Clean up subscriptions the push service reports as gone.
- Order events cover status changes and notification attempts.

## 9. Notification behavior

1. The status page is useful with JavaScript polling alone, before the customer allows notifications.
2. The client detects support. Unsupported or blocked permission gets an honest Arabic explanation, including the iOS Home Screen step. No fake lock-screen notification.
3. Where supported, the page asks for permission at a sensible moment, subscribes with the public VAPID key, and POSTs the subscription to Laravel.
4. **Notify** persists `ready` first, then sends. **Resend** sends again without changing status.
5. The service worker shows the notification. A click opens the status URL.
6. The cashier UI can say the request was sent. It must not say the customer received it.

## 10. UI requirements

These are for the Next.js repo. They are here so the API contract has a client in mind.

### Cashier dashboard

Built for a busy cashier on a tablet.

- Restaurant name.
- Create order: one number field, one primary button.
- When `preferred_qr_mode` is `per_order`, show the new order's QR immediately.
- When it is `shared`, emphasize viewing, printing, or downloading the permanent restaurant QR. Per-order **Show QR** remains available on each row.
- Active list: order number, created time, status, and large actions.
- Primary action: **Notify**. Disable it after the order leaves `waiting`, so a double tap is harmless on the server and obvious on the screen.
- **Resend** on `ready` orders.
- **Mark collected** and **Cancel**.
- **Show QR** reopens the existing cashier token for that row.
- Loading and error states, including "this number is already active."
- Filter: active (`waiting` and `ready`), plus the terminal statuses.
- Poll the list every 4 seconds.

### Customer page

Arabic, right-to-left, mobile-first, nothing else on the screen.

Shared page `/r/{slug}`:

- Restaurant name.
- Order number.
- Track button.
- `order_unavailable` shown as a single helpful sentence, the same one for a typo, an order the cashier has not entered yet, and an expired order.

Status page `/o/{token}`:

- Restaurant name, order number, and a clear state: being prepared, or ready for collection. Terminal states get a short final sentence.
- Notification prompt, plus a plain statement when notifications are unavailable or blocked, iOS included.
- Poll every 4 seconds. No refresh button required for correctness.

### Platform admin

- Create and edit a restaurant: name, slug, preferred QR mode, timezone, TTL minutes, active flag.
- Create a cashier on that restaurant: name, email, password.
- Cashiers do not get these screens.

### Landing page

A separate Next.js page. It is not served by this Laravel app and it does not call the order API. This repo does not own its layout.

## 11. Implementation sequence

This repo, in order. Do not generate the whole API in one pass.

### Phase 1 — Foundation

- Laravel project, PostgreSQL, Sanctum, CORS, `.env.example`.
- API error shape.
- Migrations and models for every table in section 6, including the partial unique index and FKs.

### Phase 2 — Auth and tenants

- Platform-admin seeder for the first admin.
- Login, logout, `/me`.
- Admin restaurant and cashier endpoints.
- Cashier queries scoped by `restaurant_id`.
- A test that restaurant A cannot read or notify restaurant B.

### Phase 3 — Orders

- Create, list, show (with cashier QR), collect, cancel.
- Notify idempotency and resend.
- Events.
- Expiry command and schedule.
- Tests for the partial unique index, illegal transitions, and duplicate notify.

### Phase 4 — Customer attachment

- Public restaurant lookup.
- Shared registration with the generic error and the token cap.
- Token status endpoint.
- Tests for expired, closed, cross-restaurant, and reused numbers.

### Phase 5 — Web Push

- VAPID env.
- Save, upsert, and delete subscriptions.
- Send on notify and resend, then failure counting and `410` deletion.
- Tests that an unauthorized cashier cannot notify, and that a second notify does not send.

The Next.js repo follows the same product order: cashier dashboard, customer pages, service worker, admin. Landing page is already in progress there. Prove push on a real Android phone over HTTPS, and write down the iOS Home Screen limit next to that result.

## 12. Acceptance criteria

Ready for a small pilot when:

- A platform admin can create a restaurant and a cashier.
- A cashier sees and manages only their restaurant's orders.
- A cashier can create an order from the restaurant's own order number, and can show that order's QR again after other orders have been created.
- The same active number cannot be created twice in one restaurant. The same number can exist in two restaurants, and can exist again after the first order is collected, cancelled, or expired.
- A shared-QR customer can attach to an eligible order without an account, then land on `/o/{token}`.
- A per-order QR opens exactly that order.
- Wrong, unknown, expired, and closed numbers return `order_unavailable`.
- **Notify** moves `waiting` to `ready`. A repeat click does not send a second push.
- **Resend** sends again without creating an order or changing status.
- The open customer page moves to ready on its own within one poll interval.
- A supported Android browser can subscribe and receive a test push. Unsupported and blocked cases are explained honestly, including iOS.
- Expired tokens and failed or gone subscriptions are handled without exposing other orders.
- Production is HTTPS.

## 13. Environment

Laravel:

- `APP_URL`
- `FRONTEND_URL` — origin used to build QR links, no trailing path
- `DB_*`
- `SANCTUM_STATEFUL_DOMAINS` is unused while auth is bearer tokens
- `FRONTEND_ORIGIN` — the single CORS origin
- `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` (`mailto:` or `https:` URL)

Next.js:

- API base URL
- `NEXT_PUBLIC_VAPID_PUBLIC_KEY` matching Laravel

Never commit real secrets. Local and production credentials stay different.

## 14. Implementation rules

- Keep the change small. Follow sections 6 and 7 instead of inventing a parallel model.
- Laravel is the source of truth for authorization, order matching, status transitions, and sending push.
- PostgreSQL migrations use explicit foreign keys and the partial unique index in section 6.
- Test tenant isolation, active order-number uniqueness, registration failures, status transitions, and notify idempotency.
- Do not add Firebase, SMS, WhatsApp, Redis, Reverb, microservices, POS, or Spatie unless a later change requests them.
- Do not claim push delivery is guaranteed.
- Document setup, env, migrations, and the scheduler in the repo README when the app exists.
