# CrickPro Auction API

Laravel 13 / PHP 8.4 / MySQL 8 / Redis backend for the CrickPro Auction product line — a **fresh build**, deliberately independent of the existing Node/Express `crickpro-auction-api` at `/auction/crickpro-auction-api` (which keeps running untouched as a separate, parallel product for `crickpro-auction-ui`'s dashboard).

Meant to be the shared backend for two new clients, not yet built:
- `crickpro-auction` — Ionic/React web app
- `crickpro-auction-app` — React Native (Expo) app

**Phase A scope (current)**: authentication only. Registration (email or mobile), login (password), email OTP, WhatsApp OTP (Meta Cloud API), forgot-password (both channels), Sanctum tokens with single-session-per-client revocation. No auction domain (teams/players/bidding) yet — see the project plan for the full phased build order.

## Stack

- Laravel 13, PHP 8.4 (Docker) — `composer.json` targets `^8.3` but the Docker image and current Symfony/Laravel dependency chain require PHP 8.4 to actually boot; there is no local-PHP-8.3 fallback for running `artisan`/tests, only Docker.
- MySQL 8, Redis 7 (session/cache — per the original product spec's `SESSION_DRIVER=redis`/`CACHE_STORE=redis`)
- Sanctum (bearer tokens, 30-day cap, no refresh-token rotation — see Auth below)
- Repository/Resource pattern per this project's dev-guide conventions (`app/Repositories`, `app/Http/Resources`)

## Setup

```bash
cp .env.example .env
docker compose build
docker compose up -d
docker compose exec laravel.test composer install
docker compose exec laravel.test php artisan migrate
```

App: `http://localhost:16080` · MySQL: `16081` · phpMyAdmin: `16082` · Redis: `16083` — the next free 16XXX band after every other CrickPro service in `dev-guide/ports.md` (which stops at 16071). Deliberately **not** 16041/16042 — the Node `crickpro-auction-api`'s docker-compose claims those despite them already belonging to `crickpro-lite-api`; don't repeat that collision.

Run tests (in-memory sqlite, no Docker DB needed):

```bash
docker compose exec laravel.test php artisan test
```

## Auth endpoints (`/v1/auth/*`)

| Method | Path | Notes |
|---|---|---|
| POST | `check-user` | `{email}` → `{userExists, hasPassword}` |
| POST | `check-user-mobile` | `{mobile, phoneCode}` |
| POST | `send-email-otp` | `{email}` |
| POST | `verify-email-otp` | `{email, otp}` → logs in if user exists, else `userExists:false` |
| POST | `send-whatsapp-otp` | `{mobile, phoneCode}` — no-ops (logs the code) until `WHATSAPP_API_TOKEN`/`WHATSAPP_PHONE_NUMBER_ID` are set |
| POST | `verify-whatsapp-otp` | `{mobile, phoneCode, otp}` — registration phone verification, consumes the code |
| POST | `login-password` | `{email, password}` |
| POST | `login-password-mobile` | `{mobile, phoneCode, password}` |
| POST | `register` | `{name, email? or mobile?+phoneCode, password, countryCode, termsAccepted, profileImage?}` |
| POST | `verify-reset-otp-mobile` | pre-check, does not consume the code |
| POST | `reset-password-mobile` | `{mobile, phoneCode, otp, password}` — consumes the code, auto-logs in |
| POST | `reset-password` | `{email, otp, password, password_confirmation}` — consumes the code, auto-logs in |
| GET | `user` | auth required |
| POST | `logout` | auth required |

In `local`/`testing` env, OTP-sending endpoints echo `debugOtp` in the response body so you can complete flows without real email/WhatsApp delivery.

## Auth design notes (deviations from the original product prompt / from crickpro-api, and why)

- **Not phone-only.** The original scaffolding prompt specified phone-only auth for the RN app; this build supports both email and mobile as primary identifiers, matching crickpro-api's actual richer auth surface (per user decision — "full crickpro-api-style auth").
- **No city/state fields, no geo tables.** crickpro-api's geo schema is legacy sprawl across 6+ overlapping tables; registration here only takes `countryCode` (accepted, not currently persisted — for future use). If city capture is needed later, add it as its own task rather than reviving that sprawl.
- **No Facebook OAuth.** crickpro-api's Facebook controller only proxies token exchange — it doesn't create/link a CrickPro user. Skipped for this pass per user decision; revisit as its own scoped task if needed.
- **Single OTP store.** crickpro-api splits OTP storage across a `password_reset_codes` DB table *and* a parallel Cache-based path for email OTP (its own investigation flagged this as inconsistent). Consolidated here to the DB table only.
- **`REVOKABLE_CLIENTS`/`NO_REVOKE_USER_IDS` are config/env-driven** (`config/auction.php`, `AUTH_REVOKABLE_CLIENTS`/`AUTH_NO_REVOKE_USER_IDS`), not hardcoded class constants like crickpro-api — tunable without a deploy.
- **Real unique indexes on `email`/`mobile`.** crickpro-api's `users` table has neither — flagged by its own investigation as a gap, fixed here.
- **Sanctum, not JWT.** Tokens are capped at 30 days (`now()->addMonth()`), no refresh/rotation endpoint — client re-authenticates on expiry, same pattern as crickpro-api. This is *not* the refresh-token-rotation design the Node `crickpro-auction-api` uses; the two are unrelated backends with different auth mechanisms.
- **Single-session-per-client**: logging in again from the same `X-CrickPro-Client` value revokes that client's prior token(s) for the user. Default revokable clients: `crickpro-auction`, `crickpro-auction-app`.

## Known gotcha (documented so it isn't rediscovered)

Sanctum's default `PersonalAccessToken` model's `$fillable` doesn't include our custom `client_identifier` column — mass-assigning it via `tokens()->create([...])` silently drops it. `User::createPersonalAccessToken()` builds the token via `tokens()->make([...])` (fillable fields only) then sets `client_identifier` as a direct property assignment before `save()`, which bypasses the guard correctly. If you ever "simplify" that method back to a single `create()` call, single-session-per-client will silently stop working — nothing will error, tokens just won't carry `client_identifier` anymore.

Also: this is a pure JSON API with no `login` route. `bootstrap/app.php` explicitly sets `$middleware->redirectGuestsTo(fn () => null)` — without it, an unauthenticated request that doesn't send `Accept: application/json` 500s (`RouteNotFoundException: Route [login] not defined`) instead of returning 401, because Laravel's default `Authenticate` middleware tries to redirect guests to a `login` named route that doesn't exist here.
