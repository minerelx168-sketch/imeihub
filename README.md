# imeihub

Free IMEI Check & Phone Info Lookup — a web application that lets users identify any mobile phone by its IMEI number. Offers both free TAC-based lookups and premium paid checks via third-party IMEI APIs.

## Overview

imeihub is a full-stack IMEI checking service built with PHP and MySQL. It provides instant device identification for free lookups, and integrates with external IMEI API providers (DHRU / sickw.com / imei.info) for detailed premium reports including iCloud status, blacklist checks, carrier info, and more.

## Features

- **Free IMEI Check** — Brand, model, colour, storage and basic specs from any valid 15-digit IMEI
- **40+ Premium Services** — Apple iCloud, MDM, SIM-Lock, Warranty, Samsung Knox, Xiaomi Mi ID, Blacklist, and more
- **Multi-brand Support** — Apple, Samsung, Huawei, Xiaomi, OPPO, vivo, realme, OnePlus, Google Pixel, Motorola, Nokia, Sony
- **Credit System** — Users top up balance and pay per check, with a full transaction ledger
- **Multiple Payment Methods** — Stripe (card + PromptPay), PayPal, Binance Pay
- **Google OAuth** — Sign in with Google, plus email/password with OTP verification (Resend)
- **Chat Bots** — LINE and Telegram bot integrations for checks on the go
- **SEO Optimized** — Individual pages for each service, brand, and article + sitemap
- **Dark Theme UI** — Modern, responsive design with dark colour scheme
- **Rate Limiting** — Protection against abuse with configurable rate limits
- **Luhn Validation** — Client + server-side IMEI checksum validation before API calls

## Tech Stack

| Layer | Technology |
|-------|------------|
| Frontend | HTML5, CSS3 (custom dark theme), Vanilla JavaScript |
| Backend | PHP 8.x |
| Database | MySQL 8.x |
| Auth | Google OAuth 2.0, session-based email/password (OTP via Resend) |
| Payments | Stripe Checkout (card + PromptPay), PayPal, Binance Pay |
| IMEI API | DHRU / sickw.com / imei.info (configurable) |
| Dev environment | Docker Compose (PHP 8.4 + Apache, MySQL 8.4) |

## Getting Started

### Docker (recommended for local dev)

The fastest way to run the full stack locally:

```bash
cp .env.docker .env          # dev defaults; compose overrides DB settings
docker compose up --build    # first run builds the image + seeds the DB
```

Then open <http://localhost:8080>. A test user is seeded so paid lookups work
right away:

```
email     test@example.com
password  test123
credit    $100
```

`docker compose down` stops the stack (keeps data); `docker compose down -v`
also drops the database volume.

### Manual (PHP built-in server)

1. Import the schema and seed into MySQL:
   ```bash
   mysql -uroot -p imei_checker < sql/schema.sql
   mysql -uroot -p imei_checker < sql/seed.sql
   ```
2. Copy `.env.example` to `.env` and configure your credentials (see below).
3. Serve the project root:
   ```bash
   php -S 127.0.0.1:8080
   ```

## Provider configuration

Premium lookups call an external IMEI provider. Configure it in `.env`:

```env
IMEI_API_PROVIDER=sickw
IMEI_API_KEY=your-real-key
IMEI_API_URL=https://sickw.com/api.php
IMEI_API_DEFAULT_SERVICE=0
```

To plug in a different provider, extend `imei_provider_lookup()` and
`imei_provider_parse()` in `includes/imei_provider.php`. DHRU-style providers
that require place-and-poll for long-running services are supported (see
`sql/migrate-dhru-async.sql`).

## Services Offered

### Free
- Basic IMEI Check (brand, model, specs)

### Premium (Apple)
- Apple Basic Info, Carrier (Lite/Pro/Pro Plus), Max Info
- iCloud ON/OFF, Clean/Lost, ID Hint
- MDM ON/OFF, MDM + FMI
- Warranty, SIM-Lock, Part Number
- Case History, Repair History, Full GSX

### Premium (Other Brands)
- Samsung Info + Knox Guard Status
- Xiaomi Info + Mi Account Lock
- Huawei Info, Honor Info
- Google Pixel Info, Motorola Info, Lenovo Info
- WorldWide Blacklist (Simple/Full)
- T-Mobile / Verizon USA Status
- HLR Lookup, Number Type, Ping-SMS

## Pricing Model

Users purchase credits and spend them on checks. Free checks are unlimited.

## Environment Variables

```env
APP_MODE=production
APP_URL=https://yourdomain.com
DB_HOST=localhost
DB_NAME=imei_checker
DB_USER=root
DB_PASS=
IMEI_API_KEY=your_api_key
IMEI_API_URL=https://api.provider.com
GOOGLE_CLIENT_ID=your_google_client_id
GOOGLE_CLIENT_SECRET=your_google_client_secret
STRIPE_SECRET_KEY=sk_live_xxx
STRIPE_PUBLISHABLE_KEY=pk_live_xxx
```

## Operations

### Integration tests

`scripts/run-tests.php` exercises the financial invariants:

```bash
php scripts/run-tests.php
```

Covers: signup → balance 0, top-up happy path, webhook replay safety
(no double credit), deduct happy path, insufficient-balance rejection,
provider-failure auto-refund (with idempotent re-call), concurrent
deduct (10 forked workers on a 100 $ wallet). Every test re-checks the
`users.cached_balance == SUM(credit_transactions)` invariant.

Exit code: 0 = all pass, 1 = any failure.

### Balance reconciliation

`scripts/reconcile-balance.php` detects drift between
`users.cached_balance` and the ledger. Run nightly:

```bash
# report only
php scripts/reconcile-balance.php

# repair drift (writes cached_balance from SUM(ledger))
php scripts/reconcile-balance.php --fix
```

If `--fix` ever has work to do, that points at a code bug in
`includes/credits_write.php`, not a finance problem — the ledger is
always source of truth.

## Branches

| Branch | Purpose |
|--------|----------|
| `main` | Project documentation and overview |
| `gh-pages` | Static HTML demo (deployed to GitHub Pages) |
| `claude/*` | Development branches with the PHP backend and advanced features |

## License

Private project. All rights reserved.
